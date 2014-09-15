<?php
namespace Brainfit\Io\Data;

use Brainfit\Io\Data\Drivers\Apc;
use Brainfit\Model\Exception;
use Brainfit\Settings;
use Brainfit\Util\Debugger;
use PDO;

/**
 * Class Query
 * @package Io\Data
 */
class Query
{
    private $sServerName;

    public static $profilingInfo = [];

    /** @var  \PDO */
    private static $dbh = [];

    /** @var  \PDOStatement */
    private $obStmp;

    //escape-builder:
    private $aLoadedValues = null;
    private $iPointer = 0;

    //query builder:
    private $aBuilder = [];
    private $aExecuteValues = [];
    private $iExecuteValueCounter = 0; //params id's counter

    //result:
    private $iCalcFoundRows = 0;


    public function __construct($sServerName)
    {
        $this->sServerName = $sServerName;
    }

    /**
     * @param string $sServerName
     *
     * @return self
     */
    public static function create($sServerName = 'main')
    {
        return new self($sServerName);
    }

    /**
     * @return \PDO
     * @throws \Brainfit\Model\Exception
     */
    private function getPdo()
    {
        if(!isset(self::$dbh[$this->sServerName]))
        {
            if(!$sServer = Settings::get('MYSQL', 'servers', $this->sServerName, 'server'))
                throw new Exception('Mysql server with id ' . $this->sServerName . ' not found in config file');

            $sUser = Settings::get('MYSQL', 'servers', $this->sServerName, 'login');
            $sPassword = Settings::get('MYSQL', 'servers', $this->sServerName, 'password');
            $sDefaultDb = Settings::get('MYSQL', 'servers', $this->sServerName, 'db');

            try
            {
                //$driver_options = [PDO::MYSQL_ATTR_INIT_COMMAND => "SET names = 'utf-8', lc_time_names = 'ru_RU',
                //time_zone = 'Europe/Moscow'"];

                $driver_options = [PDO::ATTR_PERSISTENT => false, PDO::ATTR_EMULATE_PREPARES => false];

                //not usage MYSQL_ATTR_INIT_COMMAND and SET character_set_results = 'utf8' and other.. This is not safe!
                self::$dbh[$this->sServerName] = new \PDO("mysql:host={$sServer};dbname={$sDefaultDb};charset=UTF8", $sUser, $sPassword,
                    $driver_options);
            }
            catch (\PDOException $e)
            {
                throw new Exception($e->getMessage(), $e->getCode());
            }
        }

        return self::$dbh[$this->sServerName];
    }

    private function escape2($variable)
    {
        //TODO: Check long and negative numbers

        if(is_numeric($variable))
            return $this->escapeInt($variable);
        else if(is_string($variable))
            return $this->addNamedVariable($variable);
        else if (is_bool($variable))
            return $variable ? 1 : 0;
        else
            throw new Exception('Invalid variable');
    }

    public function calcFoundRows()
    {
        $this->aBuilder['params']['foundRows'] = true;

        return $this;
    }

    public function select(/** @noinspection PhpUnusedParameterInspection */
        $sField1, $sField2 = null, $sFieldN = null)
    {
        for ($i = 0; $i < func_num_args(); $i++)
            $this->aBuilder['fields'][] = func_get_arg($i);

        return $this;
    }

    public function distinct()
    {
        $this->aBuilder['isDistinct'] = true;

        return $this;
    }

    /**
     * @param      $sTableName
     * @param null $sSuffix
     *
     * @return $this
     * @throws \Brainfit\Model\Exception
     */
    public function from($sTableName, $sSuffix = null)
    {
        if(strpos($sTableName, ' ') !== false)
            throw new Exception('Unacceptable gap in "from"');

        $this->aBuilder['tables'][] = [
            'name' => $this->escapeTableName($sTableName),
            'suffix' => $sSuffix
        ];

        return $this;
    }

    private function variable_check2($params)
    {
        $this->iPointer++;

        $value = & $this->aLoadedValues[$this->iPointer - 1];

        if($params[1] == '%i')
            return $this->escapeInt($value);
        elseif($params[1] == '%s')
            return $this->addNamedVariable($value);
        elseif($params[1] == '%a')
            return $this->createIN($value);
        /*elseif($params[1] == '%w')
            return $value;*/
        else
            throw new Exception('Invalid variable in query-builder'); //return '?';
    }

