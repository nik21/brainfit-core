<?php
namespace Brainfit\Io\Data\Drivers;

use Brainfit\Model\Exception;
use Brainfit\Settings;
use Brainfit\Util\Reflection\Singleton;

class Memcached
{
    use Singleton;

    private $bDisabled = false;

    private $obMemcached;

    function __construct()
    {
        if ($this->bDisabled = (bool)Settings::get('MEMCACHED', 'disabled'))
            return;

        $aServers = (array)Settings::get('MEMCACHED', 'servers');
        if (!$aServers)
            throw new Exception('Empty memcached servers config');

        $this->obMemcached = new \Memcached();

        if(!$this->obMemcached->getServerList())
        {
            //This code block will only execute if we are setting up a new EG(persistent_list) entry
            $this->obMemcached->setOption(\Memcached::OPT_RECV_TIMEOUT, 5000); //only if OPT_NO_BLOCK = false?
            $this->obMemcached->setOption(\Memcached::OPT_SEND_TIMEOUT, 5000); //only if OPT_NO_BLOCK = false?
            $this->obMemcached->setOption(\Memcached::OPT_CONNECT_TIMEOUT, 5000);
            $this->obMemcached->setOption(\Memcached::OPT_POLL_TIMEOUT, 5000);
            $this->obMemcached->setOption(\Memcached::OPT_HASH, \Memcached::HASH_MURMUR); //fast algorithm

            $this->obMemcached->setOption(\Memcached::OPT_TCP_NODELAY, true);
            $this->obMemcached->setOption(\Memcached::OPT_LIBKETAMA_COMPATIBLE, true);

            // Async bug: https://bugs.launchpad.net/libmemcached/+bug/583031

            //$this->obMemcached->setOption(\Memcached::OPT_NO_BLOCK, true ); //maybe conflicts in rewriting
            $this->obMemcached->setOption(\Memcached::OPT_BINARY_PROTOCOL, false); //Core bugs with add cmd
            //$this->obMemcached->setOption(\Memcached::OPT_SERVER_FAILURE_LIMIT, 10);

            $this->obMemcached->addServers($aServers);
        }
    }

    public function get($key)
    {
        if ($this->bDisabled)
            return false;

        return $this->obMemcached->get($this->hash($key));
    }

    private function hash($key)
    {
        return Settings::get('MEMCACHED', 'key').'%'.hash('sha512', $key);
    }

    public function set($key, $var, $flag = 0, $expire = 0)
    {
        if ($this->bDisabled)
            return true;

        return $this->obMemcached->set($this->hash($key), $var, $expire);
    }

    public function add($key, $var, $flag = 0, $expire = 0)
    {
        if ($this->bDisabled)
            return true;

        return $this->obMemcached->add($this->hash($key), $var, $expire);
    }

    public function delete($key)
    {
        if ($this->bDisabled)
            return true;

        return $this->obMemcached->delete($this->hash($key));
    }

    public function increment($key, $value = 1)
    {
        if ($this->bDisabled)
            return 1;

        return $this->obMemcached->increment($this->hash($key), $value);
    }

    public function decrement($key, $value = 1)
    {
        if ($this->bDisabled)
            return 0;

        return $this->obMemcached->decrement($this->hash($key), $value);
    }

    public function getServerList()
    {
        if ($this->bDisabled)
            return [];

        return $this->obMemcached->getServerList();
    }
}
