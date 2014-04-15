<?php
namespace Brainfit\Controller;

use Brainfit\Io\Input\InputInterface;
use Brainfit\Io\Output\OutputInterface;

interface ControllerInterface
{
    /**
     * @param InputInterface $obObInput
     * @return mixed
     */
    public function init(InputInterface $obObInput);

    /**
     * @return mixed
     */
    public function check();

    /**
     * @param OutputInterface $obOutput
     * @return mixed
     */
    public function execute(OutputInterface $obOutput);
}