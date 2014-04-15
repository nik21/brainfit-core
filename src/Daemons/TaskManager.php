<?php
namespace Brainfit\Daemons;

use Brainfit\Api\MethodWrapper;
use Brainfit\Io\Input\InputFake;
use Brainfit\Model\Exception;

class TaskManager
{
    private $sHost = '127.0.0.1';
    private $iPort = 4000;

    private $sCwd = null;

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

        if (!$this->sHost)
            $this->sHost = '127.0.0.1';
        if (!$this->iPort)
            $this->iPort = 4000;

        if ($bClientMode)
            $this->executeChildMode();
        else
            $this->executeMaster();
    }

    private function executeChildMode()
    {
        list($empty, $sHashSum, $sRawData) = self::parseTaskHeader(self::readStdinData());

        if (md5($sRawData) != $sHashSum)
            throw new Exception('Child: Invalid header on request: '.$sRawData."\n");

        //Разбираем данные
        $aData = json_decode($sRawData, true);
        $sTaskId = (string)$aData['id'];
        $aParams = (array)$aData['params'];
        $sMethod = (string)$aData['method'];

        if (!$sTaskId || !$sMethod)
            throw new Exception('The task does not contain data: '.$sRawData);

        //Ip alreade needed
        if (!isset($aParams['ip']))
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
        foreach(self::getBlock($iBlockSize) as $sLine)
        {
            $iReadSize += $iBlockSize;

            if (is_null($sData) && strlen($sLine) <= 8)
                throw new Exception('Very small header');

            if ($iReadSize >= 16*1024*1024)
                throw new Exception('Too much data: >16Mb');

            if (is_null($sData))
                $sData = '';

            $sData .= $sLine;


            $iLength = (int)substr($sData, 0, 8);
            if (!$iLength)
                throw new Exception('Invalid data header');
            else
                $iLength += 32;

            if (strlen($sData) >= $iLength)
                break;
        }

        return $sData;
    }

    private static function getBlock($iBlockSize)
    {
        $iBlockSize = (int)$iBlockSize;
        if ($iBlockSize <= 0)
            throw new Exception('getBlock error');

        while(-1)
            yield fread(STDIN, $iBlockSize);
    }



    ////
    private static function parseTaskHeader($sData)
    {
        $iLength = (int)substr($sData, 0, 8);
        $sHashSum = substr($sData, 8+$iLength, 32);
        $sRawData = substr($sData, 8, $iLength);

        return [$iLength, $sHashSum, $sRawData];
    }

    private function executeMaster()
    {
        $loop = \React\EventLoop\Factory::create();

        $socket = new \React\Socket\Server($loop);

        $conns = new \SplObjectStorage();

        $socket->on('connection', function ($conn) use ($conns, $loop) {
            //$conns->attach($conn);

            $sConcatData = '';
            $conn->on('data', function ($sData) use (&$sConcatData) {
                $sConcatData .= $sData;
            });

            $conn->on('end', function () use ($conns, $conn, &$sConcatData, $loop) {
                //$data containts:
                //data size (json-string) in dec value (8 bytes)
                //JSON-object with fields "id", "params", "method"
                //md5 checksum of JSON

                //Check signature
                list($empty, $sHashSum, $sRawData) = self::parseTaskHeader($sConcatData);

                if (md5($sRawData) != $sHashSum)
                    fwrite(STDERR, 'Invalid header on request: '.$sRawData."\n");
                else
                    $this->createProcess($loop, $sConcatData);

                //$conns->detach($conn);
            });

            /**
             * foreach ($conns as $current) {
            if ($conn === $current) {
            continue;
            }

            $current->write($conn->getRemoteAddress().': ');
            $current->write($sData);
            }
             */
        });

        echo "Socket server listening on port {$this->iPort} host {$this->sHost}\n";

        $socket->listen($this->iPort, $this->sHost);

        $loop->run();
    }

    private function createProcess($loop, $sData)
    {
        //Now create the process and pass it on STDIN data
        $process = new \React\ChildProcess\Process('php daemon.php -c 1', $this->sCwd);

        $sPid = 'Unknown';

        $process->on('exit', function($exitCode, $termSignal) use (&$sPid) {
            echo "{$sPid}\tChild exit\n";
        });

        $loop->addTimer(0.001, function($timer) use ($process, &$sPid, &$sData) {
            $process->start($timer->getLoop());
            $sPid = $process->getPid();
            echo "{$sPid}\tBegin new process\n";

            for($i=0; $i<=strlen($sData); $i+=512)
                $process->stdin->write(substr($sData, $i, 512));

            $process->stdin->end();

            $process->stdout->on('data', function($output) use($sPid) {
                if ($output)
                    echo "{$sPid}\tMessage from child: {$output}\n";
            });

            $process->stderr->on('data', function($output) use($sPid) {
                if ($output)
                    echo "{$sPid}\tError from child: {$output}\n";
            });
        });
    }

}





