<?php
namespace Brainfit\Io\Output;

use Brainfit\Io\Output\SmartyPlugins\SmartyMainPlugins;
use Brainfit\Util\Reflection\Singleton;

class OutputSmarty extends OutputJson implements OutputInterface
{
    use Singleton;

    private $smarty;

    public function __construct()
    {
        $this->smarty = new \Smarty();
    }

    public function htmlEscapeReplacement($e)
    {
        return htmlspecialchars($e, ENT_NOQUOTES | ENT_HTML5);
    }

    public function addPlugin($obSmartyPluginClass)
    {
        foreach(get_class_methods($obSmartyPluginClass) as $sFunctionName)
        {
            list($sPrefix, $sType, $sName) = explode('_', $sFunctionName);

            if($sPrefix != 'smarty')
                continue;

            $this->smarty->registerPlugin($sType, $sName, [$obSmartyPluginClass, $sFunctionName]);
        }
    }

    private function replaceObjects($v)
    {
        if (is_object($v) && method_exists($v, 'get'))
            return $v->get(); //Тут не следует проверять обещания, а то они пойдут по-одному
        else if (is_array($v))
        {
            foreach($v as &$v1)
                $v1 = $this->replaceObjects($v1);
        }

        return $v;
    }

    private function prepare()
    {
        //Init smarty options
        $this->addPlugin(new SmartyMainPlugins());

        $this->smarty->setCacheDir(ROOT.'/cache/smarty/cache/');
        $this->smarty->setCompileDir(ROOT.'/cache/smarty/compile/');
        $this->smarty->setTemplateDir(ROOT.'templates/');

        $this->smarty->error_reporting = error_reporting();
        $this->smarty->registerFilter('variable', [$this, 'htmlEscapeReplacement']);

        //recursive call "get" for all objects. Execute deferred methods
        $this->result = $this->replaceObjects($this->result);
        $this->result = $this->replaceObjects($this->result); //For deferred in deferred output

        //templates
        if($this->result['template'])
            $this->result['template'] = str_replace('_', '/', $this->result['template']).'.tpl';

        if ($this->result['displayTemplate'] && substr($this->result['displayTemplate'], 0, 7) != 'string:'
            && substr($this->result['displayTemplate'], 0, 5) != 'eval:')
            $this->result['displayTemplate'] =  str_replace('_', '/', $this->result['displayTemplate']).'.tpl';

        if ($this->result['cache'])
            $this->smarty->cache_lifetime = (int)$this->result['cache'];

        //assign all:
        $this->smarty->assign($this->result);
    }

    public function get()
    {
        $this->prepare();

        if($this->result['displayTemplate'])
            $this->smarty->display($this->result['displayTemplate']);
        elseif(!$this->result['displayTemplate'] && $this->result['template'])
            $this->smarty->display($this->result['template']);
    }

    public function fetch()
    {
        $this->prepare();

        return $this->smarty->fetch($this->result['displayTemplate']);
    }
}
