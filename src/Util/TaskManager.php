<?php
namespace Brainfit\Util;

use Brainfit\Api\MethodWrapper;
use Brainfit\Io\Input\InputFake;
use Brainfit\Model\Exception;
use Brainfit\Service\ServiceFactory;

/**
 * Class TaskManager
 * @package Brainfit\Util
 *
 * Process create new php-processes for run methods from Api/Method namesapces.
 * Do this using doBackground method in /Brainfit/Io/TaskManager class
 */
class TaskManager
{
    private $sHost = '127.0.0.1';
    private $iPort = 4000;
    private $aProcesses = array();
    private $sCwd = null;
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
        $bClientMode = (bool)$aOptions['c'];

        if(!$this->sHost)
            $this->sHost = '127.0.0.1';
        if(!$this->iPort)
            $this->iPort = 4000;

        $this->loop = \React\EventLoop\Factory::create();

        if($bClientMode)
            $this->executeChildMode();
        else
        {
            $this->shortTest();
            $this->executeServiceMethods();
            $this->executeMaster();
        }

        $this->loop->run();
    }

    private function executeChildMode()
    {
        list($empty, $sHashSum, $sRawData) = self::parseTaskHeader(self::readStdinData());

        if(md5($sRawData) != $sHashSum)
            throw new Exception('Child: Invalid header on request: '.$sHashSum. ' != '.md5($sRawData)."\n");

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

    private static function getBlock($iBlockSize)
    {
        $iBlockSize = (int)$iBlockSize;
        if($iBlockSize <= 0)
            throw new Exception('getBlock error');

        return fread(STDIN, $iBlockSize);
    }


    ////
    private static function parseTaskHeader($sData)
    {
        $iLength = (int)substr($sData, 0, 8);
        $sHashSum = substr($sData, 8 + $iLength, 32);
        $sRawData = substr($sData, 8, $iLength);

        return [$iLength, $sHashSum, $sRawData];
    }

    private function executeMaster()
    {
        $socket = new \React\Socket\Server($this->loop);

        $conns = new \SplObjectStorage();

        $socket->on('connection', function ($conn) use ($conns)
        {
            //$conns->attach($conn);

            $sConcatData = '';
            $conn->on('data', function ($sData) use (&$sConcatData)
            {
                $sConcatData .= $sData;
            });

            $conn->on('end', function () use ($conns, $conn, &$sConcatData)
            {
                //$data containts:
                //data size (json-string) in dec value (8 bytes)
                //JSON-object with fields "id", "params", "method" or "id", "action"
                //md5 checksum of JSON

                //Check signature
                list($empty, $sHashSum, $sRawData) = self::parseTaskHeader($sConcatData);

                if(md5($sRawData) != $sHashSum)
                {
                    fwrite(STDERR, 'Invalid header on request: '.$sRawData."\n");

                    return;
                }

                $aData = json_decode($sRawData, true);
                $sTaskId = trim($aData['id']);

                if(!$sTaskId)
                {
                    fwrite(STDERR, 'Invalid task id: '.$sRawData."\n");

                    return;
                }

                $sTaskId = sha1($sTaskId);

                if($aData['action'] == 'kill')
                    $this->killProcess($sTaskId);
                elseif($aData['action'] == 'check')
                    $this->checkProcess($sTaskId);
                else
                    $this->createProcess($sConcatData, $sTaskId);

                //$conns->detach($conn);
            });

            /**
             * foreach ($conns as $current) {
             * if ($conn === $current) {
             * continue;
             * }
             *
             * $current->write($conn->getRemoteAddress().': ');
             * $current->write($sData);
             * }
             */
        });

        echo "Socket server listening on port {$this->iPort} host {$this->sHost}\n";

        $socket->listen($this->iPort, $this->sHost);
    }

    private function checkProcess($sTaskId)
    {
        return isset($this->aProcesses[$sTaskId]);
    }

    private function killProcess($sTaskId)
    {
        if(!isset($this->aProcesses[$sTaskId]))
            return false;

        $this->aProcesses[$sTaskId]->close();
        unset($this->aProcesses[$sTaskId]);

        return true;
    }

    private function createProcess($sData, $sTaskId)
    {
        //Now create the process and pass it on STDIN data
        $process = new \React\ChildProcess\Process('php daemon.php -c 1', $this->sCwd);

        $sPid = 'Unknown';

        $process->on('exit', function ($exitCode, $termSignal) use (&$sPid, &$sTaskId)
        {
            unset($this->aProcesses[$sTaskId]);

            echo "{$sPid}\tChild exit\n";
        });

        $this->loop->addTimer(0.001, function ($timer) use ($process, &$sPid, &$sData, &$sTaskId)
        {
            $process->start($timer->getLoop());
            $sPid = $process->getPid();
            $this->aProcesses[$sTaskId] = $process;

            echo "{$sPid}\tBegin new process\n";

            for($i = 0; $i <= strlen($sData)-512; $i += 512)
                $process->stdin->write(substr($sData, $i, 512));

            if ($iLastBlock = strlen($sData)-$i)
                $process->stdin->write(substr($sData, $i, $iLastBlock), $iLastBlock);

            $process->stdin->end();

            $process->stdout->on('data', function ($output) use ($sPid)
            {
                if($output)
                    echo "{$sPid}\tMessage from child: {$output}\n";
            });

            $process->stderr->on('data', function ($output) use ($sPid)
            {
                if($output)
                    echo "{$sPid}\tError from child: {$output}\n";
            });
        });
    }

    /**
     * Scan "Service" folder and execute all methods
     */
    private function executeServiceMethods()
    {
        $aFiles = scandir(ROOT.'/engine/Service/');
        foreach($aFiles as $sFile)
        {
            if ($sFile == '.' || $sFile == '..')
                continue;

            $sClassName = str_replace('.php', '', $sFile);
            $obClass = ServiceFactory::create($sClassName);
            $obClass->execute($this->loop);
        }
    }

    private function shortTest()
    {
        $sProblem = '';

        $aData = apc_cache_info();
        if (!$aData['stime'])
            $sProblem = "Need add \"apc.enable_cli\" option\n";

        if (!$sProblem)
            return;

        throw new Exception($sProblem);
    }
}