    public function groupBy($sVariable)
    {
        $this->aBuilder['groupBy'][] = $sVariable;

        return $this;
    }

    public function where($sVariable, /** @noinspection PhpUnusedParameterInspection */
                          $sValue1 = null, /** @noinspection PhpUnusedParameterInspection */
                          $sValue2 = null, /** @noinspection PhpUnusedParameterInspection */
                          $sValueN = null)
    {
        $this->aLoadedValues = [];
        $this->iPointer = 0;

        for ($i = 1; $i < func_num_args(); $i++)
            $this->aLoadedValues[] = func_get_arg($i);

        $sVariable = preg_replace_callback("/(%[siaw])/", [$this, 'variable_check2'], $sVariable);

        $this->aBuilder['where'][] = '(' . $sVariable . ')';

        return $this;
    }


    public function having($sVariable, /** @noinspection PhpUnusedParameterInspection */
                           $sValue1 = null, /** @noinspection PhpUnusedParameterInspection */
                           $sValue2 = null, /** @noinspection PhpUnusedParameterInspection */
                           $sValueN = null)
    {
        $this->aLoadedValues = [];
        $this->iPointer = 0;

        for ($i = 1; $i < func_num_args(); $i++)
            $this->aLoadedValues[] = func_get_arg($i);

        $sVariable = preg_replace_callback("/(%[siaw])/", [$this, 'variable_check2'], $sVariable);

        $this->aBuilder['having'][] = '(' . $sVariable . ')';

        return $this;
    }

    public function join($sDirection, $sTableName, $sSuffix,
                         $sVariable, /** @noinspection PhpUnusedParameterInspection */
                         $sValue1 = null, /** @noinspection PhpUnusedParameterInspection */
                         $sValue2 = null, /** @noinspection PhpUnusedParameterInspection */
                         $sValueN = null)
    {
        if(!in_array($sDirection, ['left', 'right', 'inner']))
            throw new Exception('Invalid direction');

        $this->aLoadedValues = [];
        $this->iPointer = 0;

        for ($i = 3; $i < func_num_args(); $i++)
            $this->aLoadedValues[] = func_get_arg($i);

        $sVariable = preg_replace_callback("/(%[siaw])/", [$this, 'variable_check2'], $sVariable);

        $this->aBuilder['joins'][] = $sDirection . ' join ' .
            $this->escapeTableName($sTableName) . ' ' . $sSuffix . ' on ' . $sVariable;

        return $this;
    }

    /**
     * Convert "db.table1" to "`db`.`table1`
     *
     * @param $sTable
     *
     * @return string
     */
    private function escapeTableName($sTable)
    {
        $newTablesArray = [];
        foreach (explode('.', $sTable) as $t)
            $newTablesArray[] = '`' . $t . '`';

        return implode('.', $newTablesArray);
    }


    private function escapeInt($value)
    {
        if($value === null)
            return 'NULL';

        if(!is_numeric($value))
            throw new Exception("Integer (?i) placeholder expects numeric value, " . gettype($value) . " given");

        if(is_float($value))
            return number_format($value, 0, '.', ''); // may lose precision on big numbers

        return $value;
    }

    private function createIN($data)
    {
        if(!is_array($data))
            throw new Exception("Value for IN (?a) placeholder should be array");

        if(!$data)
            return 'NULL';

        $query = $comma = '';
        foreach ($data as $value)
        {
            $query .= $comma . $this->addNamedVariable($value);
            $comma = ",";
        }

        return $query;
    }

    private function addNamedVariable($value)
    {
        $this->iExecuteValueCounter++;
        $this->aExecuteValues[':strim' . $this->iExecuteValueCounter] = $value;

        return ':strim' . $this->iExecuteValueCounter;
    }

    public function orderBy($sFieldName, $sOrder = 'ASC')
    {
        $this->aBuilder['orderBy'][] = $sFieldName . ' ' . $sOrder;

        return $this;
    }

