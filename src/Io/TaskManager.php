<?php

namespace Brainfit\Io;

use Brainfit\Model\Exception;

class TaskManager
{
    public static function doBackground($sJobName, $workload, $sServer = null, $sUniqueId = null, $iTimeout = 10)
    {
        $sUniqueId = trim($sUniqueId);
        $sTransactionId = $sUniqueId ? $sUniqueId : sha1($sJobName.'+'.$sUniqueId);

        $aData = [
            'id' => $sTransactionId,
            'params' => is_array($workload) ? $workload : json_decode($workload, true),
            'method' => $sJobName
        ];

        if(self::send($sServer, $iTimeout, $aData))
            return $sTransactionId;
        else
            return false;
    }

    public static function jobStatus($sUniqueId, $sServer = null, $iTimeout = 10)
    {
        $sUniqueId = trim($sUniqueId);
        if(!$sUniqueId)
            throw new Exception('Invalid unique id');

        $aData = [
            'id' => $sUniqueId,
            'action' => 'check'
        ];

        return self::send($sServer, $iTimeout, $aData);
    }

    public static function killTask($sUniqueId, $sServer = null, $iTimeout = 10)
    {
        $sUniqueId = trim($sUniqueId);
        if(!$sUniqueId)
            throw new Exception('Invalid unique id');

        $aData = [
            'id' => $sUniqueId,
            'action' => 'kill'
        ];

        return self::send($sServer, $iTimeout, $aData);
    }

    private static function send($sServer, $iTimeout, $aData)
    {
        //Prepare data
        $sRaw = json_encode($aData);
        $sSign = md5($sRaw);
        $sLen = (string)strlen($sRaw);
        $sLen = str_repeat('0', 8 - strlen($sLen)).$sLen;
        $data = $sLen.$sRaw.$sSign;

        //////////////
        list($sHost, $iPort) = explode(':', (string)$sServer);

        $iPort = (int)$iPort;
        if(!$iPort)
            $iPort = 4000;

        if(!$sHost)
            $sHost = '127.0.0.1';

        $iTimeout = (int)$iTimeout;
        if($iTimeout < 1)
            throw new Exception('Low timeout');

        $fp = fsockopen($sHost, $iPort, $errno, $errstr, $iTimeout);

        if(!$fp)
            throw new Exception('Error: '.$errstr, $errno);

        //Send data
        for($i = 0; $i <= strlen($data)-512; $i += 512)
            fwrite($fp, substr($data, $i, 512), 512);

        if ($iLastBlock = strlen($data)-$i)
            fwrite($fp, substr($data, $i, $iLastBlock), $iLastBlock);


        //Not waiting for an answer
        fclose($fp);

        return true;
    }
}