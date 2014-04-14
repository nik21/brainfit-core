<?php
namespace Controller;

use Model\Exception;

class Factory
{
    /**
     * @param $className
     *
     * @throws \Model\Exception
     * @internal param string $methodName
     * @return PageInterface
     */
    public static function create($className)
    {
        $sClassName = __NAMESPACE__.'\\'.$className;

        if(!class_exists($sClassName))
            throw new Exception('Class not found: '.$sClassName);

        return new $sClassName();
    }
}
