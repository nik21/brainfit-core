<?php
namespace Brainfit\Service;

use Exception;

class ServiceFactory
{
    /**
     * @param $className
     *
     * @return ServiceInterface
     * @throws \Exception
     */
    public static function create($className)
    {
        $sClassName = '\\Service\\'.$className;

        if(!class_exists($sClassName))
            throw new Exception('Class not found: '.$sClassName);

        return new $sClassName();
    }
}
