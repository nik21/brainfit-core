<?php
namespace Brainfit\Api;

class MethodFactory
{
    /**
     * @param $sNamespace
     * @param $sMethodName
     *
     * @return bool
     */
    public static function get($sNamespace, $sMethodName)
    {
        $aNewInstanceName = [];

        if(!$sMethodName || !preg_match("/^[-a-zA-Z0-9\.]+$/", $sMethodName))
            return false; //throw new Exception('Invalid method name: "' . $sMethodName . '"', 0);

        foreach(explode('.', $sMethodName) as $sItem)
            $aNewInstanceName[] = ucfirst(mb_convert_case($sItem, MB_CASE_LOWER));

        $sClassName = $sNamespace.'\\Api\\Method\\'.implode('\\', $aNewInstanceName);

        if(!class_exists($sClassName))
            return false;

        return new $sClassName();
    }
}