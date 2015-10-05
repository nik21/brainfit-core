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
    private static $cacheProvider = null;
    private static $ttl = 0;
    private static $version = '';
    private static $cache = null;
    private static $cacheKey = null;

    public static function setQueryCacheImpl(\Doctrine\Common\Cache\Cache $obCache, $iTTL = 0, $sVersion = '')
    {
        self::$cacheProvider = $obCache;
        self::$ttl = $iTTL;
        self::$version = $sVersion;
    }

    /**
     * Load any configuration files together. All repetitive sections redefined.
     * If you do not download the files will be loaded /config/default.yml
     *
     * @param $aConfigurationFiles
     *
     * @throws Exception
     */
    public static function loadConfiguration($aConfigurationFiles)
    {
        if(!is_null(self::$aConfigurationFiles))
            throw new Exception('Configuration already loaded');

        if(!is_array($aConfigurationFiles))
            $aConfigurationFiles = [$aConfigurationFiles];

        self::$aConfigurationFiles = $aConfigurationFiles;
    }

    private static function getCacheKey()
    {
        if (is_null(self::$cacheKey)) {
            self::$cacheKey = self::$version;
            foreach (self::$aConfigurationFiles as $sFilename)
                self::$cacheKey .= md5($sFilename);
        }

        return self::$cacheKey;
    }

    public static function get()
    {
        if(is_null(self::$cache))
        {
            if(is_null(self::$aConfigurationFiles))
                self::loadConfiguration(ROOT . '/config/default.yml');

            if(!is_null(self::$cacheProvider))
            {
                $ret = self::$cacheProvider->fetch(self::getCacheKey());
            }

            if(is_null(self::$cacheProvider) || !$ret)
            {
                $ret = self::getConfig(self::$aConfigurationFiles);

                if(!is_null(self::$cacheProvider))
                    self::$cacheProvider->save(self::getCacheKey(), $ret, self::$ttl);
            }

            self::$cache = $ret;
        }
        else
            $ret = self::$cache;

        //TODO: should be optimized
        $iNumArgs = func_num_args();

        for ($i = 0; $i < $iNumArgs; $i++)
        {
            $sPath = func_get_arg($i);

            if(!isset($ret[$sPath]))
                return null;

            $ret = &$ret[$sPath];
        }

        return $ret;
    }

    public static function cleanCache()
    {
        if(!is_null(self::$cacheProvider))
            self::$cacheProvider->delete(self::getCacheKey());
        self::$cache = null;
    }

    private static function getConfig($aFiles)
    {
        $ret = [];

        foreach ($aFiles as $sNamespace => $sFile)
        {
            $aNamespace = is_string($sNamespace) ? explode('/', $sNamespace) : [];

            try
            {
                $aContent = Yaml::parse(file_get_contents($sFile));
            }
            catch (ParseException $e)
            {
                throw new Exception('Yaml parser error: ' . $e->getMessage(), $e->getCode());
            }

            //Enter namespace
            $retNamespaceChild = &$ret;
            foreach ($aNamespace as $sPath)
            {
                if(!isset($retNamespaceChild[$sPath]))
                    $retNamespaceChild[$sPath] = [];

                $retNamespaceChild = &$retNamespaceChild[$sPath];
            }

            //assign
            if ($aContent && is_array($aContent))
            {
                foreach($aContent as $k => $v)
                    $retNamespaceChild[$k] = $v;
            }
        }

        return $ret;
    }
}
