<?php

namespace Brainfit\Io\Data\Drivers;

/**
 * Class Redis
 *
 * @package Io
 * @author Vladimir Urushev <urushev@yandex.ru>
 */

class Redis
{
    static protected $redis;
    static protected $oInstance;

    /**
     * @static
     *
     * @param $sHost
     * @param int $iPort
     * @param null $sInstanceId
     *
     * @throws \RedisException
     * @return \Redis
     */
    public static function getInstance($sHost, $iPort = 6379, $sInstanceId = null)
    {
        $sStringId = $sHost . ':' . $iPort . (!is_null($sInstanceId) ? ':' . (string)$sInstanceId : '');

        if(isset(self::$oInstance[$sStringId]))
            return self::$oInstance[$sStringId];
        else
        {
            //timeout if connection failed, but this timeout has implications for "subscribe" command
            $obNewInstance = new \Redis();
            $obNewInstance->connect($sHost, $iPort);

            if($obNewInstance === false || !isset($obNewInstance->socket)) //not-return false :(
                throw new \RedisException('Connection failed');

            self::$oInstance[$sStringId] = $obNewInstance;

            return self::$oInstance[$sStringId];
        }
    }

    public static function destruct()
    {
        /** @var $obRedis \Redis */
        foreach(self::$oInstance as $obRedis)
            $obRedis->close();

        self::$oInstance = null;
    }

    protected function __clone()
    {
    }
}
