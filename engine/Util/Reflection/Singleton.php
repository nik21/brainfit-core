<?php
namespace Util\Reflection;

trait Singleton
{
    static protected $oInstance = null;

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
}