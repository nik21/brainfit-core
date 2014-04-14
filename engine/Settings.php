<?php

class Settings
{
    public static function getMemcachedServers()
    {
        $aRet = self::get('MEMCACHED', 'servers');

        if (!$aRet || !$aRet[0][0])
            throw new \Model\Exception('Not specified memcached-cluster servers');

        return (array)$aRet;
    }

    public static function get()
    {
        $ret = \Io\Data\Config::get(
            md5_file(ROOT.'config/default.yml').'+'.md5_file(ROOT.\Server::CUSTOM_CONFIGURATION),
            ROOT.'config/default.yml',
            ROOT.\Server::CUSTOM_CONFIGURATION
        );

        //TODO: should be optimized
        $iNumArgs = func_num_args();

        for($i=0;$i<$iNumArgs;$i++)
        {
            $sPath = func_get_arg($i);

            if (!isset($ret[$sPath]))
                return null;

            $ret = &$ret[$sPath];
        }

        return $ret;
    }
}
