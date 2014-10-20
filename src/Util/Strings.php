<?php
namespace Brainfit\Util;

class Strings
{
    public static function checker($sValue,$sParam,$iMin=1,$iMax=100)
    {
        if (is_array($sValue))
            return false;

        switch($sParam)
        {
            case 'id': if (preg_match("/^\d{".$iMin.','.$iMax."}$/",$sValue)){ return true; } break;
            case 'ip': if(filter_var($sValue, FILTER_VALIDATE_IP)){ return true; } break;
            case 'float': if (preg_match("/^[\-]?\d+[\.]?\d*$/",$sValue)){ return true; } break;
            case 'mail': if (preg_match("/^[\da-z\_\-\.\+]+@[\da-z_\-\.]+\.[a-z]{2,5}$/i",$sValue)){ return true; } break;
            case 'login': if (preg_match("/^[\da-z\_\-]{".$iMin.','.$iMax."}$/i",$sValue)){ return true; } break;
            case 'md5': if (preg_match("/^[\da-z]{32}$/i",$sValue)){ return true; } break;
            case 'password': if (mb_strlen($sValue,'UTF-8')>=$iMin){ return true; } break;
            case 'text': if (mb_strlen($sValue,'UTF-8')>=$iMin and mb_strlen($sValue,'UTF-8')<=$iMax){ return true; } break;
            case 'session': if(preg_match("/^[\da-f]{128}$/i", $sValue)){return true;} break;
            case 'sha1': if(preg_match("/^[\da-z]{40}$/i", $sValue)) { return true; } break;
            case 'sha512': if(preg_match("/^[\da-z]{128}$/i", $sValue)) { return true; } break;
            case 'algorithm': if(preg_match("/^[\da-z]{" . $iMin . "}$/i", $sValue)){ return true; } break;
            case 'phone': if(preg_match("/^\+[\+\d]+$/i", $sValue)) { return true; } break;
            default:
                return false;
        }

        return false;
    }

    public static function passwordGenerator($length = 10)
    {
        $chars1 = 'aeiouy';
        $chars2 = 'bcdfghklmnprstvz';
        $password = '';
        $bound = ceil($length/2);

        for ($i=0; $i<$bound; $i++)
        {
            $password .= mb_substr($chars2, mt_rand(0, mb_strlen($chars2)-1), 1);
            if (mb_strlen($password) < $length){
                $password .= mb_substr($chars1, mt_rand(0, mb_strlen($chars1)-1), 1);
            }
        }

        return $password;
    }
}