    public function limit($iLimit = null, $iOffset = null)
    {
        if(!is_null($iOffset))
            $this->aBuilder['params']['offset'] = (int)$iOffset;

        if(!is_null($iLimit))
            $this->aBuilder['params']['limit'] = (int)$iLimit;

        return $this;
    }

    /**
     * @param $iTime int        When -1 — existing cache cleaning
     *
     * @return $this
     */
    public function cache($iTime)
    {
        $this->aBuilder['params']['cache'] = $iTime;

        return $this;
    }


    public function execute($sSqlStatement)
    {
        $this->aBuilder['statements'][] = $sSqlStatement;

        return $this;
    }

    private function prepareSql($mainAction)
    {
        if ($this->aBuilder['director'])
            throw new Exception('you use director mode');

        $sResultSql = '';

        //Collect tables
        $aTablesList = [];
        foreach ($this->aBuilder['tables'] as $aTableItem)
            $aTablesList[] = $aTableItem['name'] . ' ' . $aTableItem['suffix'];


        if($mainAction == 'insert')
        {
            $sResultSql = 'INSERT ' . ($this->aBuilder['isIgnoreInsert'] ? 'IGNORE ' : '') . 'INTO '
                . implode(', ', $aTablesList);

            $sResultSql .= '(' . implode(', ', array_keys($this->aBuilder['set'])) . ') VALUES ('
                . implode(', ', array_values($this->aBuilder['set'])) . ')';

            if($this->aBuilder['isUpdateInsert'])
            {
                $aSubBuilder = [];
                foreach (array_keys($this->aBuilder['set']) as $sField)
                    $aSubBuilder[] = "{$sField} = VALUES({$sField})";

                $sResultSql .= $aSubBuilder ? ' ON DUPLICATE KEY UPDATE ' . implode(', ', $aSubBuilder) : '';
            }
        }
        else if($mainAction == 'update')
        {
            $sResultSql = 'UPDATE ' . implode(', ', $aTablesList) . ' SET ';

            $aSubBuilder = [];
            foreach ($this->aBuilder['set'] as $sField => $sValue)
                $aSubBuilder[] = "{$sField} = {$sValue}";

            $sResultSql .= implode(', ', $aSubBuilder);
        }
        else if($mainAction == 'select')
        {
            if($this->aBuilder['statements'])
                $sResultSql = implode(' ', $this->aBuilder['statements']);
            else
                $sResultSql = 'SELECT ' . ($this->aBuilder['isDistinct'] ? 'DISTINCT ' : '')
                    . implode(', ', $this->aBuilder['fields']);

            if ($aTablesList)
                $sResultSql .= ' FROM ' . implode(', ', $aTablesList);
        }
        else if($mainAction == 'delete')
        {
            $sResultSql = 'DELETE ';
            $sResultSql .= ' FROM ' . implode(', ', $aTablesList);
        }


        //collect join-expressions
        if($this->aBuilder['joins'])
            $sResultSql .= ' ' . implode(' ', $this->aBuilder['joins']);

        //collect where-expressions
        if($this->aBuilder['where'])
            $sResultSql .= ' WHERE ' . implode(' AND ', $this->aBuilder['where']);

        //collect having-expressions
        if($this->aBuilder['having'])
            $sResultSql .= ' HAVING ' . implode(' AND ', $this->aBuilder['having']);

        //collect group-by expressions
        if($this->aBuilder['groupBy'])
            $sResultSql .= ' GROUP BY ' . implode(', ', $this->aBuilder['groupBy']);

        //collect order-by expressions
        if($this->aBuilder['orderBy'])
            $sResultSql .= ' ORDER BY ' . implode(', ', $this->aBuilder['orderBy']);

        if($this->aBuilder['params']['limit'] && $this->aBuilder['params']['offset'])
            $sResultSql .= " LIMIT " . $this->aBuilder['params']['offset'] . ", " . $this->aBuilder['params']['limit'];
        elseif($this->aBuilder['params']['limit'])
            $sResultSql .= " LIMIT " . $this->aBuilder['params']['limit'];

        return $sResultSql;
    }

