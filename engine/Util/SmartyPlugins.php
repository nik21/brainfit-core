<?php

namespace Util;

class SmartyPlugins
{
    static protected $obInstance;

    public static function register(\Smarty $obSmarty)
    {
        if(is_null(self::$obInstance))
            self::$obInstance = new self();

        foreach(get_class_methods(self::$obInstance) as $sFunctionName)
        {
            list($sPrefix, $sType, $sName) = explode('_', $sFunctionName);

            if($sPrefix != 'smarty')
                continue;

            $obSmarty->registerPlugin($sType, $sName, array(__CLASS__, $sFunctionName));
        }
    }

    public static function smarty_modifier_mb_truncate($string, $length = 80, $etc = '...', $charset = 'UTF-8',
                                                       $break_words = false, $middle = false)
    {
        if($length == 0)
            return '';

        if(mb_strlen($string) > $length)
        {
            $length -= min($length, mb_strlen($etc));
            if(!$break_words && !$middle)
            {
                $string = preg_replace('/\s+?(\S+)?$/u', '', mb_substr($string, 0, $length + 1, $charset));
            }
            if(!$middle)
            {
                return mb_substr($string, 0, $length, $charset).$etc;
            }
            else
            {
                return mb_substr($string, 0, $length / 2, $charset).$etc.mb_substr($string, -$length / 2, (mb_strlen($string) - $length / 2), $charset);
            }
        }
        else
        {
            return $string;
        }
    }

    public static function smarty_modifier_bbconvert($value, $remove = false)
    {
        if($remove)
            return preg_replace('/\[img[ ]*:[ ]*[\\\]*"([^"]*?)[\\\]*"[ ]*:?[ ]*((?:left)|(?:right))?[ ]*\]/i', '', $value);

        return preg_replace('/\[img[ ]*:[ ]*[\\\]*"([^"]*?)[\\\]*"[ ]*:?[ ]*((?:left)|(?:right))?[ ]*\]/i', '<img src="\1" class="autoimage \2">', $value);
    }

    //{case value=itemsCount variants='год,года,лет'}
    public static function smarty_function_case($params, $template)
    {
        //language, value, variants
        //$sLanguage = $params['language'];
        //if (!$sLanguage)
        //    $sLanguage = \Util\Domain::getLanguage();

        $iValue = (int)$params['value'];
        $aVariants = (array)explode(',', $params['variants']);

        //год, года, лет. сообщение, сообщения, сообщений 0,1,2
        return
            $iValue % 10 == 1 && $iValue % 100 != 11
                ? $aVariants[0]
                : ($iValue % 10 >= 2 && $iValue % 10 <= 4 && ($iValue % 100 < 10 || $iValue % 100 >= 20)
                ? $aVariants[1]
                : $aVariants[2]);

        return '';
    }
}