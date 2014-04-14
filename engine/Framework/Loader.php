<?php

namespace Framework;

class Loader
{
    public static function autoload($class)
    {
        if($file = self::findFile($class))
        {
            require_once($file);

            return true;
        }
    }

    public static function findFile($sClassName)
    {
        $sFileName = ROOT.'/engine/';

        $aSplitter = explode('\\', $sClassName);
        $sClassName = array_pop($aSplitter);

        $sFileName .= implode('/', $aSplitter);
        $sFileName .= '/'.$sClassName;

        $sFileName .= '.php';

        if(!file_exists($sFileName))
            return false;

        return $sFileName;
    }
}

//If Loader used PHPUnit and ROOT is not installed
if(!defined('ROOT'))
    define('ROOT', getcwd().'/');

spl_autoload_register('\Framework\Loader::autoload');
