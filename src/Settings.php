<?php

namespace Brainfit;

use Brainfit\Model\Exception;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

class Settings
{
    private static $aConfigurationFiles = null;

    /**
     * @var \Doctrine\Common\Cache\Cache
     */
    private static $cache = null;
    private static $iTTL = 0;
    private static $sVersion = '';

    public static function setQueryCacheImpl(\Doctrine\Common\Cache\Cache $obCache, $iTTL = 0, $sVersion = '')
    {
        self::$cache = $obCache;
        self::$iTTL = $iTTL;
        self::$sVersion = $sVersion;
    }

    public static function loadConfiguration($aConfigurationFiles)
    {
        if (!is_null(self::$aConfigurationFiles))
            throw new Exception('Configuration already loaded');

        self::$aConfigurationFiles = $aConfigurationFiles;
    }

    public static function get()
    {
        if (is_null(self::$aConfigurationFiles))
            throw new Exception('Configuration not loaded');

        //when file changes, your fetch old values
        if (!is_null(self::$cache))
        {
            $sFilenameMd5 = self::$sVersion;
            foreach(self::$aConfigurationFiles as $sFilename)
                $sFilenameMd5 .= md5($sFilename);

            $ret = self::$cache->fetch($sFilenameMd5);
        }

        if (is_null(self::$cache) || !$ret)
        {
            $ret = self::getConfig(self::$aConfigurationFiles);

            if (!is_null(self::$cache))
                self::$cache->save($sFilenameMd5, $ret, self::$iTTL);
        }

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

    private static function getConfig($aFiles)
    {
        $ret = [];

        foreach($aFiles as $sNamespace => $sFile)
        {
            $aNamespace = is_string($sNamespace) ? explode('/', $sNamespace) : [];

            try
            {
                $aContent = Yaml::parse(file_get_contents($sFile));
            }
            catch(ParseException $e)
            {
                throw new Exception('Yaml parser error: '.$e->getMessage(), $e->getCode());
            }

            //Enter namespace
            $retNamespaceChild = &$ret;
            foreach($aNamespace as $sPath)
            {
                if (!isset($retNamespaceChild[$sPath]))
                    $retNamespaceChild[$sPath] = [];

                $retNamespaceChild = &$retNamespaceChild[$sPath];
            }

            //assign
            foreach($aContent as $k=>$v)
                $retNamespaceChild[$k] = $v;
        }

        return $ret;
    }
}
