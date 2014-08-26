<?php
namespace Brainfit\Util\Helper;

class ObjectDeferred
{
    protected $sCurrentId = null;
    protected $sHash = null;
    private static $aPromises = [];
    private static $aCallables = [];
    private static $aResults = [];

    public function __construct($sId, callable $callable, $sHash, $sFieldName)
    {
        $this->sCurrentId = $sId;
        $this->callable = $callable;
        $this->sHash = $sHash;
        self::$aPromises[$sHash][] = $this->sCurrentId;
        self::$aCallables[$sHash] = $callable;
    }

    private function resolve()
    {
        $obCallable = self::$aCallables[$this->sHash];

        $aTemp = $obCallable(self::$aPromises[$this->sHash]);
        self::$aResults[$this->sHash] = [];

        foreach($aTemp as $k=>$obItem)
        {
            self::$aResults[$this->sHash][is_object($obItem) ? $obItem->getCurrentId() : $k] = $obItem;
        }
    }

    public function get()
    {
        if (is_null(self::$aResults[$this->sHash]))
            $this->resolve();

        $v = self::$aResults[$this->sHash][$this->sCurrentId];
        return is_object($v) ? $v->get() : $v;
    }
}