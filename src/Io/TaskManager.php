<?php

namespace Brainfit\Io;

use Brainfit\Model\Exception;

class TaskManager
{
    public static function doBackground($sJobName, $sWorkload, $sServer = null, $sUniqueId = null, $iTimeout = 10)
    {
        list($sHost, $iPort) = explode(':', (string)$sServer);

        $iPort = (int)$iPort;
        if (!$iPort)
            $iPort = 4000;

        if (!$sHost)
            $sHost = '127.0.0.1';

        $iTimeout = (int)$iTimeout;
        if ($iTimeout < 1)
            throw new Exception('Low timeout');

        $sTransactionId = $sUniqueId ? sha1($sJobName . '+' . $sUniqueId) : sha1($sJobName . '+' . $sWorkload);

        $t = [
            'id' => $sTransactionId,
            'params' => json_decode($sWorkload, true),
            'method' => $sJobName
        ];
        $sRaw = json_encode($t);
        $sSign = md5($sRaw);
        $sLen = (string)strlen($sRaw);
        $sLen = str_repeat('0', 8-strlen($sLen)).$sLen;

        $out = $sLen.$sRaw.$sSign;


        $fp = fsockopen($sHost, $iPort, $errno, $errstr, $iTimeout);

        if (!$fp)
            throw new Exception('Error: '.$errstr, $errno);

        //Send data
        for($i=0; $i<=strlen($out); $i+=512)
            fwrite($fp, substr($out,$i, 512), 512);

        //Not waiting for an answer
        fclose($fp);

        return $sTransactionId;
    }

    public static function jobStatus($sJobName, $sUniqueId, $sServer = null)
    {
        throw new Exception('Not implementation');
    }
}