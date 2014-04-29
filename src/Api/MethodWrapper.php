<?php
namespace Brainfit\Api;

use Brainfit\Io\Input\InputInterface;
use Brainfit\Io\Output\OutputJson;
use Brainfit\Model\Exception;

class MethodWrapper
{
    /**
     * @param $sMethodName
     * @param InputInterface $obInput
     * @throws \Brainfit\Model\Exception
     * @return bool|OutputJson
     */
    public static function execute($sMethodName, InputInterface $obInput)
    {
        $obOutput = new OutputJson();

        $obMethod = MethodFactory::get($sMethodName);
        if(!$obMethod)
            throw new Exception('Method not found: '.$sMethodName);

        $obSecurity = SecurityFactory::get($obMethod->getSecurityMethod());
        if(!$obSecurity)
            throw new Exception('Security type not found');

        $obSecurity->check($obInput);

        $obMethod->init($obInput);
        if($obMethod->check() === false)
            return false;

        $obMethod->execute($obOutput);

        return $obOutput;
    }
}