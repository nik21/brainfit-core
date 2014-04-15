<?php
namespace Brainfit\Util;

class Debugger
{
    private static $aBuffer = [];

    public static function log()
    {
        $iNumArgs = func_num_args();

        $message = '';
        for($i=0;$i<$iNumArgs;$i++){
            $sCurMessage = func_get_arg($i);

            if (is_array($sCurMessage) || is_object($sCurMessage))
                $sCurMessage = print_r($sCurMessage, true);

            $message .= $sCurMessage . "\t";
        }
        $message .= "\n";

        if (!$message)
            return;

        $cur_log_file = ROOT . '/logs/' . date("Y/m/d") . '.log';

        $arr1 = mb_split('/', str_replace('//', '/', $cur_log_file));
        array_pop($arr1);
        @mkdir(join('/', $arr1), 0755, true);

        file_put_contents($cur_log_file, date("Y-m-d H:i:s u") . "\t" . $message, FILE_APPEND+LOCK_EX);
    }

    public static function clientLog($sSection, $val1)
    {
        $iNumArgs = func_num_args();

        $message = [];
        $sSection = func_get_arg(0);

        for($i=1;$i<$iNumArgs;$i++){
            $sCurMessage = func_get_arg($i);

            if (is_object($sCurMessage))
                $sCurMessage = (array)$sCurMessage;

            if (!is_null($sCurMessage))
                $message[] = $sCurMessage;
        }

        if ($message)
            self::$aBuffer[$sSection][] = $message;
    }

    public static function getClientLogBuffer()
    {
        $aData = self::$aBuffer;

        self::$aBuffer = [];

        return $aData;
    }
}