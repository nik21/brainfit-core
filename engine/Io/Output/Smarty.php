<?php
namespace Io\Output;

use Util\Reflection\Singleton;
use Util\SmartyPlugins;

class Smarty implements OutputInterface
{
    use Singleton;

    private $smarty;
    private $displayTemplate;
    private $template;

    private $cache;

    function __construct()
    {
        $this->smarty = new \Smarty();
        SmartyPlugins::register($this->smarty);

        $this->smarty->setCacheDir(ROOT.'/cache/smarty/cache/');
        $this->smarty->setCompileDir(ROOT.'/cache/smarty/compile/');
        $this->smarty->setTemplateDir(ROOT.'templates/');

        $this->smarty->error_reporting = E_ALL ^ E_NOTICE; // E_ALL; // LEAVE E_ALL DURING DEVELOPMENT
    }

    public function assign($name, $value = null)
    {
        if($name == 'displayTemplate')
            $this->displayTemplate = $value;
        elseif($name == 'template')
            $this->template = $value;
        elseif($name == 'cache')
            $this->cache = (int)$value;
        else
            $this->smarty->assign($name, $value);
    }

    public function getVar($name)
    {
        if($name == 'displayTemplate')
            return $this->displayTemplate;
        elseif($name == 'template')
            return $this->template;
        else
            return $this->smarty->getTemplateVars($name);
    }

    /*
     * Корректировака имен шаблонов перед выводом
     */
    private function corrector()
    {
        if($this->template)
        {
            $this->template = str_replace('_', '/', $this->template).'.tpl';
            $this->smarty->assign('template', $this->template);
        }


        if($this->displayTemplate && substr($this->displayTemplate, 0, 7) != 'string:'
            && substr($this->displayTemplate, 0, 5) != 'eval:'
        )
            $this->displayTemplate = str_replace('_', '/', $this->displayTemplate).'.tpl';
    }

    public function get()
    {
        $this->corrector();

        $this->smarty->caching = $this->cache > 0 && \Server::PRODUCTION_MODE;
        $this->smarty->cache_lifetime = (int)$this->cache;

        if($this->displayTemplate)
            $this->smarty->display($this->displayTemplate);
        elseif(!$this->displayTemplate && $this->template)
            $this->smarty->display($this->template);
    }

    public function fetch()
    {
        $this->corrector();

        return $this->smarty->fetch($this->displayTemplate);
    }
}
