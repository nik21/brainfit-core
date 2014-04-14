<?php

namespace Brainfit;

use Brainfit\Io\Data\Config;
use Brainfit\Model\Exception;

class Settings
{
    private static $aConfigurationFiles = null;

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

        $ret = Config::get(self::$aConfigurationFiles);

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
