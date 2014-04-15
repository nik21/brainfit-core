<?php
namespace Brainfit\Model;

class Exception extends \Exception
{
    public $obError;

    public function __construct($message, $code = 0, $previous = null)
    {
        $this->obError = new \stdClass();

        $this->obError->code = (int)$code;
        $this->obError->message = $message ? $message : 'Unknown error';

        $this->obError->stack = debug_backtrace(false);

        parent::__construct((string)$message, (int)$code);
    }
}