<?php
namespace Io\Data\Drivers;

use Util\Reflection\Singleton;

class Memcached
{
    use Singleton;

    const PINBA_PROFILING = false;
    static protected $oInstance;
    /**
     * @var Memcached
     */
    private $obMemcached;

    function __construct()
    {
        $aServers = \Settings::getMemcachedServers();

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
        $obPinba = $this->createPinba('get');
        $ret = $this->obMemcached->get($this->hash($key));
        Pinba::timer_stop($obPinba);

        return $ret;
    }

    private function createPinba($method)
    {
        if(!self::PINBA_PROFILING || !$method)
            return false;

        return Pinba::timer_start(array(
            'group' => 'methods',
            'server' => \Server::SERVER_UNIQUE_NAME,
            'method' => '_memcached.'.$method
        ));
    }

    private function hash($key)
    {
        return \Server::API_MEMCACHE_KEY.'%'.hash('sha512', $key);
    }

    public function set($key, $var, $flag = 0, $expire = 0)
    {
        $obPinba = $this->createPinba('set');
        $ret = $this->obMemcached->set($this->hash($key), $var, $expire);
        Pinba::timer_stop($obPinba);

        return $ret;
    }

    public function add($key, $var, $flag = 0, $expire = 0)
    {
        $obPinba = $this->createPinba('add');
        $ret = $this->obMemcached->add($this->hash($key), $var, $expire);
        Pinba::timer_stop($obPinba);

        return $ret;
    }

    public function delete($key)
    {
        $obPinba = $this->createPinba('delete');
        $ret = $this->obMemcached->delete($this->hash($key));
        Pinba::timer_stop($obPinba);

        return $ret;
    }

    public function increment($key, $value = 1)
    {
        $obPinba = $this->createPinba('increment');
        $ret = $this->obMemcached->increment($this->hash($key), $value);
        Pinba::timer_stop($obPinba);

        return $ret;
    }

    public function decrement($key, $value = 1)
    {
        $obPinba = $this->createPinba('decrement');
        $ret = $this->obMemcached->decrement($this->hash($key), $value);
        Pinba::timer_stop($obPinba);

        return $ret;
    }

    public function getServerList()
    {
        return $this->obMemcached->getServerList();
    }
}
