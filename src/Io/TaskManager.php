<?php

namespace Brainfit\Io;

use Brainfit\Model\Exception;

class TaskManager
{
    public static function doBackground($sJobName, $workload, $sServer = null, $sUniqueId = null, $iTimeout = 10)
    {
        $sUniqueId = trim($sUniqueId);
        $sTransactionId = $sUniqueId ? $sUniqueId : sha1($sJobName . '+' . $sUniqueId);

        $aData = [
            'id' => $sTransactionId,
            'params' => is_array($workload) ? $workload : json_decode($workload, true),
            'method' => $sJobName
        ];

        if(self::send($sServer, $iTimeout, $aData) !== false)
            return $sTransactionId;
        else
            return false;
    }

    public static function check($sServer, $iTimeout = 10)
    {
        $aData = self::send($sServer, $iTimeout, ['action' => 'ping']);

        return isset($aData['result']);
    }

    /**
     * @param $sUniqueId
     * @param null $sServer
     * @param int $iTimeout
     *
     * @return bool|int
     * @throws \Brainfit\Model\Exception
     *
     * Return 1 if process exist. 0 if process is not exist. false otherwise
     */
    public static function jobStatus($sUniqueId, $sServer = null, $iTimeout = 10)
    {
        $sUniqueId = trim($sUniqueId);
        if(!$sUniqueId)
            throw new Exception('Invalid unique id');

        $aData = [
            'id' => $sUniqueId,
            'action' => 'check'
        ];

        $aAnswer = self::send($sServer, $iTimeout, $aData);
        return isset($aAnswer['status']) ? (int)$aAnswer['status'] : false;
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

        return self::send($sServer, $iTimeout, $aData) !== false;
    }

    /**
     * @param $sServer
     * @param $iTimeout
     * @param $aData
     *
     * @return bool|array
     * @throws \Brainfit\Model\Exception
     */
    private static function send($sServer, $iTimeout, $aData)
    {
        //Prepare data
        $sRaw = json_encode($aData);
        $sSign = md5($sRaw);
        $sLen = (string)strlen($sRaw);
        $sLen = str_repeat('0', 8 - strlen($sLen)) . $sLen;
        $data = $sLen . $sRaw . $sSign . "\n";

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

        $obHandle = fsockopen($sHost, $iPort, $errno, $errstr, $iTimeout);
        stream_set_timeout($obHandle, $iTimeout);

        if(!$obHandle)
            return false;

        //Send data
        for ($i = 0; $i <= strlen($data) - 512; $i += 512)
            fwrite($obHandle, substr($data, $i, 512), 512);

        if($iLastBlock = strlen($data) - $i)
            fwrite($obHandle, substr($data, $i, $iLastBlock), $iLastBlock);

        //waiting
        $sRawAnswer = fgets($obHandle);

        fclose($obHandle);

        $aInfo = false;
        if ($sRawAnswer)
            $aInfo = json_decode($sRawAnswer, true);

        return $aInfo;
    }
}