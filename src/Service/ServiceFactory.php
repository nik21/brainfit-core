<?php
namespace Brainfit\Service;

class ServiceFactory
{
    /**
     * @param $className
     * @return bool
     */
    public static function get($className)
    {
        $sClassName = '\\Service\\'.$className;

        if(!class_exists($sClassName))
            return false;

        return new $sClassName();
    }
}
