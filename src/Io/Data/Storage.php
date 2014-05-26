<?php
namespace Brainfit\Io\Data;

use Brainfit\Io\Data\Drivers\Redis;
use Brainfit\Model\Exception;

/**
 * Надсройка над драйвером phpredis
 * Позволяет создавать классы, расширяемые хранилищем и реализует методы
 * для работы с ним.
 */
abstract class Storage
{
    const REDIS_PREFIX = 'bf';

    /**
     * @var \Redis
     */
    private $redis; //Data already loaded, if the object exists
    private $className; //Data storage name
    private $currentId = null; //New or current object id
    private $_aHashData = null;
    private $_sRedisPrefix = '';

    public static function findByIndex($aConnectionInfo, $sIndexName, $sIndexType, $sIndexValue)
    {
        $sRedisPrefix = self::REDIS_PREFIX;
        if(!$sRedisPrefix)
            throw new Exception('Not set REDIS_PREFIX');

        list($sStoreName, $sServerIp, $iServerPort) = $aConnectionInfo;

        if(!$sStoreName || !$sServerIp || !$iServerPort)
            throw new Exception('Not specified correct connection information');

        //Init
        $obRedis = Redis::getInstance($sServerIp, $iServerPort);

        if($sIndexType == 'primary')
        {
            $sKey = implode('::', array($sRedisPrefix, $sStoreName, 'pk', $sIndexName, $sIndexValue));

            return $obRedis->get($sKey);
        }
        elseif($sIndexType == 'multi')
        {
            $sKey = implode('::', array($sRedisPrefix, $sStoreName, 'mk', $sIndexName, $sIndexValue));

            return (array)$obRedis->sMembers($sKey);
        }
        else
            throw new Exception('The index type can be primary or multi only');
    }

    public function findKeys($sPattern)
    {
        $this->init();

        $aRet = (array)$this->redis->keys($this->getKeyName(array('table', $this->getCurrentId(), $sPattern)));

        foreach($aRet as $k => $v)
            $aRet[$k] = str_replace(implode('::', array_merge(array($this->_sRedisPrefix, $this->className,
                    'table', $this->getCurrentId()))) . '::', '', $v);

        return $aRet;
    }

    //abstract function __construct($id); php 5.4

    abstract function getCurrentId();

    public function __get($name)
    {
        throw new Exception('You can not query the object properties directly');
    }

    public function __set($name, $value)
    {
        throw new Exception('You can not set the properties of an object directly');
    }

    /**
     * Добавляет связь с объектом. Попытки повторного связывания будут
     * обрабатываться без ошибок, ссылка продолжит храниться
     * Либо добавляет скалярное значение
     *
     * @param mixed $fieldName
     * @param mixed $object
     * @param int $iScore
     *
     * @return bool|int
     */
    public function attach($fieldName, $object, $iScore = null)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        if(is_null($iScore))
            return (bool)$this->redis->sAdd($keyName, $fieldValue);

