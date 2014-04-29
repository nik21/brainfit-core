<?php
namespace Brainfit\Io\Input;

use Brainfit\Settings;
use Brainfit\Util\Reflection\Singleton;

class InputRouter extends InputPost implements InputInterface
{
    use Singleton;

    private $aPath = array();

    function __construct()
    {
        parent::__construct();

        $aUrlSplitter = explode('?', $_SERVER['REQUEST_URI']);
        $url = current($aUrlSplitter);
        $bNeedRedirect = false;

        if(count($aUrlSplitter) == 1 && $_SERVER['REQUEST_METHOD'] != 'POST')
        {
            //If there is no slash at the end, make a redirect to the version with a slash at the end

            $aRedirectExclusionList = (array)Settings::get('PROJECT', 'REDIRECT_EXCLUSION_LIST');
            if(!$aRedirectExclusionList)
                $aRedirectExclusionList = ['.jpg', '.png', '.gif', '.html', '.htm', '.txt', '.php', '.pl', '.asp'];

            $sLastSymbol = mb_substr($url, -1, 1);
            if($sLastSymbol != '/'
                && !in_array(mb_convert_case(mb_substr($url, -4), MB_CASE_LOWER), $aRedirectExclusionList)
            )
            {
                $url = $url . '/';
                $bNeedRedirect = true;
            }
            elseif($sLastSymbol == '/'
                && in_array(mb_convert_case(mb_substr($url, -5, 4), MB_CASE_LOWER), $aRedirectExclusionList)
            )
            {
                $url = mb_substr($url, 0, mb_strlen($url) - 1);
                $bNeedRedirect = true;
            }
        }

        //If the address has 2 slash, make a redirect on one
        if(mb_strpos($url, '//'))
        {
            $url = preg_replace('/\/+/i', '/', $url);
            $bNeedRedirect = true;
        }

        if($bNeedRedirect)
        {
            header('Location: ' . ($_SERVER['HTTPS'] ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . $url, true, 301);
            exit;
        }

        //remove the slashes from the beginning and end addresses
        $url = preg_replace("/^\//", "", $url);
        $url = preg_replace("/\/$/", "", $url);

        $url = urldecode($url); //conversion %D0 in Cyrillic "П", for example

        $this->aPath = explode("/", $url);
    }

    public function getPath($element = null)
    {
        if(isset($element))
        {
            if($element == -1)
            {
                //last

                return $this->aPath[count($this->aPath) - 1];
            }
            elseif($element == -2)
            {
                //collect

                return join('/', $this->aPath);
            }
            else
            {
                return $this->aPath[$element];
            }
        }
        else
        {
            return $this->aPath;
        }
    }
}
