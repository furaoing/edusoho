<?php
namespace WebBundle\Controller;

use Topxia\Common\Paginator;
use Topxia\Common\ArrayToolkit;
use Topxia\Service\Common\ServiceKernel;
use Symfony\Component\HttpFoundation\Request;
use Topxia\Service\Question\Type\QuestionTypeFactory;

class TestpaperManageController extends BaseController
{
    public function indexAction(Request $request, $courseId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $conditions = array(
            'courseId' => $courseId,
            'type'     => 'testpaper'
        );

        $paginator = new Paginator(
            $this->get('request'),
            $this->getTestpaperService()->searchTestpaperCount($conditions),
            10
        );

        $testpapers = $this->getTestpaperService()->searchTestpapers(
            $conditions,
            array('createdTime' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $userIds = ArrayToolkit::column($testpapers, 'updatedUserId');
        $users   = $this->getUserService()->findUsersByIds($userIds);

        return $this->render('WebBundle:TestpaperManage:index.html.twig', array(
            'course'     => $course,
            'testpapers' => $testpapers,
            'users'      => $users,
            'paginator'  => $paginator

        ));
    }

    public function createAction(Request $request, $courseId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        if ($request->getMethod() == 'POST') {
            $fields             = $request->request->all();
            $fields['ranges']   = empty($fields['ranges']) ? array() : explode(',', $fields['ranges']);
            $fields['courseId'] = $courseId;
            $fields['pattern']  = 'questionType';

            $testpaper = $this->getTestpaperService()->buildTestpaper($fields, 'testpaper');

            return $this->redirect($this->generateUrl('course_manage_testpaper_questions', array('courseId' => $course['id'], 'testpaperId' => $testpaper['id'])));
        }

        $typeNames = $this->get('codeages_plugin.dict_twig_extension')->getDict('questionType');
        $types     = array();

        foreach ($typeNames as $type => $name) {
            $typeObj = QuestionTypeFactory::create($type);
            $types[] = array(
                'key'          => $type,
                'name'         => $name,
                'hasMissScore' => $typeObj->hasMissScore()
            );
        }

        $conditions['types']    = ArrayToolkit::column($types, 'key');
        $conditions['courseId'] = $course['id'];

        $questionNums = $this->getQuestionService()->getQuestionCountGroupByTypes($conditions);
        $questionNums = ArrayToolkit::index($questionNums, 'type');

        $conditions                              = array();
        $conditions['type']                      = 'material';
        $conditions['subCount']                  = 0;
        $questionNums['material']['questionNum'] = $this->getQuestionService()->searchCount($conditions);

        return $this->render('WebBundle:TestpaperManage:create.html.twig', array(
            'course'       => $course,
            'ranges'       => $this->getQuestionRanges($course),
            'types'        => $types,
            'questionNums' => $questionNums
        ));
    }

    public function checkListAction(Request $request, $courseId, $type)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $conditions = array(
            'status'   => 'open',
            'courseId' => $course['id'],
            'type'     => $type
        );

        $paginator = new Paginator(
            $request,
            $this->getTestpaperService()->searchTestpaperCount($conditions),
            10
        );

        $testpapers = $this->getTestpaperService()->searchTestpapers(
            $conditions,
            array('createdTime' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        foreach ($testpapers as $key => $testpaper) {
            $testpapers[$key]['resultStatusNum'] = $this->getTestpaperService()->findPaperResultsStatusNumGroupByStatus($testpaper['id']);
        }

        return $this->render('WebBundle:TestpaperManage:check-list.html.twig', array(
            'course'     => $course,
            'testpapers' => ArrayToolkit::index($testpapers, 'id'),
            'paginator'  => $paginator
        ));
    }

    public function checkAction(Request $request, $resultId)
    {
        $result = $this->getTestpaperService()->getTestpaperResult($resultId);

        if (!$result) {
            throw $this->createResourceNotFoundException('testpaperResult', $resultId);
        }

        $source   = $request->query->get('source', 'course');
        $targetId = $request->query->get('targetId', 0);

        $testpaper = $this->getTestpaperService()->getTestpaper($result['testId']);
        if (!$testpaper) {
            throw $this->createResourceNotFoundException('testpaper', $result['id']);
        }

        if ($result['status'] != 'reviewing') {
            return $this->redirect($this->generateUrl('testpaper_result_show', array('resultId' => $result['id'])));
        }

        if ($request->getMethod() == 'POST') {
            $formData    = $request->request->all();
            $paperResult = $this->getTestpaperService()->checkFinish($result['id'], $formData);

            $this->createJsonResponse(true);
        }

        $questions = $this->getTestpaperService()->showTestpaperItems($result['id']);

        $essayQuestions = $this->getCheckedEssayQuestions($questions);

        $student  = $this->getUserService()->getUser($result['userId']);
        $accuracy = $this->getTestpaperService()->makeAccuracy($result['id']);
        $total    = $this->getTestpaperService()->countQuestionTypes($testpaper, $questions);

        return $this->render('WebBundle:TestpaperManage:teacher-check.html.twig', array(
            'paper'         => $testpaper,
            'paperResult'   => $result,
            'questions'     => $essayQuestions,
            'student'       => $student,
            'accuracy'      => $accuracy,
            'questionTypes' => array('essay', 'material'),
            'total'         => $total,
            'source'        => $source,
            'targetId'      => $targetId,
            'isTeacher'     => true
        ));
    }

    public function resultListAction(Request $request, $testpaperId, $source, $targetId)
    {
        $user = $this->getUser();

        $testpaper = $this->getTestpaperService()->getTestpaper($testpaperId);
        if (!$testpaper) {
            throw $this->createResourceNotFoundException('testpaper', $testpaperId);
        }

        $status  = $request->query->get('status', 'finished');
        $keyword = $request->query->get('keyword', '');

        if (!in_array($status, array('all', 'finished', 'reviewing', 'doing'))) {
            $status = 'all';
        }

        $conditions = array('testId' => $testpaper['id']);
        if ($status != 'all') {
            $conditions['status'] = $status;
        }

        if (!empty($keyword)) {
            $searchUser           = $this->getUserService()->getUserByNickname($keyword);
            $conditions['userId'] = $searchUser ? $searchUser['id'] : '-1';
        }

        $testpaper['resultStatusNum'] = $this->getTestpaperService()->findPaperResultsStatusNumGroupByStatus($testpaper['id']);

        $paginator = new Paginator(
            $request,
            $this->getTestpaperService()->searchTestpaperResultsCount($conditions),
            10
        );

        $testpaperResults = $this->getTestpaperService()->searchTestpaperResults(
            $conditions,
            array('endTime' => 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $userIds = ArrayToolkit::column($testpaperResults, 'userId');
        $users   = $this->getUserService()->findUsersByIds($userIds);

        return $this->render('WebBundle:TestpaperManage:result-list.html.twig', array(
            'testpaper'    => $testpaper,
            'status'       => $status,
            'paperResults' => $testpaperResults,
            'paginator'    => $paginator,
            'users'        => $users,
            'source'       => $source,
            'targetId'     => $targetId,
            'isTeacher'    => true
        ));
    }

    public function getQuestionCountGroupByTypesAction(Request $request, $courseId)
    {
        $params = $request->query->all();
        $course = $this->getCourseService()->tryManageCourse($courseId);

        if (empty($course)) {
            return $this->createJsonResponse(array());
        }

        $typeNames = $this->get('topxia.twig.web_extension')->getDict('questionType');
        $types     = array();

        foreach ($typeNames as $type => $name) {
            $typeObj = QuestionTypeFactory::create($type);
            $types[] = array(
                'key'          => $type,
                'name'         => $name,
                'hasMissScore' => $typeObj->hasMissScore()
            );
        }

        $conditions["types"] = ArrayToolkit::column($types, "key");

        if ($params["range"] == "course") {
            $conditions["courseId"] = $course["id"];
        } elseif ($params["range"] == "lesson") {
            $targets               = $params["targets"];
            $targets               = explode(',', $targets);
            $conditions["targets"] = $targets;
        }

        $questionNums = $this->getQuestionService()->getQuestionCountGroupByTypes($conditions);
        $questionNums = ArrayToolkit::index($questionNums, "type");
        return $this->createJsonResponse($questionNums);
    }

    public function buildCheckAction(Request $request, $courseId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $data           = $request->request->all();
        $data['ranges'] = empty($data['ranges']) ? array() : explode(',', $data['ranges']);
        $result         = $this->getTestpaperService()->canBuildTestpaper('QuestionType', $data);
        return $this->createJsonResponse($result);
    }

    public function updateAction(Request $request, $courseId, $id)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $testpaper = $this->getTestpaperService()->getTestpaper($id);

        if (empty($testpaper)) {
            throw $this->createNotFoundException($this->getServiceKernel()->trans('试卷不存在'));
        }

        if ($request->getMethod() == 'POST') {
            $data      = $request->request->all();
            $testpaper = $this->getTestpaperService()->updateTestpaper($id, $data);
            $this->setFlashMessage('success', $this->getServiceKernel()->trans('试卷信息保存成功！'));
            return $this->redirect($this->generateUrl('course_manage_testpaper', array('courseId' => $course['id'])));
        }

        return $this->render('TopxiaWebBundle:CourseTestpaperManage:update.html.twig', array(
            'course'    => $course,
            'testpaper' => $testpaper
        ));
    }

    public function deleteAction(Request $request, $courseId, $testpaperId)
    {
        $course    = $this->getCourseService()->tryManageCourse($courseId);
        $testpaper = $this->getTestpaperWithException($course, $testpaperId);
        $this->getTestpaperService()->deleteTestpaper($testpaper['id']);

        return $this->createJsonResponse(true);
    }

    public function deletesAction(Request $request, $courseId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $ids = $request->request->get('ids');

        foreach (is_array($ids) ? $ids : array() as $id) {
            $testpaper = $this->getTestpaperWithException($course, $id);
            $this->getTestpaperService()->deleteTestpaper($id);
        }

        return $this->createJsonResponse(true);
    }

    public function publishAction(Request $request, $courseId, $id)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $testpaper = $this->getTestpaperService()->publishTestpaper($id);

        $user = $this->getUserService()->getUser($testpaper['updatedUserId']);

        return $this->render('WebBundle:TestpaperManage:testpaper-list-tr.html.twig', array(
            'testpaper' => $testpaper,
            'user'      => $user,
            'course'    => $course
        ));
    }

    public function closeAction(Request $request, $courseId, $id)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $testpaper = $this->getTestpaperService()->closeTestpaper($id);

        $user = $this->getUserService()->getUser($testpaper['updatedUserId']);

        return $this->render('WebBundle:TestpaperManage:testpaper-list-tr.html.twig', array(
            'testpaper' => $testpaper,
            'user'      => $user,
            'course'    => $course
        ));
    }

    protected function getTestpaperWithException($course, $testpaperId)
    {
        $testpaper = $this->getTestpaperService()->getTestpaper($testpaperId);

        if (empty($testpaper)) {
            throw $this->createNotFoundException();
        }

        if ($testpaper['target'] != "course-{$course['id']}") {
            throw $this->createAccessDeniedException();
        }

        return $testpaper;
    }

    public function questionsAction(Request $request, $courseId, $testpaperId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $testpaper = $this->getTestpaperService()->getTestpaper($testpaperId);

        if (empty($testpaper)) {
            throw $this->createNotFoundException($this->getServiceKernel()->trans('试卷不存在'));
        }

        if ($request->getMethod() == 'POST') {
            $data = $request->request->all();

            if (empty($data['questionId']) || empty($data['scores'])) {
                return $this->createMessageResponse('error', $this->getServiceKernel()->trans('试卷题目不能为空！'));
            }

            if (count($data['questionId']) != count($data['scores'])) {
                return $this->createMessageResponse('error', $this->getServiceKernel()->trans('试卷题目数据不正确'));
            }

            $data['questionId'] = array_values($data['questionId']);
            $data['scores']     = array_values($data['scores']);

            $items = array();

            foreach ($data['questionId'] as $index => $questionId) {
                $items[] = array('questionId' => $questionId, 'score' => $data['scores'][$index]);
            }

            $this->getTestpaperService()->updateTestpaperItems($testpaper['id'], $items);

            if (isset($data['passedScore'])) {
                $this->getTestpaperService()->updateTestpaper($testpaperId, array('passedScore' => $data['passedScore']));
            }

            $this->setFlashMessage('success', $this->getServiceKernel()->trans('试卷题目保存成功！'));
            return $this->redirect($this->generateUrl('course_manage_testpaper', array('courseId' => $courseId)));
        }

        $items     = $this->getTestpaperService()->findItemsByTestId($testpaper['id']);
        $questions = $this->getQuestionService()->findQuestionsByIds(ArrayToolkit::column($items, 'questionId'));

        //$targets = $this->get('topxia.target_helper')->getTargets(ArrayToolkit::column($questions, 'target'));

        $subItems   = array();
        $hasEssay   = false;
        $scoreTotal = 0;

        foreach ($items as $key => $item) {
            if ($item['questionType'] == 'essay') {
                $hasEssay = true;
            }

            if ($item['questionType'] != 'material') {
                $scoreTotal = $scoreTotal + $item['score'];
            }

            if ($item['parentId'] > 0) {
                $subItems[$item['parentId']][] = $item;
                unset($items[$key]);
            }
        }

        $passedScoreDefault = ceil($scoreTotal * 0.6);
        return $this->render('WebBundle:TestpaperManage:question.html.twig', array(
            'course'             => $course,
            'testpaper'          => $testpaper,
            'items'              => ArrayToolkit::group($items, 'questionType'),
            'subItems'           => $subItems,
            'questions'          => $questions,
            //'targets'            => $targets,
            'hasEssay'           => $hasEssay,
            'passedScoreDefault' => $passedScoreDefault
        ));
    }

    public function itemsResetAction(Request $request, $courseId, $testpaperId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $testpaper = $this->getTestpaperService()->getTestpaper($testpaperId);

        if (empty($testpaper)) {
            throw $this->createNotFoundException($this->getServiceKernel()->trans('试卷不存在'));
        }

        if ($request->getMethod() == 'POST') {
            $data           = $request->request->all();
            $data['target'] = "course-{$course['id']}";
            $data['ranges'] = explode(',', $data['ranges']);
            $this->getTestpaperService()->buildTestpaper($testpaper['id'], $data);
            return $this->redirect($this->generateUrl('course_manage_testpaper_items', array('courseId' => $courseId, 'testpaperId' => $testpaperId)));
        }

        $typeNames = $this->get('topxia.twig.web_extension')->getDict('questionType');
        $types     = array();

        foreach ($typeNames as $type => $name) {
            $typeObj = QuestionTypeFactory::create($type);
            $types[] = array(
                'key'          => $type,
                'name'         => $name,
                'hasMissScore' => $typeObj->hasMissScore()
            );
        }

        $conditions["types"]    = ArrayToolkit::column($types, "key");
        $conditions["courseId"] = $course["id"];
        $questionNums           = $this->getQuestionService()->getQuestionCountGroupByTypes($conditions);
        $questionNums           = ArrayToolkit::index($questionNums, "type");
        return $this->render('TopxiaWebBundle:CourseTestpaperManage:items-reset.html.twig', array(
            'course'       => $course,
            'testpaper'    => $testpaper,
            'ranges'       => $this->getQuestionRanges($course),
            'types'        => $types,
            'questionNums' => $questionNums
        ));
    }

    public function itemPickerAction(Request $request, $courseId, $testpaperId)
    {
        $course    = $this->getCourseService()->tryManageCourse($courseId);
        $testpaper = $this->getTestpaperService()->getTestpaper($testpaperId);

        if (empty($testpaper)) {
            throw $this->createNotFoundException();
        }

        $conditions = $request->query->all();

        if (empty($conditions['target'])) {
            $conditions['targetPrefix'] = "course-{$course['id']}";
        }

        $conditions['parentId']   = 0;
        $conditions['excludeIds'] = empty($conditions['excludeIds']) ? array() : explode(',', $conditions['excludeIds']);

        if (!empty($conditions['keyword'])) {
            $conditions['stem'] = $conditions['keyword'];
        }

        if ($conditions['type'] == 'material') {
            $conditions['subCount'] = 0;
        }

        $replace = empty($conditions['replace']) ? '' : $conditions['replace'];

        $paginator = new Paginator(
            $request,
            $this->getQuestionService()->searchCount($conditions),
            7
        );

        $questions = $this->getQuestionService()->search(
            $conditions,
            array('createdTime', 'DESC'),
            $paginator->getOffsetCount(),
            $paginator->getPerPageCount()
        );

        $targets = $this->get('topxia.target_helper')->getTargets(ArrayToolkit::column($questions, 'target'));
        return $this->render('TopxiaWebBundle:CourseTestpaperManage:item-picker-modal.html.twig', array(
            'course'        => $course,
            'testpaper'     => $testpaper,
            'questions'     => $questions,
            'replace'       => $replace,
            'paginator'     => $paginator,
            'targetChoices' => $this->getQuestionRanges($course, true),
            'targets'       => $targets,
            'conditions'    => $conditions
        ));
    }

    public function itemPickedAction(Request $request, $courseId, $testpaperId)
    {
        $course    = $this->getCourseService()->tryManageCourse($courseId);
        $testpaper = $this->getTestpaperService()->getTestpaper($testpaperId);

        if (empty($testpaper)) {
            throw $this->createNotFoundException();
        }

        $question = $this->getQuestionService()->get($request->query->get('questionId'));

        if (empty($question)) {
            throw $this->createNotFoundException();
        }

        if ($question['subCount'] > 0) {
            $subQuestions = $this->getQuestionService()->findQuestionsByParentId($question['id']);
        } else {
            $subQuestions = array();
        }

        $targets = $this->get('topxia.target_helper')->getTargets(array($question['target']));

        return $this->render('TopxiaWebBundle:CourseTestpaperManage:item-picked.html.twig', array(
            'course'       => $course,
            'testpaper'    => $testpaper,
            'question'     => $question,
            'subQuestions' => $subQuestions,
            'targets'      => $targets,
            'type'         => $question['type']
        ));
    }

    public function itemsGetAction(Request $request, $courseId)
    {
        $course = $this->getCourseService()->tryManageCourse($courseId);

        $testpaperId = $request->request->get('testpaperId');

        $testpaper = $this->getTestpaperService()->getTestpaper($testpaperId);

        if (empty($testpaper)) {
            throw $this->createNotFoundException();
        }

        $items    = $this->getTestpaperService()->getItemsCountByParams(array('testId' => $testpaperId, 'parentIdDefault' => 0), $gourpBy = 'questionType');
        $subItems = $this->getTestpaperService()->getItemsCountByParams(array('testId' => $testpaperId, 'parentId' => 0));

        $items = ArrayToolkit::index($items, 'questionType');

        $items['material'] = $subItems[0];

        return $this->render('WebBundle:TestpaperManage:item-get-table.html.twig', array(
            'items' => $items
        ));
    }

    protected function getQuestionRanges($course, $includeCourse = false)
    {
        $lessons = $this->getCourseService()->getCourseLessons($course['id']);
        $ranges  = array();

        if ($includeCourse == true) {
            $ranges["course-{$course['id']}"] = $this->getServiceKernel()->trans('本课程');
        }

        foreach ($lessons as $lesson) {
            if ($lesson['type'] == 'testpaper') {
                continue;
            }

            $ranges["course-{$lesson['courseId']}/lesson-{$lesson['id']}"] = $this->getServiceKernel()->trans('课时%lessonnumber%： %lessontitle%',
                array('%lessonnumber%' => $lesson['number'], '%lessontitle%' => $lesson['title']));
        }

        return $ranges;
    }

    protected function getCheckedEssayQuestions($questions)
    {
        //$checkedQuestionTypes = $this->getQuestionService()->getCheckedQuestionTypes();
        $essayQuestions = array();

        /*foreach ($questions as $type => $items) {
        if (in_array($type, $checkedQuestionTypes) && $type != 'material') {
        $essayQuestions[$type] = $items;
        }

        if ($type == 'material') {
        foreach ($variable as $key => $value) {
        # code...
        }
        }
        }*/
        $essayQuestions['essay'] = !empty($questions['essay']) ? $questions['essay'] : array();

        if (empty($questions['material'])) {
            return $essayQuestions;
        }

        foreach ($questions['material'] as $questionId => $question) {
            $questionTypes = ArrayToolkit::column(empty($question['subs']) ? array() : $question['subs'], 'type');

            if (in_array('essay', $questionTypes)) {
                $essayQuestions['material'][$questionId] = $question;
            }
        }

        return $essayQuestions;
    }

    protected function getCourseService()
    {
        return ServiceKernel::instance()->createService('Course.CourseService');
    }

    protected function getUserService()
    {
        return ServiceKernel::instance()->createService('User.UserService');
    }

    protected function getTestpaperService()
    {
        return $this->createService('Testpaper:TestpaperService');
    }

    protected function getQuestionService()
    {
        return $this->createService('Question:QuestionService');
    }

    protected function getServiceKernel()
    {
        return ServiceKernel::instance();
    }
}
