<?php
namespace Brainfit\Api\Method;


use Brainfit\Io\Input\InputInterface;
use Brainfit\Io\Output\OutputInterface;

interface MethodInterface
{
    public function getSecurityMethod();
    public function init(InputInterface $input);
    public function check();
    public function execute(OutputInterface $obOutput);
}
