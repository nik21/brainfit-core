<?php
namespace Brainfit\Util;

use Brainfit\Api\MethodWrapper;
use Brainfit\Io\Input\InputFake;
use Brainfit\Model\Exception;
use Brainfit\Service\ServiceFactory;
use React\ChildProcess\Process;
use React\EventLoop\LoopInterface;
use React\Socket\Connection;

/**
 * Class TaskManager
 * @package Brainfit\Util
 *
 * Process create new php-processes for run methods from Api/Method namesapces.
 * Do this using doBackground method in /Brainfit/Io/TaskManager class
 */
class TaskManager
{
    const CONNECTION_TIMEOUT = 30;

    private $sHost = '127.0.0.1';
    private $iPort = 4000;

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
     * @param $aOptions
     */
    public function run($aOptions)
    {
        $this->sCwd = getcwd();

        $this->sHost = $aOptions['h'];
        $this->iPort = (int)$aOptions['p'];
        $iClientMode = (int)$aOptions['c'];

        if(!$this->sHost || $this->sHost == 'localhost')
            $this->sHost = '127.0.0.1';
        if(!$this->iPort)
            $this->iPort = 4000;

        if($iClientMode == 1)
        {
            //Call API method
            $this->executeChildMode();
        }
        else
        {
            $this->loop = \React\EventLoop\Factory::create();

            if($iClientMode == 2)
            {
                //Call Service method
                $this->executeServiceMethod();
            }
            else
            {
                $this->shortTest();
                $this->executeServiceMethods();

                //Start listener
                $this->executeMaster();
            }

            $this->loop->run();
        }
    }

    private function executeServiceMethod()
    {
        list($empty, $empty, $sClassName) = self::parseTaskHeader(self::readStdinData());

        $obClass = ServiceFactory::create($sClassName);

        if(!$obClass)
            throw new Exception('Invalid class name');

        $obClass->execute($this->loop);
    }

    private function executeChildMode()
    {
        list($empty, $sHashSum, $sRawData) = self::parseTaskHeader(self::readStdinData());

        if(md5($sRawData) != $sHashSum)
            throw new Exception('Child: Invalid header on request: '.$sHashSum.' != '.md5($sRawData)."\n");

        //Разбираем данные
        $aData = json_decode($sRawData, true);
        $sTaskId = (string)$aData['id'];
        $aParams = (array)$aData['params'];
        $sMethod = (string)$aData['method'];

        if(!$sTaskId || !$sMethod)
            throw new Exception('The task does not contain data: '.$sRawData);

        //Ip alreade needed
        if(!isset($aParams['ip']))
            $aParams['ip'] = '127.0.0.1';

        //Execute API method
        $obInput = new InputFake($aParams);

        $obOutput = MethodWrapper::execute($sMethod, $obInput);

        fwrite(STDOUT, $obOutput->get());
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

    private function shortTest()
    {
        $sProblem = '';

        $aData = apc_cache_info();
        if(!$aData['stime'])
            $sProblem = "Need add \"apc.enable_cli\" option\n";

        if(!$sProblem)
            return;

        throw new Exception($sProblem);
    }

    /**
     * Scan "Service" folder and execute all methods as new process
     */
    private function executeServiceMethods()
    {
        $aFiles = scandir(ROOT.'/engine/Service/');
        foreach($aFiles as $sFile)
        {
            if($sFile == '.' || $sFile == '..')
                continue;

            $sClassName = str_replace('.php', '', $sFile);

            if(!ServiceFactory::create($sClassName))
                continue;

            //Run child proccess:
            $sSign = md5($sClassName);
            $sLen = (string)strlen($sClassName);
            $sLen = str_repeat('0', 8 - strlen($sLen)).$sLen;
            $data = $sLen.$sClassName.$sSign."\n";

            $this->createProcess($data, 'service_'.$sClassName, 2);
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

            /*$obTimer = $this->loop->addTimer(self::CONNECTION_TIMEOUT, function () use (
                &$conn, $sConnectionId, $sRemoteAddress
            )
            {
                self::log($sConnectionId, "Close idle connection " . $sRemoteAddress);
                $conn->end();
            });*/

            $sTaskRawData = '';


            $run = function () use (&$sTaskRawData, $sConnectionId, $conn)
            {
                //$data containts:
                //data size (json-string) in dec value (8 bytes)
                //JSON-object with fields "id", "params", "method" or "id", "action"
                //md5 checksum of JSON
                //\0 byte

                //Check signature
                list($empty, $sHashSum, $sRawData) = self::parseTaskHeader($sTaskRawData);

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
                /*if($obTimer)
                    $obTimer->cancel();*/

                self::log($sConnectionId, "Close connection");
            });
        });

        self::log("Socket server listening on port {$this->iPort} host {$this->sHost}");

        $socket->listen($this->iPort, $this->sHost);

        if($this->iPort != 4000 || $this->sHost != '127.0.0.1')
        {
            self::log("Socket server listening on port 4000 host 127.0.0.1");
            $socket->listen(4000, '127.0.0.1');
        }
    }

    private static function log($string1 = '', $string2 = '', $stringN = '')
    {
        $message = '';
        for($i = 0; $i < func_num_args(); $i++)
            $message .= func_get_arg($i)." ";

        fwrite(STDERR, trim($message)."\n");

        //Debugger::log($message);

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
        $process = new \React\ChildProcess\Process('php daemon.php -c '.$iType, $this->sCwd);

        $sPid = 'Unknown';

        $process->on('exit', function ($exitCode, $termSignal) use (&$sPid, &$sTaskId)
        {
            unset($this->aProcesses[$sTaskId]);

            self::log("{$sPid}\tChild exit");
        });

        $this->loop->addTimer(0.001, function ($timer) use ($process, &$sPid, &$sData, &$sTaskId)
        {
            $process->start($timer->getLoop());
            $sPid = $process->getPid();
            $this->aProcesses[$sTaskId] = $process;

            self::log("{$sPid}\tBegin new process");

            for($i = 0; $i <= strlen($sData) - 512; $i += 512)
                $process->stdin->write(substr($sData, $i, 512));

            if($iLastBlock = strlen($sData) - $i)
                $process->stdin->write(substr($sData, $i, $iLastBlock), $iLastBlock);

            $process->stdin->end();

            $process->stdout->on('data', function ($output) use ($sPid)
            {
                if($output)
                    self::log("{$sPid}\tMessage from child: {$output}");
            });

            $process->stderr->on('data', function ($output) use ($sPid)
            {
                if($output)
                    self::log("{$sPid}\tError from child: {$output}");
            });
        });

        return true;
    }
}