<?php
namespace Brainfit\Api;

class SecurityFactory
{
    /**
     * @param $sNamespace
     * @param $sClassName
     *
     * @return bool
     */
    public static function get($sNamespace, $sClassName)
    {
        $sClassName = $sNamespace.'\\Api\\Security\\Security'.ucfirst($sClassName);

        if(!class_exists($sClassName))
            return false;

        $obSecurity = new $sClassName();

        return new $obSecurity();
    }
}