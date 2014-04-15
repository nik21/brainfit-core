<?php
/**
 * Created by PhpStorm.
 * User: urushev
 * Date: 16.11.13
 * Time: 7:58
 */

namespace Brainfit\Io\Data;

class StorageTestProvider extends Storage
{
    public function storageConnect()
    {
        return ['test5', '127.0.0.1', 6379, 5];
    }

    public function getCurrentId()
    {
        return 1;
    }
}

class StorageTest extends \PHPUnit_Framework_TestCase
{
    public function test1LocalRedisConnection()
    {
        $obRedis = new \Redis();
        $obRedis->connect('127.0.0.1', 6379);

        //Запрещаем запуск, если есть ключи в db5
        $aInfo = $obRedis->info('keyspace');
        if (isset($aInfo['db5']))
            throw new \PHPUnit_Framework_Exception('Tested redis db not empty!');

        $obRedis->select(5);


        $obRedis->set('a', 1);
        $this->assertEquals(1, $obRedis->get('a'));
        $obRedis->del('a');
    }

    public function test2SimpleTest()
    {
        $obTest = new StorageTestProvider();
        $obTest->setField('ft1', 12);
        $obTest->delField('ft1');
    }

    /**
     * УДостоверимся, что sAdd и zAdd через метод multiAttach способны добавлять верное кол-во элементов
     * с мульти-вставкой и без нее
     */
    public function test3MultiSAdd()
    {
        $aVariants = [0, 1, 2, 5,10,20,100,200,499,500,501,999,1000,1001,1200,1500,2000,2001];

        foreach($aVariants as $iVariant)
        {
            //Придумаем 1200 объектов
            $aObjects = [];
            for($i=1;$i<=$iVariant;$i++)
                $aObjects[] = $i;

            $obTest = new StorageTestProvider();
            $obTest->multiAttach('sadd1', $aObjects);

            $this->assertEquals($obTest->getAttachedObjectsCount('sadd1'), $iVariant);

            $obTest->delField('sadd1');
        }
    }

    public function test4MultiZAdd()
    {
        $aVariants = [5,10,20,100,200,499,500,501,999,1000,1001,1200,1500,2000,2001];

        foreach($aVariants as $iVariant)
        {
            //Придумаем 1200 объектов
            $aObjects = [];
            for($i=1;$i<=$iVariant;$i++)
                $aObjects[$i] = mt_rand(1,10000);

            $obTest = new StorageTestProvider();
            $obTest->multiAttach('zadd1', $aObjects, true);

            $this->assertEquals($obTest->getAttachedObjectsCount('zadd1', true), $iVariant);

            $obTest->delField('zadd1');
        }
    }
}
