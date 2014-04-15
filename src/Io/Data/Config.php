<?php

namespace Brainfit\Io\Data;

use Brainfit\Model\Exception;

class Config
{
    const KEY = 'config';

    private static $aConfig;

    public static function get($aFiles)
    {
        $sIdentificator = '';
        foreach($aFiles as $sFilename)
        {
            $sMd5 = md5_file($sFilename);
            if (!$sMd5)
                throw new Exception('Invalid MD5 checksum for file '.$sFilename);

            $sIdentificator .= $sMd5.'+';
        }

        $sIdentificator = self::KEY.sha1($sIdentificator);

        if(isset(self::$aConfig[$sIdentificator]))
            return self::$aConfig[$sIdentificator];


        if(!apc_exists($sIdentificator))
        {
            self::$aConfig[$sIdentificator] = self::getConfig($aFiles);
            apc_store($sIdentificator, self::$aConfig[$sIdentificator]);
        }
        else
            self::$aConfig[$sIdentificator] = apc_fetch($sIdentificator);

        return self::$aConfig[$sIdentificator];
    }

    private static function getConfig($aFiles)
    {
        $aContent = array();
        $parser = new \sfYamlParser();

        foreach($aFiles as $sFile)
            $aContent[] = $parser->parse(file_get_contents($sFile));

        return call_user_func_array('array_replace_recursive', $aContent);
    }
}