<?php
namespace Brainfit\Api;

use Brainfit\Io\Input\InputInterface;
use Brainfit\Io\Output\OutputJson;

class MethodWrapper
{
    /**
     * @param $sMethodName
     * @param InputInterface $obInput
     * @return bool|OutputJson
     */
    public static function execute($sMethodName, InputInterface $obInput)
    {
        $obOutput = new OutputJson();

        $obMethod = MethodFactory::get($sMethodName);
        $obSecurity = SecurityFactory::get($obMethod->getSecurityMethod());

        $obSecurity->check($obInput);

        $obMethod->init($obInput);
        if($obMethod->check() === false)
            return false;

        $obMethod->execute($obOutput);

        return $obOutput;
    }
}