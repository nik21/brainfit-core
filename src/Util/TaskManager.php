<?php

namespace Brainfit\Util;

use Brainfit\Api\MethodWrapper;
use Brainfit\Io\Input\InputFake;
use Brainfit\Model\Exception;
use Brainfit\Settings;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;

/**
 * Class TaskManager
 * @package Brainfit\Util
 *
 * Daemon create and manage new php-processes for execute api-methods.
 * Using doBackground method in /Brainfit/Io/TaskManager class
 */
class TaskManager
{
    const CONNECTION_TIMEOUT = 30;

    private $sHost = '127.0.0.1';
    private $iPort = 4000;
    private static $bDebug = false;
    private $sCurrentFile = '';

    /**
     * @var Process[]
     */
    private $aProcesses = [];
    private $sCwd = null;

    /**
     * @var LoopInterface
     */
    private $loop;

    /**
     * @internal param h — host
     * @internal param p — port
     * @internal param c — client mode
     *
     * @param $sFile
     * @param $aOptions
     * @throws Exception
     */
    public function run($sFile, $aOptions)
    {
        $this->sCurrentFile = $sFile;
        $this->sCwd = getcwd();

        $this->sHost = $aOptions['h'];
        $this->iPort = (int)$aOptions['p'];
        $iClientMode = (int)$aOptions['c'];
        self::$bDebug = $aOptions['d'] ? true : false;

        if(!$this->sHost || $this->iPort <= 0)
        {
            $this->sHost = Settings::get('TASK_DAEMON', 'host');
            $this->iPort = (int)Settings::get('TASK_DAEMON', 'port');
        }

        if(!$this->sHost || $this->iPort <=0)
            throw new Exception('Invalid host or port');

        if($iClientMode == 1)
        {
            //Call API method
            $this->executeChildMode();
        }
        else
        {
            $this->loop = \React\EventLoop\Factory::create();


            $this->bindSignals();
            $this->loop->addPeriodicTimer(2, function(){
                pcntl_signal_dispatch();
            });



            if($iClientMode == 2)
                $this->executeServiceMethods();
            else
            {
                $this->createProcess('', 'services_master', 2);

                //Start listener
                $this->executeMaster();
            }

            $this->loop->run();
        }
    }

    private function bindSignals()
    {
        pcntl_signal(SIGTERM, [$this, "sigHandler"]);
        pcntl_signal(SIGINT, [$this, "sigHandler"]);
    }

    public function sigHandler()
    {
        foreach(array_keys($this->aProcesses) as $sProcessName)
            $this->killProcess($sProcessName);

        exit;
    }

    private function executeChildMode()
    {
        list(, $sHashSum, $sRawData) = self::parseTaskHeader(self::readStdinData());

        if(md5($sRawData) != $sHashSum)
            throw new Exception('Child: Invalid header on request: '.$sHashSum.' != '.md5($sRawData)."\n");

        $aData = json_decode($sRawData, true);
        $sTaskId = (string)$aData['id'];
        $aParams = (array)$aData['params'];
        $sMethod = (string)$aData['method'];
        $sNamespace = (string)$aData['namespace'];

        if(!$sTaskId || !$sMethod)
            throw new Exception('The task does not contain data: '.$sRawData);

        //Ip already needed
        if(!isset($aParams['ip']))
            $aParams['ip'] = '127.0.0.1';

        //Execute API method
        $obInput = new InputFake($aParams);

        $obOutput = MethodWrapper::execute($sNamespace, $sMethod, $obInput);

        if (self::$bDebug)
            echo($obOutput->get()."\n");
    }

    private static function parseTaskHeader($sData)
    {
        $iLength = (int)substr($sData, 0, 8);
        $sHashSum = substr($sData, 8 + $iLength, 32);
        $sRawData = substr($sData, 8, $iLength);

        return [$iLength, $sHashSum, $sRawData];
    }

