<?php
require_once __DIR__.'/helper.php';
require_once __DIR__.'/../redis_helper.php';
require_once __DIR__.'/../GatewayClient/Gateway.php';

use GatewayClient\Gateway;
use qiyu\helper\Helper;
/**
* receive request from qiyu server
*/
class Receiver {
    protected $appconfig;
    
    function __construct() {
        $this->appconfig = json_decode(file_get_contents(__DIR__.'/app_config.json'), true);
        Gateway::$registerAddress = '127.0.0.1:1238';
    }

    //七鱼请求处理方法
    public function handler(){
        $json_data = file_get_contents("php://input");
        if (!$this->verify($_GET['checksum'], $json_data, $_GET['time'])){
            echo '';
            return ;
        }
        $event = $_GET['eventType'];
        $function = new ReflectionMethod(get_called_class(), 'event_'.strtolower($event));
        $function->invoke($this, json_decode($json_data, true));
    }

    public function event_msg($post_data){
        $redis_helper = new RedisHelper();
        $redis = $redis_helper->connect_redis('pconnect');
        if ($redis->zScore('msgId', $post_data['msgId']) !== false) {
            //消息验重
            echo '';
            return false;
        }
        $redis->zAdd('msgId', $post_data['msgId'], $post_data['timeStamp']);
        //发送给用户
        $staff = explode('`', $redis->hGet('staffInfo', $post_data['staffId']));
        $content = ($post_data['msgType'] == 'PICTURE') ? ['url' => $post_data['content']['url']] : $post_data['content'];
        $chatContent = [
            'type' => 'say',
            'msg' => [
                [
                    'contentType' => $post_data['msgType'],
                    'content' => $content,
                    'isMe' => false,
                    'avatar' => $staff[1]
                ]
            ]
        ];
        Gateway::sendToUid($post_data['uid'], json_encode($chatContent));
        $this->addStaffChatRecord($redis, $post_data, $staff[1]);
        
        echo '';
        return ;
    }

    public function event_session_start($post_data){
        Gateway::sendToUid($post_data['uid'], json_encode([
            'type' => 'notice',
            'msg' => 'session_start',
            'staffId' => $post_data['staffId']
        ]));
        echo '';
        return ;
    }

    public function event_session_end($post_data){
        echo '';
        return ;
    }

    public function event_eva_invitation($post_data){
        echo '';
        return ;
    }

    public function event_user_join_queue($post_data){
        echo '';
        return ;
    }

    public function event_queue_timeout($post_data){
        echo '';
        return ;
    }

    public function addStaffChatRecord($redis, $post_data, $avatar){
        $record = [
            'isMe' => 'not',
            'staffId' => $post_data['staffId'],
            'msgType' => $post_data['msgType'],
            'content' => $post_data['content'],
            'staff_avatar' => $avatar
        ];
        $redis->lPush('chatRecord:'.$post_data['uid'], implode('`', $record));
        //仅保留最新的30条数据
        $redis->lTrim('chatRecord:'.$post_data['uid'], 0, 29);
        return ;
    }

    //验签
    public function verify($checksum, $json_data, $time){
        if($checksum == Helper::getChecksum( $this->appconfig['secret'], $json_data, $time)){
            return true;
        }
        return false;
    }

    protected function logger($content,$file = 'qiyu_receiver.log'){
        file_put_contents($file, '['.date('Y-m-d H:i:s').'] - '.json_encode($content)."\n", FILE_APPEND | LOCK_EX);
        return ; 
    }
}