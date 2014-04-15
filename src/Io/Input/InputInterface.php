<?php
namespace Brainfit\Io\Input;

const VALIDATOR_STRING = 1;
const VALIDATOR_INTEGER = 2;
const VALIDATOR_BOOLEAN = 3;
const VALIDATOR_FLOAT = 4;
const VALIDATOR_TIMESTAMP = 5;
const VALIDATOR_IPADDRESS = 6;
const VALIDATOR_PHONE = 11;
const VALIDATOR_OR_NULL = 1000;
const VALIDATOR_BASE64 = 8;
const VALIDATOR_RAW = 9;
const VALIDATOR_INTEGER_POSITIVE = 7;
const VALIDATOR_CLIENT_TIME = 100;
const VALIDATOR_SESSION = 10;
const VALIDATOR_DATA = 20;

interface InputInterface
{
    public static function getInstance();

    public function getConcatParams($aExceptionsList = array());

    public function getParam($sName, $iParamType = VALIDATOR_STRING, $bObligatory = false, $iErrorCode = 0,
                             $sErrorDescription = '');

    public function getPath($element = null);
}