    private static function readStdinData()
    {
        $iBlockSize = 512;
        $sData = null;
        $iReadSize = 0;

        //Read as there are data
        while(-1)
        {
            $sLine = self::getBlock($iBlockSize);

            $iReadSize += $iBlockSize;

            if(is_null($sData) && strlen($sLine) <= 8)
                throw new Exception('Very small header');

            if($iReadSize >= 16 * 1024 * 1024)
                throw new Exception('Too much data: >16Mb');

            if(is_null($sData))
                $sData = '';

            $sData .= $sLine;


            $iLength = (int)substr($sData, 0, 8);
            if(!$iLength)
                throw new Exception('Invalid data header');
            else
                $iLength += 40; //32 md5 and 8 — header length

            if(strlen($sData) >= $iLength)
                break;
        }

        return $sData;
    }


    ////

    private static function getBlock($iBlockSize)
    {
        $iBlockSize = (int)$iBlockSize;
        if($iBlockSize <= 0)
            throw new Exception('getBlock error');

        return fread(STDIN, $iBlockSize);
    }

    /**
     * Scan "Service" folder and execute all methods as new process
     */
    private function executeServiceMethods()
    {
        $aServices = Settings::get('SERVICES');
        $sUniqueSalt = date('U');

        foreach($aServices as $sNamespace=>$aServicesItems)
        {
            $this->loop->addPeriodicTimer(0.1, function() use (&$aServicesItems, $sUniqueSalt, $sNamespace){
                foreach($aServicesItems as $sMethodName => &$aParams)
                {
                    $iTime = intval(microtime(true) * 1000);
                    $iHour = date('G');

                    $sTaskHash = sha1(implode('+', [$sUniqueSalt, $sMethodName]));

                    if(isset($aParams['interval']))
                    {
                        //If you need to perform at intervals, then check to see whether early to perform
                        if(isset($aParams['prevExecute']) && $aParams['prevExecute'] + $aParams['interval'] > $iTime)
                            continue;

                    }
                    else if(isset($aParams['hours']))
                    {
                        //If you need to perform on the clock, then check whether it is time to perform
                        if(isset($aParams['prevExecuteHour']) && ($aParams['prevExecuteHour'] == $iHour
                                || !in_array($iHour, (array)$aParams['hours']))
                        )
                            continue;

                    }
                    else
                    {
                        if (isset($aParams['isOnce']))
                            continue;

                        $aParams['isOnce'] = true;
                    }

                    //Time to perform, if not performed
                    if(\Brainfit\Io\TaskManager::jobStatus($sTaskHash, '127.0.0.1', 5))
                        continue;

                    $aParams['prevExecute'] = $iTime;
                    $aParams['prevExecuteHour'] = $iHour;

                    \Brainfit\Io\TaskManager::doBackground(
                        $sNamespace,
                        $sMethodName,
                        [],
                        '127.0.0.1',
                        $sTaskHash,
                        5
                    );
                }
            });
        }

    }

