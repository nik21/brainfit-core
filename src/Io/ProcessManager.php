<?php
namespace Brainfit\Io;

use Brainfit\Util\Debugger;

class ProcessManager
{
    private static $aProcesses;

    public static function exec($cmd, $personalLogFilename = null, $iTimeout = 0)
    {
        if ($personalLogFilename)
        {
            $sLogfile = $personalLogFilename;

            if (file_exists($sLogfile))
                $sLogfile .= '.'.mt_rand(1,1000000);
        }
        else
            $sLogfile = ROOT.'/logs/temp-'.posix_getpid().'-'.mt_rand(1,1000000).'.log';

        //Debugger::log('Execute', $cmd, 'write temporary stdout/stderr file', $sLogfile);

        $pipes = array();
        $descriptors = array(
            1 => ['file', $sLogfile, 'a'], //stdout
            2 => ['file', $sLogfile, 'a'] //stderr
        );

        $p = proc_open($cmd, $descriptors, $pipes);
        $status = null;

        $iExitCode = 0;
        while (true) {
            sleep(1);

            $status = proc_get_status($p);
            if (!$status['running'])
            {
                $iExitCode = (int)$status['exitcode'];
                break;
            }

            //_log('Stell run. Child process pid:', $status['pid']);
        }

        proc_close($p);

        //Прикрепляем STDOUT в общий лог-файл, если не в персональный
        $sContent = file_get_contents($sLogfile);

        //Debugger::log('Execute complete. Last status', $status);

        if (is_null($personalLogFilename))
        {
            //Debugger::log('Report from stdout/stderr:'."\n".$sContent);

            unlink($sLogfile);
        }

        return $iExitCode;
    }


    /////////////////////////////////////

    public static function stop($sTaskId)
    {
        if (!self::isExist($sTaskId))
            return;
    }

    public static function isExist($sTaskId)
    {
        return isset(self::$aProcesses[$sTaskId]);
    }

    public static function getProcess($sTaskId)
    {
        if (!self::isExist($sTaskId))
            return false;

        return self::$aProcesses[$sTaskId];
    }

    public static function createProcess($loop, $sCommand, $sTaskId, callable $callback = null)
    {
        //Now create the process and pass it on STDIN data
        $process = new \React\ChildProcess\Process($sCommand);

        $sPid = 'Unknown';

        $process->on('exit', function ($exitCode, $termSignal) use (&$sPid, &$sTaskId)
        {
            unset(self::$aProcesses[$sTaskId]);
        });

        $loop->addTimer(0.001, function ($timer) use ($process, &$sPid, &$callback, &$sTaskId)
        {
            $process->start($timer->getLoop());
            $sPid = $process->getPid();

            if (!is_null($callback))
                $callback($process);
        });

        self::$aProcesses[$sTaskId] = $process;

        return $process;
    }
}