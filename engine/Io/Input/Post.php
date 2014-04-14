<?php
namespace Io\Input;

use Model\Exception;
use Util\Reflection\Singleton;
use Util\Strings;

class Post implements InputInterface
{
    use Singleton;

    public $buffer;

    const ERROR_EMPTY_OBLIGATORY_ARGUMENT = 1;

    public function getPath($element = null)
    {
        throw new Exception('You should use the class "Router"');
    }

    function __construct()
    {
        //In $ _REQUEST should be required data
        $this->buffer = & $_REQUEST;
    }

    public function getConcatParams($aExceptionsList = array())
    {
        $allKeys = array_diff(array_keys($this->buffer), $aExceptionsList);
        $ret = array();

        foreach($allKeys as $curKey)
        {
            $ret[$curKey] = $this->getParam($curKey);
        }

        return $ret;
    }

    public function getParam($sName, $iParamType = VALIDATOR_STRING, $bObligatory = false, $iErrorCode = 0,
                             $sErrorDescription = '')
    {
        $bReturnNullIfEmpty = false;

        if($iParamType >= VALIDATOR_OR_NULL)
        {
            if($bObligatory)
                throw new Exception('You can not specify that the field is required if current returns null');

            $bReturnNullIfEmpty = true;
            $iParamType -= 1000;
        }

        if($bReturnNullIfEmpty && !isset($this->buffer[$sName]))
            return null;

        if($bObligatory && !isset($this->buffer[$sName]))
        {
            if(!$sErrorDescription)
                $sErrorDescription = 'No field '.$sName.'. Request: '.print_r($this->buffer, true);

            throw new Exception($sErrorDescription, $iErrorCode);
        }

        $ret = $this->buffer[$sName];

        switch($iParamType)
        {
            case VALIDATOR_INTEGER:
                return (int)$ret;
                break;
            case VALIDATOR_INTEGER_POSITIVE:
                $ret = (int)$ret;
                if($ret < 0)
                    $ret = 0;
                if($bObligatory && !$ret)
                    throw new Exception($sErrorDescription, $iErrorCode);

                return $ret;
                break;
            case VALIDATOR_BOOLEAN:
                return (bool)(strtolower($ret) == 'true' || $ret == '1');
                break;
            case VALIDATOR_TIMESTAMP:
                return intval($ret / 1000);
                break;
            case VALIDATOR_IPADDRESS:
                if($bObligatory)
                {
                    $ret = filter_var($ret, FILTER_VALIDATE_IP);

                    if(!$ret)
                        throw new Exception($sErrorDescription, $iErrorCode);
                }

                return $ret;

                break;
            case VALIDATOR_SESSION:
                if($ret && !Strings::checker($ret, 'session'))
                    throw new Exception($sErrorDescription, $iErrorCode);
                break;
            case VALIDATOR_DATA: //При FakeInput — это массив, если передавали параметр
                return (array)$ret;
                break;
            case VALIDATOR_BASE64: //тот же стринг но без ограничения длины
            case VALIDATOR_RAW:
                break;
            case VALIDATOR_CLIENT_TIME:
                return strtotime($ret);
                break;
            case VALIDATOR_PHONE:
                if(!$ret || !Strings::checker($ret, 'phone'))
                    throw new Exception($sErrorDescription, $iErrorCode);
                break;
            default:
                if(mb_strlen($ret) > 4096)
                    throw new Exception('Parametr lenght > 4K', $iErrorCode);
        }

        return $ret;
    }
}
