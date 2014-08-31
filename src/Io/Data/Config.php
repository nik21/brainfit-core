<?php

namespace Brainfit\Io\Data;

use Brainfit\Io\Data\Drivers\Apc;
use Brainfit\Model\Exception;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser;
use Symfony\Component\Yaml\Yaml;

class Config
{
    const KEY = 'config';

    private static $aConfig;

    public static function get($aFiles)
    {
        $sFilesChecksum = '';
        foreach($aFiles as $sFilename)
        {
            $sMd5 = md5_file($sFilename);
            if (!$sMd5)
                throw new Exception('Invalid MD5 checksum for file '.$sFilename);

            $sFilesChecksum .= $sMd5.'+';
        }

        $sFilesChecksum = self::KEY.sha1($sFilesChecksum);

        if(isset(self::$aConfig[$sFilesChecksum]))
            return self::$aConfig[$sFilesChecksum];

        if(!apc_exists($sFilesChecksum))
        {
            self::$aConfig[$sFilesChecksum] = self::getConfig($aFiles);
            apc_store($sFilesChecksum, self::$aConfig[$sFilesChecksum]);
        }
        else
            self::$aConfig[$sFilesChecksum] = apc_fetch($sFilesChecksum);

        return self::$aConfig[$sFilesChecksum];
    }

    private static function getConfig($aFiles)
    {
        $ret = [];

        foreach($aFiles as $sFile)
        {
            try
            {
                $aContent = Yaml::parse(file_get_contents($sFile));
            }
            catch(ParseException $e)
            {
                throw new Exception('Yaml parser error: '.$e->getMessage(), $e->getCode());
            }
            foreach($aContent as $k=>$v)
                $ret[$k] = $v;
        }

        return $ret;
    }
}