<?php

namespace Io\Data;

class Config
{
    const KEY = 'config';

    private static $aConfig;

    public static function get($sIdentificator, $sFile1 = '', $sFile2 = '', $sFileN = '')
    {
        $aFiles = array();
        for($i = 1; $i < func_num_args(); $i++)
            $aFiles[] = func_get_arg($i);

        if($sIdentificator === false)
            return self::getConfig($aFiles);

        $sIdentificator = self::KEY.$sIdentificator;

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