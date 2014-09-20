<?php
namespace Brainfit\Controller;

use Brainfit\Controller\ControllerInterface;
use Exception;

class ControllerFactory
{
    /**
     * @param $sNamespace
     * @param $className
     *
     * @throws Exception
     * @internal param string $methodName
     * @return ControllerInterface
     */
    public static function create($sNamespace, $className)
    {
        $sClassName = $sNamespace.'\\Controller\\'.$className;

        if(!class_exists($sClassName))
            throw new Exception('Class not found: '.$sClassName);

        return new $sClassName();
    }
}