    private function executeMaster()
    {
        $socket = new \React\Socket\Server($this->loop);

        $socket->on('connection', function ($conn)
        {
            /** @var Connection $conn */

            $sConnectionId = Math::createID();
            $sRemoteAddress = $conn->getRemoteAddress();

            self::log($sConnectionId, "Connect client ".$sRemoteAddress);

            if(!Network::isTrustInternalAddress($sRemoteAddress))
            {
                self::log($sConnectionId, "Reject not trusted connection");
                $conn->end();

                return;
            }

            $sTaskRawData = '';
            $run = function () use (&$sTaskRawData, $sConnectionId, $conn)
            {
                //$data contains:
                //data size (json-string) in dec value (8 bytes)
                //JSON-object with fields "id", "params", "method" or "id", "action"
                //md5 checksum of JSON
                //\0 byte

                //Check signature
                list(, $sHashSum, $sRawData) = self::parseTaskHeader($sTaskRawData);

                if(md5($sRawData) != $sHashSum)
                {
                    self::log($sConnectionId, 'Invalid request header: "'.$sTaskRawData.'"');
                    $conn->end();

                    return;
                }

                $aData = json_decode($sRawData, true);
                $sTaskId = trim($aData['id']);
                $sAction = $aData['action'];

                if(!$sTaskId && !$sAction)
                {
                    self::log($sConnectionId, 'Invalid task id: "'.$sTaskRawData.'"');
                    $conn->end();

                    return;
                }

                $sTaskId = sha1($sTaskId);
                $aResult = [];

                if($sAction == 'kill')
                    $aResult['result'] = $this->killProcess($sTaskId);
                elseif($sAction == 'check')
                    $aResult['status'] = $this->getProcessStatus($sTaskId);
                elseif($sAction == 'ping')
                    $aResult['result'] = $this->ping();
                else
                    $aResult['result'] = $this->createProcess($sTaskRawData, $sTaskId);

                self::log($sConnectionId, "Send answer");

                $conn->write(json_encode($aResult)."\n");

                $conn->end();
            };


            $conn->on('data', function ($sData) use (&$sTaskRawData, $conn, $run)
            {
                $sTaskRawData .= $sData;
                $iEndPosition = strpos($sData, "\n");
                if($iEndPosition)
                    $run();
            });

            $conn->on('end', function () use ($conn, &$sTaskRawData, $sConnectionId)
            {
                self::log($sConnectionId, "Close connection");
            });
        });

        self::log("Socket server listening on port {$this->iPort} host {$this->sHost}");

        $socket->listen($this->iPort, $this->sHost);
    }


    /**
     * @return bool
     */
    private static function log()
    {
        $message = '';
        for($i = 0; $i < func_num_args(); $i++)
            $message .= func_get_arg($i)." ";

        if (self::$bDebug)
            echo(trim($message)."\n");

        return true;
    }

    private function killProcess($sTaskId)
    {
        if(!isset($this->aProcesses[$sTaskId]))
            return false;

        $this->aProcesses[$sTaskId]->close();
        unset($this->aProcesses[$sTaskId]);

        return true;
    }

    private function getProcessStatus($sTaskId)
    {
        return isset($this->aProcesses[$sTaskId]) ? 1 : 0;
    }

    private function ping()
    {
        return true;
    }

    private function createProcess($sData, $sTaskId, $iType = 1)
    {
        //Now create the process and pass it on STDIN data
        $process = new \React\ChildProcess\Process($this->sCurrentFile.' -c '.$iType.' -h '.$this->sHost.' -p '
            .$this->iPort, $this->sCwd);

        $sPid = 'Unknown';

        $process->on('exit', function ($exitCode, $termSignal) use (&$sPid, &$sTaskId)
        {
            unset($this->aProcesses[$sTaskId]);

            self::log("{$sTaskId} Child exit {$sPid}");
        });

        $this->loop->addTimer(0.001, function ($timer) use ($process, &$sPid, &$sData, &$sTaskId)
        {
            $process->start($timer->getLoop());
            $sPid = $process->getPid();
            $this->aProcesses[$sTaskId] = $process;

            self::log("{$sTaskId} Begin new process {$sPid}");

            for($i = 0; $i <= strlen($sData) - 512; $i += 512)
                $process->stdin->write(substr($sData, $i, 512));

            if($iLastBlock = strlen($sData) - $i)
                $process->stdin->write(substr($sData, $i, $iLastBlock), $iLastBlock);

            $process->stdin->end();

            $process->stdout->on('data', function ($output) use ($sPid, $sTaskId)
            {
                if($output)
                    self::log("{$sTaskId} Message {$output}");
            });

            $process->stderr->on('data', function ($output) use ($sPid, $sTaskId)
            {
                if($output)
                    self::log("{$sTaskId} Error {$output}");
            });
        });

        return true;
    }
}