    private function prepareResult($sSql)
    {
        if(!$sSql)
            throw new Exception('Invalid call sequence');

        //maybe from cache
        $sCacheKey = sha1($sSql . implode(array_values($this->aExecuteValues)));
        $iCache = (int)$this->aBuilder['params']['cache'];
        $obApc = Apc::getInstance();

        if($iCache > 0 && !$obApc->add($sCacheKey, 1, $iCache))
        {
            $aResult = (array)$obApc->fetch($sCacheKey . 'v');
            $this->iCalcFoundRows = (int)$obApc->fetch($sCacheKey . 'f');

            $this->saveForProfiling($sSql, 'from cache');

            //debug:
            if($sDumpName = $this->aBuilder['params']['getDump'])
                Debugger::clientLog('HIDDEN' . ($sDumpName ? 'sql' : $sDumpName) . ' [cache]: ' . $sSql,
                    $aResult);

            return $aResult;
        }

        if($iCache == -1)
        {
            $obApc->delete([$sCacheKey . 'v', $sCacheKey . 'f']);
            $this->saveForProfiling($sSql, 'clean cache query');
        }
        else
            $this->saveForProfiling($sSql, 'direct query');

        //Begin
        $this->CreateQuery($sSql, !$this->aBuilder['params']['foundRows'], $this->aExecuteValues);

        $aResult = [];

        //field types
        $aFields = [];
        for ($i = 0; $i < 1000; $i++)
        {
            if(!$aMetaInfo = $this->obStmp->getColumnMeta($i))
                break;

            $aFields[$aMetaInfo['name']] = $aMetaInfo['native_type']; //pdo_type
        }

        $k = 0;
        while ($row = $this->obStmp->fetch(PDO::FETCH_ASSOC))
        {
            $aResult[$k] = $row;

            //Change some rows types:
            foreach ($aFields as $field => $type)
            {
                switch ($type)
                {
                    // Convert INT to an integer.
                    case 'LONG':
                    case 'LONGLONG':
                    case 'TINY':
                    case 'SHORT':
                    case 'INT24':
                        $aResult[$k][$field] = intval($aResult[$k][$field]);
                        break;
                    // Convert FLOAT to a float.
                    case 'FLOAT':
                    case 'NEWDECIMAL':
                    case 'DOUBLE':
                        $aResult[$k][$field] = floatval($aResult[$k][$field]);
                        break;
                    case 'TIMESTAMP':
                    case 'DATE':
                    case 'DATETIME':
                        /*$obTemp = new \DateTime($aResult[$k][$field->name]);
                        $aResult[$k][$field->name] = $obTemp->getTimestamp();*/
                        break;
                    case 'VAR_STRING':
                    case 'STRING':
                    case 'BLOB';
                        break;
                    default:
                        //echo $type.'|';
                        break;
                }
            }

            $k++;
        }

        if($sDumpName = $this->aBuilder['params']['getDump'])
            Debugger::clientLog('HIDDEN' . ($sDumpName ? 'sql' : $sDumpName) . ': [query]: ' . $sSql, $aResult);

        if($iCache)
        {
            $obApc->store($sCacheKey . 'v', $aResult, $iCache * 2);
            $obApc->store($sCacheKey . 'f', $this->iCalcFoundRows, $iCache * 2);
        }

        return $aResult;
    }

    private function saveForProfiling($sSql, $sGroupName)
    {
        self::$profilingInfo[$this->sServerName][$sGroupName][] = $sSql;
    }

    public function dump($sDescription = 'query')
    {
        $this->aBuilder['params']['getDump'] = $sDescription;

        return $this;
    }

