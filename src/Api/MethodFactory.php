<?php
namespace Brainfit\Api;

use Brainfit\Api\Method\MethodInterface;
use Brainfit\Model\Exception;

class MethodFactory
{
    /**
     * Создать экземпляр метода
     *
     * @param $sMethodName
     *
     * @throws Exception
     * @internal param string $methodName
     *
     * @return MethodInterface
     */
    public static function get($sMethodName)
    {
        $aNewInstanceName = [];

        if(!$sMethodName || !preg_match("/^[-a-zA-Z0-9\.]+$/", $sMethodName))
            throw new Exception('Invalid method name: "' . $sMethodName . '"', 0);

        foreach(explode('.', $sMethodName) as $sItem)
            $aNewInstanceName[] = ucfirst(mb_convert_case($sItem, MB_CASE_LOWER));

        $sClassName = '\\Api\\Method\\' . implode('\\', $aNewInstanceName);

        if(!class_exists($sClassName))
            throw new Exception('Method not found: "' . $sMethodName, 0);

        return new $sClassName();
    }
}