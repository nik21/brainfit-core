<?php
namespace Brainfit\Api;

use Brainfit\Api\Security\SecurityInterface;
use Brainfit\Model\Exception;

class SecurityFactory
{
    /**
     * Создать экземпляр метода
     *
     * @param $sClassName
     * @throws Exception
     * @internal param $sMethodName
     *
     * @internal param string $methodName
     *
     * @return SecurityInterface
     */
    public static function get($sClassName)
    {
        $sClassName = '\\Api\\Security\\Security'.ucfirst($sClassName);

        if(!class_exists($sClassName))
            throw new Exception('Security class not found: "'.$sClassName);

        $obSecurity = new $sClassName();

        return new $obSecurity();
    }
}