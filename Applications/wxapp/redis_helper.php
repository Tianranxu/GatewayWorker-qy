<?php

/**
* Helper class for redis
*/
class RedisHelper {
    public static function connect_redis($redisConfig, $type = 'pconnect'){
        $redis = new Redis();
        ($type == 'connect') 
            ? $redis->pconnect($redisConfig['host'], $redisConfig['port'])
            : $redis->connect($redisConfig['host'], $redisConfig['port']);
        $redis->auth($redisConfig['authKey']);
        return $redis;
    }

    public static function close_redis($redis){
        return $redis->close();
    }
}