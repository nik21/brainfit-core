<?php
namespace Brainfit\Io\Input;

use Brainfit\Util\Reflection\Singleton;

class InputFake extends InputPost implements InputInterface
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
