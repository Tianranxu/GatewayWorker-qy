<?php
require_once __DIR__ . '/helper.php';

use qiyu\helper\Helper;
/**
* send request to qiyu server
*/
class Sender {
    protected $appconfig;

    function __construct(){
        $this->appconfig = require(__DIR__ . '/app_config.php');
    }

    /**
     *发送消息到七鱼服务器
     *@param string $msgContent 发送请求的内容(json字符串)
     */
    public function pushMsg($msgContent){
        $this->getUrl('https://qiyukf.com/openapi/message/send', $msgContent);
        return Helper::http_post($url, $msgContent);
    }

    /**
     * 请求分配客服
     *@param string $msgContent 发送请求的内容(json字符串)：1.uid 2. staffType:如果传0，表示机器人，传1表示人工。默认为机器人。
     */
    public function applyStaff($msgContent){
        $this->getUrl('https://qiyukf.com/openapi/event/applyStaff', $msgContent);
        return Helper::http_post($url, $msgContent);
    }

    /**
     * 更新用户资料
     *@param string $msgContent 发送请求的内容(json字符串)
     */  
    public function updateUserInfo($msgContent){
        $this->getUrl('https://qiyukf.com/openapi/event/updateUInfo', $msgContent);
        return Helper::http_post($url, $msgContent);
    }

    /**
     * 评价客服
     *@param string $msgContent 发送请求的内容(json字符串)
     */
    public function evaluate($msgContent){
        $this->getUrl('https://qiyukf.com/openapi/event/evaluate', $msgContent);
        return Helper::http_post($url, $msgContent);
    }

    /**
     * 查询排队状态
     *@param string $msgContent 发送请求的内容(json字符串)
     */
    public function queryQueueStatus($msgContent){
        $this->getUrl('https://qiyukf.com/openapi/event/queryQueueStatu', $msgContent);
        return Helper::http_post($url, $msgContent);
    }

    /**
     * 查询排队状态
     *@param string $msgContent 发送请求的内容(json字符串)
    */
    public function quitQueue($msgContent){
        $this->getUrl('https://qiyukf.com/openapi/event/quitQueue', $msgContent);
        return Helper::http_post($url, $msgContent);
    }
    
    public function getUrl($frontUrl, $msgContent){
        $time = time();
        return $frontUrl . '?appKey='.$this->appconfig['key'].'&time='.$time.'&checksum='.Helper::getChecksum($this->appconfig['secrect'], $msgContent, $time);
    }
}