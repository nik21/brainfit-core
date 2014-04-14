<?php
namespace Io\Output;

use Util\Reflection\Singleton;

class Json implements OutputInterface
{
    use Singleton;

    protected $result;

    public function assign($name, $value)
    {
        $this->result[$name] = $value;
    }

    public function get()
    {
        return json_encode($this->result);
    }

    public function getVar($name)
    {
        return $this->result[$name];
    }
}
