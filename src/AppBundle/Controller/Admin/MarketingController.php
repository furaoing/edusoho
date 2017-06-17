<?php

namespace AppBundle\Controller\Admin;

use Symfony\Component\HttpFoundation\Request;
use Codeages\RestApiClient\RestApiClient;
use Codeages\RestApiClient\Specification\SimpleJsonHmacSpecification;

class MarketingController extends BaseController
{
    public function toMarketingAction(Request $request)
    {
        $merchantUrl = $request->getSchemeAndHttpHost();

        $site = $this->getSettingService()->get('site', array());
        $storage = $this->getSettingService()->get('storage', array());

        $user = $this->getCurrentUser();
        $marketingDomain = "http://marketing.dev";

        $config = array(
            'accessKey' => $storage['cloud_access_key'],
            'secretKey' => $storage['cloud_secret_key'],
            'endpoint' => $marketingDomain.'/merchant',
        );

        $spec = new SimpleJsonHmacSpecification('sha1');
        $client = new RestApiClient($config, $spec);

        try {
            $login = $client->post('/login', array(
                'access_key' => $storage['cloud_access_key'],
                'name' => $site['name'],
                'url' => $merchantUrl,
                'user_id' => $user['id'],
                'user_name' => $user['nickname'],
            ));
        } catch (\Exception $e) {
            return $this->createMessageResponse('error',$e->getMessage());
        }
       
        return  $this->redirect($login['url']);
    }

    public function canOpenMarketing(Request $request)
    {
        return true;
    }

    
    protected function getFileService()
    {
        return $this->createService('Content:FileService');
    }

    protected function getSettingService()
    {
        return $this->createService('System:SettingService');
    }
}
