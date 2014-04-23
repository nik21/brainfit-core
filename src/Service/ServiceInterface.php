<?php
namespace Brainfit\Service;

use React\EventLoop\LoopInterface;

interface ServiceInterface
{
    public function execute(LoopInterface $loop);
}
