<?php

/**
* Helper function
*/
class Helper {

    public static function http_get($url){
        $oCurl = curl_init ();
        if (stripos ( $url, "https://" ) !== FALSE) {
            curl_setopt ( $oCurl, CURLOPT_SSL_VERIFYPEER, FALSE );
            curl_setopt ( $oCurl, CURLOPT_SSL_VERIFYHOST, FALSE );
        }
        curl_setopt ( $oCurl, CURLOPT_URL, $url );
        curl_setopt ( $oCurl, CURLOPT_TIMEOUT, 15 );
        curl_setopt ( $oCurl, CURLOPT_RETURNTRANSFER, 1 );
        $sContent = curl_exec ( $oCurl );
        $aStatus = curl_getinfo ( $oCurl );
        curl_close ( $oCurl );
        if (intval ( $aStatus ["http_code"] ) == 200) {
            return json_decode($sContent, true);
        } else {
            return false;
        }
    }

    public static function http_post($url, $parameter){
        $oCurl = curl_init ();
        if (stripos ( $url, "https://" ) !== FALSE) {
            curl_setopt ( $oCurl, CURLOPT_SSL_VERIFYPEER, FALSE );
            curl_setopt ( $oCurl, CURLOPT_SSL_VERIFYHOST, false );
        }
        curl_setopt ( $oCurl, CURLOPT_URL, $url );
        curl_setopt ( $oCurl, CURLOPT_RETURNTRANSFER, 1 );
        curl_setopt ( $oCurl, CURLOPT_TIMEOUT, 15 );
        curl_setopt ( $oCurl, CURLOPT_POST, true );
        curl_setopt ( $oCurl, CURLOPT_POSTFIELDS, json_encode($parameter) );
        $sContent = curl_exec ( $oCurl );
        $aStatus = curl_getinfo ( $oCurl );
        curl_close ( $oCurl );
        if (intval ( $aStatus ["http_code"] ) == 200) {
            return json_decode($sContent, true);
        } else {
            return false;
        }
    }

    public static function pconnect_redis($redisConfig){
        $redis = new Redis();
        $redis->pconnect($redisConfig['host'], $redisConfig['port']);
        $redis->auth($redisConfig['authKey']);
        return $redis;
    }
}