<?php
namespace Controller;

use Io\Input\InputInterface;
use Io\Output\OutputInterface;

interface PageInterface
{
    /**
     * @param \Io\Input\InputInterface $obObInput
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