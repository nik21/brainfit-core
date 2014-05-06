<?php
namespace Brainfit\Io\Data;

use Brainfit\Model\Exception;
use Brainfit\Settings;
use Brainfit\Util\Debugger;

class Elasticsearch
{
    const DEBUG = false;

    private static $aInstances = [];
    private $obElasticSearch = false;
    private $aCommandQueue = [];

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
            Debugger::log('Elasticsearch problems', $e->getMessage(), $e->getLine());
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

        if (self::DEBUG && $this->aCommandQueue['upsert'])
            Debugger::log('Elastic upsert', print_r($this->aCommandQueue['upsert'], true));

        //Update or insert
        foreach ($this->aCommandQueue['upsert'] as $index => $data1) {
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

                if(!$ret || !$ret['ok'])
                    Debugger::log('Elasticsearch problems', 'Upsert trouble', $ret);
            }
        }

        if (self::DEBUG && $this->aCommandQueue['delete'])
            Debugger::log('Elastic delete', print_r($this->aCommandQueue['delete'], true));

        //Delete
        foreach ($this->aCommandQueue['delete'] as $index => $data1) {
            foreach ($data1 as $type => $aIds) {
                foreach ($aIds as $id) {
                    $ret = $this->obElasticSearch->delete([
                        'index' => $index,
                        'type' => $type,
                        'id' => $id,
                    ]);

                    if(!$ret || !$ret['ok'])
                        Debugger::log('Elasticsearch problems', 'Delete trouble '. $id. ' '.$type.' '.$index);
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
            Debugger::log('Elastic search', print_r($body, true));

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
            Debugger::log('Elasticsearch problems', 'Search trouble '.print_r($body, true).' '.$type.' '.$index,
                $ret['error']);

            return false;
        }

        return $ret;
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
}