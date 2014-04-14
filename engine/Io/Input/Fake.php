<?php
namespace Io\Input;

use Util\Reflection\Singleton;

class Fake extends Post implements InputInterface
{
    use Singleton;

    public $buffer;

    function __construct($aData)
    {
        $this->buffer = $aData;
    }

    public function addParam($sName, $sValue)
    {
        $this->buffer[$sName] = $sValue;
    }
}
