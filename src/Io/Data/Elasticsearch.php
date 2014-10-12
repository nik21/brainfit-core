<?php
namespace Brainfit\Io\Data;

use Brainfit\Model\Exception;
use Brainfit\Settings;

class Elasticsearch
{
    const DEBUG = false;

    private static $aInstances = [];
    private $obElasticSearch = false;
    private $aCommandQueue = [];
    private static $bWithoutDebug = false;

    public function __construct($sServerId)
    {
        if(!$sServerId)
            throw new Exception('Invalid server id');

        $params = Settings::get('ELASTICSEARCH', $sServerId);

        if(!$params)
            return;

        $this->obElasticSearch = new \Elasticsearch\Client($params);

    }

    /**
     * @param string $sServerId
     *
     * @throws \Brainfit\Model\Exception
     * @return self
     */
    public static function getInstance($sServerId = 'main')
    {
        if (Settings::get('MAINTENCE', 'disableElasticSearchServer'))
            return false;

        if(isset(self::$aInstances[$sServerId]))
            return self::$aInstances[$sServerId];
        else {
            $client = new self($sServerId);

            if($client === false)
                throw new Exception('ElasticSearch connection failed');

            self::$aInstances[$sServerId] = $client;

            return self::$aInstances[$sServerId];
        }
    }

    /**
     * Close instances and send data to server
     */
    public static function destruct()
    {
        try {
            /** @var $obElasticInstances self */
            foreach (self::$aInstances as $obElasticInstances)
                $obElasticInstances->transfer();
        } catch (\Exception $e) {
            self::addDebug('Elasticsearch problems');
        }

        self::$aInstances = null;
    }

    /**
     * Flush elasticSearch
     */
    public function transfer()
    {
        if ($this->obElasticSearch === false)
            return;

        if (self::DEBUG && isset($this->aCommandQueue['upsert']))
            self::addDebug('Elastic upsert', $this->aCommandQueue['upsert']);

        //Update or insert
        foreach ((array)$this->aCommandQueue['upsert'] as $index => $data1) {
            foreach ($data1 as $type => $data2) {
                $params = [];
                foreach ($data2 as $id => $data3) {
                    $params['body'][] = [
                        'update' => [
                            '_id' => $id
                        ]
                    ];

                    $params['body'][] = [
                        'doc_as_upsert' => 'true',
                        'doc' => $data3['body']
                    ];
                }

                $params['index'] = $index;
                $params['type'] = $type;

                $ret = $this->obElasticSearch->bulk($params);

                if(!$ret || $ret['error'])
                    self::addDebug('Elasticsearch problems: upsert trouble', $ret);
            }
        }

        if (self::DEBUG && isset($this->aCommandQueue['delete']))
            self::addDebug('Elastic delete', $this->aCommandQueue['delete']);

        //Delete
        foreach ((array)$this->aCommandQueue['delete'] as $index => $data1) {
            foreach ($data1 as $type => $aIds) {
                foreach ($aIds as $id) {
                    $ret = $this->obElasticSearch->delete([
                        'index' => $index,
                        'type' => $type,
                        'id' => $id,
                    ]);

                    if(!$ret || !$ret['ok'])
                        self::addDebug('Elasticsearch problems: Delete trouble '. $id. ' '.$type.' '.$index);
                }
            }
        }

        //Clean
        $this->aCommandQueue = [];
    }

    public function search($index, $type, $body, $from = 0, $size = 10)
    {
        if ($this->obElasticSearch === false)
            return [];

        if (self::DEBUG)
            self::addDebug('Elastic search', $body);

        try
        {
            $ret = $this->obElasticSearch->search([
                'from' => $from,
                'size' => $size,
                'index' => $index,
                'type' => $type,
                'body' => $body /*[
                    'query' => $query,
                    [
                        ['sort' => ['order' => 'desc']]
                    ]
                ]+($sort ? [
                    'sort' => $sort
                ] : [])*/
            ]);
        }catch (\Exception $e)
        {
            $ret['error'] = $e->getMessage();
        }

        if($ret['error']) {
            self::addDebug('Elasticsearch problems: Search trouble '.print_r($body, true).' '.$type.' '.$index,
                $ret['error']);

            return false;
        }

        return $ret;
    }

    public function ping()
    {
        return !!$this->obElasticSearch->ping();
    }

    public function upsert($index, $type, $id, $docFields)
    {
        foreach ($docFields as $sFieldName => $value)
            $this->aCommandQueue['upsert'][$index][$type][$id]['body'][$sFieldName] = $value;

        return true;
    }

    public function delete($index, $type, $id)
    {
        if (isset($this->aCommandQueue['upsert'][$index][$type][$id]))
            unset($this->aCommandQueue['upsert'][$index][$type][$id]);

        $this->aCommandQueue['delete'][$index][$type][] = $id;

        return true;
    }

    private static function addDebug($sMessage, $params = [])
    {
        if (self::$bWithoutDebug)
            return;

        if (!class_exists('\\Monolog\\Registry'))
        {
            self::$bWithoutDebug = true;
            return;
        }

        $obInstance = null;
        try
        {
            $obInstance = \Monolog\Registry::getInstance('elasicsearch');
        }catch (\InvalidArgumentException $e)
        {

        }

        if (!$obInstance)
        {
            self::$bWithoutDebug = true;
            return;
        }

        $params = (array)$params;
        $obInstance->addDebug($sMessage, $params);
    }
}