    private function CreateQuery($strSQLQuery, $foundRowsDisable = false, $params = [])
    {
        if(!$strSQLQuery)
            return 0;

        $calc_found_rows_enable = 0;

        if(!$foundRowsDisable)
        {
            if(mb_eregi('LIMIT ', $strSQLQuery) && mb_eregi('^SELECT', $strSQLQuery))
            {
                //Если включено лимитирование и это инструкция SELECT, то посчитать, при условии разрешения
                $calc_found_rows_enable = 1;

                $strSQLQuery = mb_eregi_replace("(^SELECT)(.*)", "SELECT SQL_CALC_FOUND_ROWS\\2", $strSQLQuery);
            }
        }


        try
        {
            if(!$stmt = self::getPdo()->prepare($strSQLQuery))
            {
                Debugger::log('Query syntax problem:'.$strSQLQuery);
                throw new Exception('Query syntax problem');
            }

            $stmt->execute($params);
            $this->obStmp = $stmt;

            if($calc_found_rows_enable)
            {
                $sql = "select found_rows();";
                if(!$stmt2 = self::getPdo()->query($sql))
                    throw new Exception('Problem when prepare "found_rows" query');
                $this->iCalcFoundRows = (int)$stmt2->fetch(PDO::FETCH_COLUMN);
                $stmt2->closeCursor();
            }
        }
        catch (\PDOException $e)
        {
            Debugger::log('Mysql error:', $e->getMessage(), 'Query:', $strSQLQuery);
            throw new Exception('Mysql error #' . $e->getCode(), 1);
        }

        return 1;
    }

    public function director()
    {
        $this->aBuilder['director'] = true;
        return $this->getPdo();
    }

    public function delete()
    {
        $sSql = $this->prepareSql('delete');

        $this->CreateQuery($sSql, true, $this->aExecuteValues);
    }

    public function set($field, $value = null)
    {
        if (is_array($field))
        {
            //multi-set
            foreach($field as $k=>$v)
                $this->set($k, $v);

            return $this;
        }

        $this->aBuilder['set']['`' . $field . '`'] = $this->escape2($value);
        return $this;
    }

    /**
     * After insert you must use "getLastInsertId()" or "getRowCount()" method
     *
     * @param      $table
     * @param bool $update
     * @param bool $ignore
     *
     * @throws \Brainfit\Model\Exception
     */
    public function insert($table, $update = false, $ignore = false)
    {
        if(!$this->aBuilder['set'])
            throw new Exception('Builder: not found "set" fields');

        $this->aBuilder['isIgnoreInsert'] = (bool)$ignore;
        $this->aBuilder['isUpdateInsert'] = (bool)$update;
        $this->aBuilder['tables'] = [['name' => $this->escapeTableName($table)]];

        $sSql = $this->prepareSql('insert');
        $this->prepareResult($sSql);

    }

    public function update($table)
    {
        if(!$this->aBuilder['set'])
            throw new Exception('Builder: not found "set" fields');

        if(!$this->aBuilder['where'])
            throw new Exception('Builder: not found "where" fields');

        $this->aBuilder['tables'] = [['name' => $this->escapeTableName($table)]];

        $sSql = $this->prepareSql('update');
        $this->prepareResult($sSql);
    }

    public function get($sType = 'matrix')
    {
        $sSql = $this->prepareSql('select');
        $aResult = $this->prepareResult($sSql);

        if($sType == 'first')
            return $aResult[0];
        elseif($sType == 'array')
        {
            $aRet = [];
            foreach ($aResult as $aItem)
                $aRet[] = $aItem[array_keys($aItem)[0]];

            return $aRet;
        }
        elseif($sType == 'assoc')
        {
            $aRet = [];
            foreach ($aResult as $aItem)
                $aRet[array_values($aItem)[0]] = count($aItem) == 2 ? array_values($aItem)[1] : $aItem;

            return $aRet;
        }

        return (array)$aResult;
    }

    /**
     * @param callable $obLoader
     * @param null     $sFieldNameForFactory
     *
     * @return array
     */
    public function load(callable $obLoader, $sFieldNameForFactory = null)
    {
        $sSql = $this->prepareSql('select');
        $aResult = $this->prepareResult($sSql);

        $aRet = [];
        foreach ($aResult as $v)
        {
            if(!is_null($sFieldNameForFactory))
                $aRet[] = $obLoader($v[$sFieldNameForFactory]);
            else
                $aRet[] = $obLoader($v);
        }

        return $aRet;
    }

    public function getFoundRows()
    {
        return $this->iCalcFoundRows;
    }

    public function getLastInsertId()
    {
        return self::getPdo()->lastInsertId();
    }

    public function getRowCount()
    {
        return $this->obStmp->rowCount();
    }
}