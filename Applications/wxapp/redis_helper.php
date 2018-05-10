<?php

/**
* Helper class for redis
*/
class RedisHelper {
    protected $redisConfig;
    public function __construct(){
        $this->redisConfig = json_decode(file_get_contents(__DIR__.'/db_config.php'), true)['redis'];
    }

    public function connect_redis($type = 'pconnect'){
        $redis = new Redis();
        ($type == 'connect') 
            ? $redis->pconnect($this->redisConfig['host'], $this->redisConfig['port'])
            : $redis->connect($this->redisConfig['host'], $this->redisConfig['port']);
        $redis->auth($this->redisConfig['authKey']);
        return $redis;
    }

    public function close_redis($redis){
        return $redis->close();
    }
}