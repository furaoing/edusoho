<?php

namespace Biz\Activity\Service\Impl;

use Biz\BaseService;
use Topxia\Common\ArrayToolkit;
use Biz\Activity\Dao\ActivityDao;
use Codeages\Biz\Framework\Event\Event;
use Biz\Activity\Service\ActivityService;
use Biz\Activity\Listener\ActivityLearnLogListener;

class ActivityServiceImpl extends BaseService implements ActivityService
{
    public function getActivity($id)
    {
        return $this->getActivityDao()->get($id);
    }

    public function getActivityFetchMedia($id)
    {
        $activity = $this->getActivity($id);
        if (!empty($activity['mediaId'])) {
            $activityConfig  = $this->getActivityConfig($activity['mediaType']);
            $media           = $activityConfig->get($activity['mediaId']);
            $activity['ext'] = $media;
        }
        return $activity;
    }

    public function findActivities($ids)
    {
        return $this->getActivityDao()->findByIds($ids);
    }

    public function findActivitiesByCourseIdAndType($courseId, $type)
    {
        $conditions = array(
            'fromCourseId' => $courseId,
            'mediaType'    => $type
        );
        return $this->getActivityDao()->search($conditions, null, 0, 1000);
    }

    public function trigger($id, $eventName, $data = array())
    {
        $activity = $this->getActivity($id);

        if (empty($activity)) {
            return;
        }

        if (in_array($eventName, array('start', 'doing'))) {
            $this->biz['dispatcher']->dispatch("activity.{$eventName}", new Event($activity, $data));
        }

        $logListener = new ActivityLearnLogListener($this->biz);

        $logData          = $data;
        $logData['event'] = $activity['mediaType'].'.'.$eventName;
        $logListener->handle($activity, $logData);

        $activityListener = $this->getActivityConfig($activity['mediaType'])->getListener($eventName);
        if (!is_null($activityListener)) {
            $activityListener->handle($activity, $data);
        }

        $this->biz['dispatcher']->dispatch("activity.operated", new Event($activity, $data));
    }

    public function createActivity($fields)
    {
        try {
            $this->beginTransaction();

            if ($this->invalidActivity($fields)) {
                throw $this->createInvalidArgumentException('activity is invalid');
            }

            if (!$this->canManageCourse($fields['fromCourseId'])) {
                throw $this->createAccessDeniedException('无权创建教学活动');
            }

            if (!$this->canManageCourseSet($fields['fromCourseSetId'])) {
                throw $this->createAccessDeniedException('无权创建教学活动');
            }
            $activityConfig = $this->getActivityConfig($fields['mediaType']);
            $media          = $activityConfig->create($fields);

            if (!empty($media)) {
                $fields['mediaId'] = $media['id'];
            }

            $fields = ArrayToolkit::parts($fields, array(
                'title',
                'remark',
                'mediaId',
                'mediaType',
                'content',
                'length',
                'fromCourseId',
                'fromCourseSetId',
                'fromUserId',
                'startTime',
                'endTime'
            ));

            $fields = array_filter($fields);

            if (isset($fields['startTime']) && isset($fields['length'])) {
                $fields['endTime'] = $fields['startTime'] + $fields['length'] * 60;
            }

            $activity = $this->getActivityDao()->create($fields);

            $listener = $activityConfig->getListener('activity.created');
            if (!empty($listener)) {
                $listener->handle($activity, array());
            }
            $this->commit();
            return $activity;
        } catch (\Exception $e) {
            $this->rollback();
            throw $e;
        }
    }

    public function updateActivity($id, $fields)
    {
        $savedActivity = $this->getActivity($id);

        if (!$this->canManageCourse($savedActivity['fromCourseId'])) {
            throw $this->createAccessDeniedException('无权更新教学活动');
        }

        $activityConfig = $this->getActivityConfig($savedActivity['mediaType']);

        if (!empty($savedActivity['mediaId'])) {
            $activityConfig->update($savedActivity['mediaId'], $fields);
        }

        $fields = ArrayToolkit::parts($fields, array(
            'title',
            'remark',
            'desc',
            'content',
            'length',
            'startTime',
            'endTime'
        ));

        if (isset($fields['startTime']) && isset($fields['length'])) {
            $fields['endTime'] = $fields['startTime'] + $fields['length'] * 60;
        }

        return $this->getActivityDao()->update($id, $fields);
    }


    public function deleteActivity($id)
    {
        $activity = $this->getActivity($id);

        if (!$this->canManageCourse($activity['fromCourseId'])) {
            throw $this->createAccessDeniedException('无权删除教学活动');
        }

        $activityConfig = $this->getActivityConfig($activity['mediaType']);

        $activityConfig->delete($activity['mediaId']);

        return $this->getActivityDao()->delete($id);
    }

    public function isFinished($id)
    {
        $activity       = $this->getActivity($id);
        $activityConfig = $this->getActivityConfig($activity['mediaType']);
        return $activityConfig->isFinished($id);
    }

    /**
     * @return ActivityDao
     */
    protected function getActivityDao()
    {
        return $this->createDao('Activity:ActivityDao');
    }

    protected function canManageCourse($courseId)
    {
        return true;
    }

    protected function canManageCourseSet($fromCourseSetId)
    {
        return true;
    }

    protected function invalidActivity($activity)
    {
        if (!ArrayToolkit::requireds($activity, array(
            'title',
            'mediaType',
            'fromCourseId',
            'fromCourseSetId'
        ))
        ) {
            return true;
        }
        $activity = $this->getActivityConfig($activity['mediaType']);
        if (!is_object($activity)) {
            return true;
        }
        return false;
    }

    public function getActivityConfig($type)
    {
        return $this->biz["activity_type.{$type}"];
    }
}