        return $this->redis->zAdd($keyName, $iScore, $fieldValue);
    }

    public function clearAttached($fieldName)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $this->redis->del($keyName);

        return $this;
    }

    public function delField($fieldName)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $this->redis->del($keyName);

        return $this;
    }

    public function sDiff($sObjectConstructor, $sField, $sField2, $sFieldN = null)
    {
        $this->init();

        $iNumArgs = func_num_args();
        $aKeys = array();
        for($i = 1; $i < $iNumArgs; $i++)
            $aKeys[] = func_get_arg($i);

        foreach($aKeys as $k => $v)
            $aKeys[$k] = $this->getKeyName(array('table', $this->getCurrentId(), $v));

        $fieldValue = call_user_func_array(array($this->redis, 'sDiff'), $aKeys);

        $ret = array();

        foreach($fieldValue as $v)
        {
            $i++;
            if(!is_null($sObjectConstructor))
                $ret[] = $this->getObjectConstructor($sObjectConstructor, $v); //$ret[] = new $sObjectConstructor($v);
            else
                $ret[] = $v;

        }

        return $ret;
    }

    public function sInter($sObjectConstructor, $sField, $sField2, $sFieldN = null)
    {
        $this->init();

        $iNumArgs = func_num_args();
        $aKeys = array();
        for($i = 1; $i < $iNumArgs; $i++)
            $aKeys[] = func_get_arg($i);

        foreach($aKeys as $k => $v)
            $aKeys[$k] = $this->getKeyName(array('table', $this->getCurrentId(), $v));

        $fieldValue = call_user_func_array(array($this->redis, 'sInter'), $aKeys);

        $ret = array();

        foreach($fieldValue as $v)
        {
            $i++;
            if(!is_null($sObjectConstructor))
                $ret[] = $this->getObjectConstructor($sObjectConstructor, $v); //$ret[] = new $sObjectConstructor($v);
            else
                $ret[] = $v;

        }

        return $ret;
    }

    /**
     * Возвращает неупорядоченный массив объектов, прикрепленных с помощью
     * attach
     * Не стоит забывать, что множество неупорядоченно и в случае указания лимита,
     * вернутся N случайных записей (уникальных). Если указать лимит отрицательным, вернутся
     * |N| разных, в том числе — повторяющихся значений
     *
     * @param mixed $fieldName
     * @param mixed $objectName
     * @param int $limiter - Вернуть только часть
     *
     * @return array
     */
    public function getAttachedObjects($fieldName, $objectName = null, $limiter = 0)
    {
        $limiter = (int)$limiter;

        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(!$limiter)
            $fieldValue = $this->redis->sMembers($keyName);
        else
            $fieldValue = $this->redis->sRandMember($keyName, $limiter);

        if($fieldValue === false)
            return array();

        if(is_null($objectName))
            return (array)$fieldValue;

        $ret = array();
        foreach($fieldValue as $v)
            $ret[] = $this->getObjectConstructor($objectName, $v);

        return $ret;
    }

    public function popAttachedObject($fieldName, $objectName = null)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $fieldValue = $this->redis->sPop($keyName);


        if(!is_null($objectName))
            $ret = $this->getObjectConstructor($objectName, $fieldValue); // new $objectName($v);
        else
            $ret = $fieldValue;

        return $ret;
    }

    public function zScore($fieldName, $object)
    {
        $this->init();

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        return $this->getAttachedItemScore($fieldName, $fieldValue);
    }

    /**
     * Вернет float или строго false
     *
     * @param $fieldName
     * @param $object
     *
     * @return float
     */
    public function getAttachedItemScore($fieldName, $object)
    {
        $this->init();
        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        return $this->redis->zScore($keyName, $fieldValue);
    }

    public function zIncrement($fieldName, $object, $increment)
    {
        $this->init();
        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        return $this->redis->zIncrBy($keyName, $increment, $fieldValue);
    }

    public function zCount($fieldName, $min = '-inf', $max = '+inf')
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        return (int)$this->redis->zCount($keyName, $min, $max);
    }

    public function zRangeByScore($fieldName, $objectName = null, $start, $stop, $offset = null, $count = null,
                                  $bReverse = false, $bWithScores = false)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $aOptions = array();
        if(!is_null($offset) && !is_null($count))
            $aOptions['limit'] = array($offset, $count);
        if($bWithScores)
            $aOptions['withscores'] = true;


        if(!$bReverse)
        {
            $fieldValue = $this->redis->zRangeByScore($keyName, $start, $stop, $aOptions);
        }
        else
        {
            $fieldValue = $this->redis->zRevRangeByScore($keyName, $stop, $start, $aOptions);
        }


        if(!$fieldValue)
            return array();

        $ret = array();
        foreach($fieldValue as $k => $v)
        {
            if($bWithScores)
            { //Именно так. Даже объет -- ключ. Ведь он уникален, а оценка могла бы совпадать
                //if (!is_null($objectName))
                //$ret[$this->getObjectConstructor($objectName, $k)] = $v;//Патавая ситуация. Объект не может быть ключем.
                //else
                $ret[$k] = $v;
            }
            else
            {
                if(!is_null($objectName))
                    $ret[] = $this->getObjectConstructor($objectName, $v); //new $objectName($v);
                else
                    $ret[] = $v;
            }
        }

        return $ret;
    }

    public function getAttachedByScore($fieldName, $iMinOrStart, $iMaxOrStop, $objectName = null, $bByScore = true,
                                       $bReverse = false, $iOffset = null, $iCount = null, $bWithScores = false)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if($bByScore)
        {
            $aOptions = []
                +(array)($iOffset || $iCount ? ['limit' => [$iOffset, $iCount]] : [])
                +(array)($bWithScores ? ['withscores' => true] : []);

            if($bReverse)
                $fieldValue = $this->redis->zRevRangeByScore($keyName, $iMaxOrStop, $iMinOrStart, $aOptions);
            else
                $fieldValue = $this->redis->zRangeByScore($keyName, $iMinOrStart, $iMaxOrStop, $aOptions);
        }
        elseif(is_null($iMinOrStart) && is_null($iMaxOrStop))
            $fieldValue = ($bReverse ? $this->redis->zRevRange($keyName, 0, -1)
                : $this->redis->zRange($keyName, 0, -1));
        else
            $fieldValue = ($bReverse ? $this->redis->zRevRange($keyName, $iMinOrStart, $iMaxOrStop)
                : $this->redis->zRange($keyName, $iMinOrStart, $iMaxOrStop));

        if(!$fieldValue)
            return array();

        $ret = array();
        if ($bWithScores)
        {
            foreach($fieldValue as $v=>$score)
            {
                if(!is_null($objectName))
                    $ret[] = ['score'=>$score, 'object'=>$this->getObjectConstructor($objectName, $v)];
                else
                    $ret[] = ['score'=>$score, 'object'=>$v];
            }
        }
        else
        {
            foreach($fieldValue as $v)
            {
                if(!is_null($objectName))
                    $ret[] = $this->getObjectConstructor($objectName, $v); //new $objectName($v);
                else
                    $ret[] = $v;
            }
        }

        return $ret;
    }

    public function interStore($aKeys, $withScores = false,
                               $sDestination = null, $bUnion = false, $aggregateFunction = 'SUM')
    {
        $bIsAssoc = (is_array($aKeys) && count(array_filter(array_keys($aKeys), 'is_string')) == count($aKeys));

        $this->init();

        if(!count($aKeys))
            throw new Exception('Неуказаны ключи');
        if(is_null($sDestination))
            throw new Exception('Неуказано назначение');

        $sDestinationKey = $this->getKeyName(array('table', $this->getCurrentId(), $sDestination));

        $aFullKeys = array();
        $aWeights = array();
        foreach($aKeys as $sKeyName => $iWeight)
        {
            if($bIsAssoc)
            {
                $aFullKeys[] = $this->getKeyName(array('table', $this->getCurrentId(), $sKeyName));
                $aWeights[] = $iWeight;
            }
            else
            {
                $aFullKeys[] = $this->getKeyName(array('table', $this->getCurrentId(), $iWeight));
                $aWeights[] = 1;
            }
        }


        //TODO: Добавить возврат объектов
        if(!$bUnion)
            return $this->redis->zInter($sDestinationKey, $aFullKeys, $aWeights, $aggregateFunction);
        else
            return $this->redis->zUnion($sDestinationKey, $aFullKeys, $aWeights, $aggregateFunction);
        //return call_user_func_array(array($this->redis,'zInter'), $aFullKeys);
    }

    /**
     * @param int $iState - 1 -- start, 2 -- commit, 3 - ручная отмена. Неудачный коммит и так не запишет
     *
     * @throws Exception
     * @return bool|\Redis|void
     */
    public function transaction($iState)
    {
        $iState = (int)$iState;
        $this->init();

        switch($iState)
        {
            case 1:
                return $this->redis->multi();
                break;
            case 2:
                //Истина в случае успеха
                return is_array($this->redis->exec()); //Массив где 0 элемент будет true или null вообще
                break;
            case 3:
                return $this->redis->discard();
                break;

            default:
                throw new Exception('Неверное использование transaction');
        }
    }

    /**
     * @param $fieldName
     *
     * @internal param array|string $key : a list of keys
     */
    public function watch($fieldName)
    {
        $this->init();
        $sKey = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        return $this->redis->watch($sKey);
    }

    /**
     * Вызов не требуется, если был вызван exec или discard
     */
    public function unwatch()
    {
        $this->init();

        return $this->redis->unwatch();
    }

    public function setField($fieldName, $fieldValue, $seconds = null)
    {
        $this->init();
        $sKey = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_null($seconds) || $seconds === 0)
            $this->redis->set($sKey, $fieldValue);

        if($seconds > 0)
            $this->redis->setex($sKey, (int)$seconds, $fieldValue);
        elseif($seconds === 0)
            $this->redis->persist($sKey);

        return $this;
    }

    public function setIfNotExist($fieldName, $fieldValue, $seconds = null)
    {
        $this->init();
        $sKey = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $ret = $this->redis->setnx($sKey, $fieldValue);

        if ($ret && !is_null($seconds))
            $this->redis->expire($sKey, (int)$seconds);

        return $ret;
    }

    /**
     * Создает индексное поле для данного контекста.
     * Используется это же хранилище, но иное имя поля, где ключем выступит
     * $sIndexName и $sIndexValue.
     * В зависимости от $sIndexType значением будет выступать один-к-одному
     * значение или список значений.
     *
     * ВНИМАНИЕ! Ради производительности уникальность не проверяется:
     * это должен сделать разработчик перед созданием индекса!
     * Вследствие отсутствия подобной проверки уникальность будет нарушена,
     * так как новые данные перезапишут старые!
     * Не забывайте про транзакции на этапе проверки индекса: лучше всего
     * одной транзакцией и проверять старый, ставить новый индекс и значение,
     * затем смотреть результат первой проверки и откатывать,
     * если он не удволетворяет.
     *
     * @param $sIndexName
     * @param $sIndexType - primary/multi
     * @param $sIndexValue
     * @param int $iTTL
     *
     * @throws Exception
     */
    public function createIndex($sIndexName, $sIndexType, $sIndexValue, $iTTL = 0)
    {
        $this->init();

        if(is_object($sIndexValue))
            $sVal = $sIndexValue->getCurrentId();
        else
            $sVal = $sIndexValue;

        if($sIndexType == 'primary')
        {
            $sKey = $this->getKeyName(array('pk', $sIndexName, $sVal));
            if(!$iTTL)
                $this->redis->set($sKey, $this->getCurrentId());
            else
                $this->redis->setex($sKey, $iTTL, $this->getCurrentId());
        }
        elseif($sIndexType == 'multi')
        {
            $sKey = $this->getKeyName(array('mk', $sIndexName, $sVal));
            $this->redis->sAdd($sKey, $this->getCurrentId());
        }
        else
            throw new Exception('Тип индекса может быть лишь primary или multi');
    }

    public function removeIndex($sIndexName, $sIndexType, $sIndexValue)
    {
        $this->init();

        if(is_object($sIndexValue))
            $sVal = $sIndexValue->getCurrentId();
        else
            $sVal = $sIndexValue;

        if($sIndexType == 'primary')
        {
            $sKey = $this->getKeyName(array('pk', $sIndexName, $sVal));
            $this->redis->delete($sKey);
        }
        elseif($sIndexType == 'multi')
        {
            $sKey = $this->getKeyName(array('mk', $sIndexName, $sVal));
            $this->redis->sRem($sKey, $this->getCurrentId());
        }
        else
            throw new Exception('Тип индекса может быть лишь primary или multi');
    }

    public function incrementField($fieldName, $iIncrement = 1)
    {
        $this->init();
        $sKey = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));
        $iIncrement = (int)$iIncrement;

        if(!$iIncrement) //0
            return $this->getField($fieldName);

        if($iIncrement == 1)
            return $this->redis->incr($sKey);
        else
            return $this->redis->incrBy($sKey, $iIncrement);
    }

    /**
     * Считывает отдельный ключ или несколько ключей за раз.
     * Несуществующий ключ вернет false.
     * При считывании нескольких ключей, вернет их в обычном массиве или в ассоциативном, если
     * установлен $bReturnAsArray
     *
     * @param $fieldName
     * @param $bReturnAsArray
     *
     * @return array|bool|string
     */
    public function getField($fieldName, $bReturnAsArray = false)
    {
        $this->init();

        $ret = null;

        if(is_array($fieldName))
        {
            if(count($fieldName) == 0)
                return array();

            //Несколько значений
            $aKeys = array();
            foreach($fieldName as $sKey)
                $aKeys[] = $this->getKeyName(array('table', $this->getCurrentId(), $sKey));

            $ret = (array)$this->redis->mget($aKeys);
        }
        else
        {
            $ret = $this->redis->get($this->getKeyName(array('table', $this->getCurrentId(), $fieldName)));
        }

        if($bReturnAsArray)
        {
            $aNewRet = array();
            $ret = (array)$ret;

            foreach((array)$fieldName as $i => $sKey)
                $aNewRet[$sKey] = $ret[$i];

            return $aNewRet;
        }
        else
            return $ret;

    }

    public function setFieldExpire($fieldName, $seconds, $asTimestap = false)
    {
        $this->init();

        $seconds = intval($seconds);

        //CAUTION: expireAt timestamp problem php/unix time

        if(!$seconds)
            $this->redis->persist($this->getKeyName(array('table', $this->getCurrentId(), $fieldName)));
        elseif(!$asTimestap)
            $this->redis->expire($this->getKeyName(array('table', $this->getCurrentId(), $fieldName)), $seconds);
        else
            $this->redis->expireAt($this->getKeyName(array('table', $this->getCurrentId(), $fieldName)), $seconds);
    }

    public function setHashExpire($seconds, $asTimestap = false)
    {
        $this->init();

        $seconds = intval($seconds);

        if(!$seconds)
            return (bool)$this->redis->persist($this->getKeyName(array('hash', $this->getCurrentId())));
        elseif(!$asTimestap)
            return (bool)$this->redis->expire($this->getKeyName(array('hash', $this->getCurrentId())), $seconds);
        else
            return (bool)$this->redis->expireAt($this->getKeyName(array('hash', $this->getCurrentId())), $seconds);
    }

    public function getTTL($fieldName)
    {
        $this->init();
        $iVal = (int)$this->redis->ttl($this->getKeyName(array('table', $this->getCurrentId(), $fieldName)));

        return $iVal > 0 ? $iVal : 0;
    }

    public function getHashTTL()
    {
        $this->init();
        $iVal = (int)$this->redis->ttl($this->getKeyName(array('hash', $this->getCurrentId())));

        return $iVal > 0 ? $iVal : 0;
    }

    public function setHash($fieldName, $value)
    {
        $this->init();

        //Превращаем в string, чтобы было тоже, что
        //вернет redis впоследствии
        if(isset($this->_aHashData))
            $this->_aHashData[$fieldName] = (string)$value;

        //TODO: Если включен режим _aHashData, то реализовать отложенную запись?
        $this->redis->hSet($this->getKeyName(array('hash', $this->getCurrentId())), $fieldName, $value);
    }

    public function setHashAll($aAssoc)
    {
        $this->init();
        $ret = $this->redis->hMset(
            $this->getKeyName(array('hash', $this->getCurrentId())),
            (array)$aAssoc
        );

        return $ret;
    }

    public function getHash($fieldName, $bReadAll = false)
    {
        $this->init();
        if(!$bReadAll)
            $value = $this->redis->hGet($this->getKeyName(array('hash', $this->getCurrentId())), $fieldName);
        else
        {
            //Читает все
            if(!isset($this->_aHashData))
                $this->_aHashData = $this->getHashAll();

            return $this->_aHashData[$fieldName];
        }

        return $value;
    }

    /**
     * Возвращает ассоциативный массив данных из хэша в виде ключ-значение
     * @return array
     */
    public function getHashAll()
    {
        $this->init();

        return $this->redis->hGetAll($this->getKeyName(array('hash', $this->getCurrentId())));

    }

    public function delHash($fieldName)
    {
        $this->init();
        $this->redis->hDel($this->getKeyName(array('hash', $this->getCurrentId())), $fieldName);
        if(isset($this->_aHashData))
            unset($this->_aHashData[$fieldName]);
    }

    public function cleanHash()
    {
        $this->init();
        $ret = $this->redis->del($this->getKeyName(array('hash', $this->getCurrentId()))) == 1;

        return $ret;
    }

    protected function createNewId()
    {
        $this->init();

        if($this->currentId)
            throw new Exception('У вас уже имеется ключ записи');

        $this->currentId = $this->redis->incr($this->getKeyName('counter'));

        return $this->currentId;
    }

    private function init($bLoadObject = false)
    {
        //Не проверять $this->redis! Всегда нужен getInstance, иначе в режиме daemon будут проблемы

        //Чтобы list не выдавал Notice, когда параметры переданы не все
        $aConnectionData = array_merge($this->storageConnect(), ['Default', 'localhost', 6379, 0]);

        list($sStoreName, $sServerIp, $iServerPort, $iDbIndex) = $aConnectionData;

        $iDbIndex = (int)$iDbIndex;

        if(!$sStoreName || !$sServerIp || !$iServerPort || $iDbIndex > 9)
            throw new Exception('Не указана или неверна информация о соединении');

        $this->redis = Redis::getInstance($sServerIp, $iServerPort, $iDbIndex);
        if ($iDbIndex)
            $this->redis->select($iDbIndex);

        $this->className = $sStoreName;

        $this->_sRedisPrefix = self::REDIS_PREFIX;
        if(!$this->_sRedisPrefix)
            throw new Exception('Не задан REDIS_PREFIX');
    }

    abstract function storageConnect();


    //TODO: Недоделано пересечение классических сетов

    /**
     * Возвращает строку с названием ключа.
     * Если путь длиннее 1 значения, может быть передан в массиве
     */
    private function getKeyName($keys)
    {
        if(!is_array($keys))
            $keys = array($keys);

        $sKey = implode('::', array_merge(array($this->_sRedisPrefix, $this->className), $keys));

        return $sKey;
    }

    protected function getCounter()
    {
        $this->init();

        return $this->redis->get($this->getKeyName('counter'));
    }

    /**
     * Добавляет в лист слева ID объекта или скалярное значение.
     * ID объектов могут повторяться, но при запросе будут в правильном порядке
     *
     * @param mixed $fieldName
     * @param mixed $object
     *
     * @return int
     */
    protected function attachLeft($fieldName, $object)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));


        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        return $this->redis->lPush($keyName, $fieldValue);
    }

    protected function attachRight($fieldName, $object)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));


        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        $this->redis->rPush($keyName, $fieldValue);


        return $this;
    }

    protected function replaceListItem($fieldName, $index, $object)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        $this->redis->lSet($keyName, $index, $fieldValue);

        return $this;
    }

    protected function lRem($fieldName, $object, $count = 1)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        $this->redis->lRem($keyName, $fieldValue, $count);

        return $this;
    }

    protected function getRangeObjects($fieldName, $firstIndex = 0, $lastIndex = -1, $objectName = null)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $fieldValue = $this->redis->lRange($keyName, $firstIndex, $lastIndex);


        $ret = array();

        foreach($fieldValue as $v)
        {
            if(!is_null($objectName))
                $ret[] = $this->getObjectConstructor($objectName, $v); //new $objectName($v);
            else
                $ret[] = $v;
        }


        return $ret;
    }

    private function getObjectConstructor($sObjectConstructor, $sValue)
    {
        $aArguments = array($sValue);

        if(is_array($sObjectConstructor))
        {
            //Можно инстанцировать объект с доп. аргументами, переданными в массиве

            $aData = $sObjectConstructor;
            $sObjectConstructor = array_shift($aData);

            //Теперь в $aData доп. аргументы для вызова. Нам они нужны следом за $sValue
            $aArguments = array_merge($aArguments, $aData);
        }

        if(strpos($sObjectConstructor, '::'))
        {
            $aSplitter = explode('::', $sObjectConstructor);

            return call_user_func_array($aSplitter, $aArguments);
        }
        else
            return new $sObjectConstructor($sValue);
    }

    /**
     * Удаляет один или несколько объектов, если известен их индекс и конец от начала.
     *
     * @param $fieldName
     * @param $iStart
     * @param null $iStop - Можно не передавать и будет удален один объект
     *
     * @return bool Истина, если все хорошо
     */
    protected function removeRangeObjects($fieldName, $iStart, $iStop = null)
    {
        $this->init();

        $iStart = (int)$iStart;
        if(is_null($iStop))
            $iStop = $iStart;
        else
            $iStop = (int)$iStop;

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        return (bool)$this->redis->lTrim($keyName, $iStart, $iStop);
    }

    protected function popObject($fieldName, $objectName = null)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $fieldValue = $this->redis->lPop($keyName);

        if(!$fieldValue)
            return null;

        if(is_null($objectName))
            return $fieldValue;

        return new $objectName($fieldValue);
    }

    protected function getObject($fieldName, $index = 0, $objectName = null)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $fieldValue = $this->redis->lIndex($keyName, $index);

        if(!$fieldValue)
            return null;

        if(is_null($objectName))
            return $fieldValue;

        return new $objectName($fieldValue);
    }

    /**
     * Возвращает количество прикрепленных attachLeft-ом объектов
     *
     * @param mixed $fieldName Имя поля
     *
     * @return int
     */
    protected function getLenght($fieldName)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        $fieldValue = (int)$this->redis->llen($keyName);

        return $fieldValue;
    }

    /**
     * В случае массового добавления в scoreMode, ключем являются member, очками — score
     *
     * @param $sFieldName
     * @param $aList
     * @param bool $iScoreMode
     *
     * @return $this
     */
    public function multiAttach($sFieldName, $aList, $iScoreMode = false)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $sFieldName));

        if(!count($aList))
            return;

        //Задача не из легких — много команд или в большую команду (не ниже Redis 2.4)
        //Будем добавлять по 500 штук в multi

        $fSendBuffer = function($r) use ($iScoreMode, &$aBuffer, &$iCounter, $keyName)
        {
            if (count($aBuffer) > 1)
                call_user_func_array(array($r, $iScoreMode ? 'zAdd' : 'sAdd'), $aBuffer);

            $iCounter = 0;
            $aBuffer = [$keyName];
        };

        //instance $aBuffer, $iCounter
        $fSendBuffer($this->redis);

        $bNeedMulti = count($aList) > 500;

        if ($bNeedMulti)
            $this->redis->multi();

        foreach($aList as $k=>$v)
        {
            if ($iCounter == 500)
                $fSendBuffer($this->redis);

            //Чтобы это понять, читайте доку по zAdd, sAdd
            $aBuffer[] = $v;
            if($iScoreMode)
                $aBuffer[] = $k;

            $iCounter++;
        }

        //Довыполним буффер
        $fSendBuffer($this->redis);

        /*if(!$iScoreMode)
            $this->redis->sAdd($keyName, $v);
        else
            $this->redis->zAdd($keyName, $v, $k);*/

        if ($bNeedMulti)
            $this->redis->exec();

        return $this;
    }

    protected function zRem($fieldName, $object)
    {
        $this->init();
        $this->detach($fieldName, $object, true);
    }

    public function detach($fieldName, $object, $bHaveScore = false)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        if(!$bHaveScore)
            return $this->redis->sRem($keyName, $fieldValue);

        return $this->redis->zRem($keyName, $fieldValue);
    }

    protected function zRemRangeByRank($fieldName, $iStart, $iStop)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        return (int)$this->redis->zRemRangeByRank($keyName, $iStart, $iStop);
    }

    protected function zRemRangeByScore($fieldName, $iMin, $iMax)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        return (int)$this->redis->zRemRangeByScore($keyName, $iMin, $iMax);
    }

    protected function moveAttached($sSourceFieldName, $sDestinationFieldName, $object)
    {
        $this->init();

        $sSourceFieldName = $this->getKeyName(array('table',
                                                    $this->getCurrentId(), $sSourceFieldName));
        $sDestinationFieldName = $this->getKeyName(array('table',
                                                         $this->getCurrentId(), $sDestinationFieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;


        return (bool)$this->redis->sMove($sSourceFieldName, $sDestinationFieldName, $fieldValue);
    }

    protected function isMember($fieldName, $object)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(is_object($object))
            $fieldValue = $object->getCurrentId();
        else
            $fieldValue = $object;

        $ret = $this->redis->sIsMember($keyName, $fieldValue);

        //TODO: Check
        return (bool)$ret;
    }

    /**
     * Возвращает как много объектов в неупорядоченном массиве
     * attach
     *
     * @param mixed $fieldName
     * @param bool $bScore
     *
     * @internal param \Io\type $objectName
     * @return int
     */
    public function getAttachedObjectsCount($fieldName, $bScore = false)
    {
        $this->init();

        $keyName = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));

        if(!$bScore)
            return (int)$this->redis->sCard($keyName);
        else
            return (int)$this->redis->zCard($keyName);
    }

    protected function decrementField($fieldName, $iDecrement = 1)
    {
        $this->init();
        $sKey = $this->getKeyName(array('table', $this->getCurrentId(), $fieldName));
        $iDecrement = (int)$iDecrement;

        if(!$iDecrement) //0
            return $this->getField($fieldName);

        if($iDecrement == 1)
            return $this->redis->decr($sKey);
        else
            return $this->redis->decrBy($sKey, $iDecrement);
    }
}
