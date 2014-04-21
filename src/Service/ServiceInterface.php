<?php
namespace Brainfit\Service;

interface ServiceInterface
{
    public function getTime();
    public function execute($loop);
}
