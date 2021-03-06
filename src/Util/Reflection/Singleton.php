<?php
namespace Brainfit\Util\Reflection;

trait Singleton
{
    static private $oInstance = null;

    /**
     * @return $this
     */
    static public function getInstance()
    {
        if(isset(self::$oInstance) and (self::$oInstance instanceof self))
            return self::$oInstance;
        else
        {
            self::$oInstance = new self();

            return self::$oInstance;
        }
    }

    protected function __clone() {

    }

    static public function destruct()
    {
        self::$oInstance = null;
    }
}