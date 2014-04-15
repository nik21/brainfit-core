<?php
namespace Brainfit\Api\Security;

use Brainfit\Io\Input\InputInterface;

interface SecurityInterface
{
    public function check(InputInterface $input);
}