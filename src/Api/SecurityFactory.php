<?php
namespace Brainfit\Api;

class SecurityFactory
{
    /**
     * @param $sClassName
     * @return bool
     */
    public static function get($sClassName)
    {
        $sClassName = '\\Api\\Security\\Security'.ucfirst($sClassName);

        if(!class_exists($sClassName))
            return false;

        $obSecurity = new $sClassName();

        return new $obSecurity();
    }
}