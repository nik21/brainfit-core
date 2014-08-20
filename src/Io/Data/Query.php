<?php
namespace Brainfit\Io\Data;

use PDO;
use Brainfit\Io\Data\Drivers\Apc;
use Brainfit\Model\Exception;
use Brainfit\Settings;
use Brainfit\Util\Debugger;

/**
 * Class Query
 * @package Io\Data
 */
class Query
{
    private $sServerName;

    public static $profilingInfo = [];

    /** @var  \PDO */
    private static $dbh = null;

    /** @var  \PDOStatement */
    private $stmp;
    private $_foundRows;
    private $aExecuteValues = null;

    private $aTables = [];
    private $aFields = [];
    private $bDistinct = false;
    private $sWhere = null;
    private $sJoins = null;
    private $sHaving = null;
    private $sResultSql = null;
    private $aResult = null;
    private $aOtherParams = [];
    private $aOrderBy = [];
    private $aGroupBy = [];

    private $aLoadedValues = null;
    private $iPointer = 0;

    private $iCalcFoundRows = 0;

    public function __construct($sServerName)
    {
        $this->sServerName = $sServerName;
    }

    /**
     * @param string $sServerName
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
        if (is_null(self::$dbh))
        {
            if (!$sServer = Settings::get('MYSQL', 'servers', $this->sServerName, 'server'))
                throw new Exception('Mysql server with id '.$this->sServerName.' not found in config file');

            $sUser = Settings::get('MYSQL', 'servers', $this->sServerName, 'login');
            $sPassword = Settings::get('MYSQL', 'servers', $this->sServerName, 'password');
            $sDefaultDb = Settings::get('MYSQL', 'servers', $this->sServerName, 'db');
            $bUseUtf8 = Settings::get('MYSQL', 'switchToUTF8');

            try
            {
                //$driver_options = [PDO::MYSQL_ATTR_INIT_COMMAND => "SET names = 'utf-8', lc_time_names = 'ru_RU',time_zone = 'Europe/Moscow'"];
                $driver_options = [PDO::ATTR_PERSISTENT => false];

                //not usage MYSQL_ATTR_INIT_COMMAND and SET character_set_results = 'utf8' and other.. This is not safe!
                self::$dbh = new \PDO("mysql:host={$sServer};dbname={$sDefaultDb};charset=UTF8", $sUser, $sPassword,
                    $driver_options);
            }
            catch(\PDOException $e) {
                throw new Exception($e->getMessage(), $e->getCode());
            }
        }

        return self::$dbh;
    }

    public function escape($data)
    {
        //see http://stackoverflow.com/questions/134099/are-pdo-prepared-statements-sufficient-to-prevent-sql-injection
        mysql_query('SET NAMES utf8');
        return mysql_real_escape_string($data);
        //Подключение к БД может снижать производительность
        return self::getPdo()->quote($data);
    }

    public function calcFoundRows()
    {
        $this->aOtherParams['foundRows'] = true;

        return $this;
    }

    public function select(/** @noinspection PhpUnusedParameterInspection */
        $sField1, $sField2 = null, $sFieldN = null)
    {
        for($i = 0; $i < func_num_args(); $i++)
            $this->aFields[] = func_get_arg($i);

        return $this;
    }

    public function distinct()
    {
        $this->bDistinct = true;

        return $this;
    }

    /**
     * @param $sTableName
     * @param null $sSuffix
     * @return $this
     * @throws \Brainfit\Model\Exception
     */
    public function from($sTableName, $sSuffix = null)
    {
        if(strpos($sTableName, ' ') !== false)
            throw new Exception('Unacceptable gap in "from"');

        $newTablesArray = [];
        foreach(explode('.', $sTableName) as $t)
            $newTablesArray[] = '`'.$t.'`';

        $sTableName = implode('.', $newTablesArray);

        $this->aTables[] = [
            'name' => $sTableName,
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
            return $this->escapeString($value);
        elseif($params[1] == '%a')
            return $this->createIN($value);
        elseif($params[1] == '%w')
            return $value;
        else
            return '?';
    }

    public function groupBy($sVariable)
    {
        $this->aGroupBy[] = $sVariable;

        return $this;
    }

    public function where($sVariable, /** @noinspection PhpUnusedParameterInspection */
                          $sValue1 = null, /** @noinspection PhpUnusedParameterInspection */
                          $sValue2 = null, /** @noinspection PhpUnusedParameterInspection */
                          $sValueN = null)
    {
        $this->aLoadedValues = [];
        $this->iPointer = 0;

        for($i = 1; $i < func_num_args(); $i++)
            $this->aLoadedValues[] = func_get_arg($i);

        $sVariable = preg_replace_callback("/(%[siaw])/", [$this, 'variable_check2'], $sVariable);

        $this->sWhere .= ($this->sWhere ? ' AND ' : '').'('.$sVariable.')';

        return $this;
    }

    public function join($sDirection, $sTableName, $sSuffix, $sVariable, /** @noinspection PhpUnusedParameterInspection */
                         $sValue1 = null, /** @noinspection PhpUnusedParameterInspection */
                         $sValue2 = null, /** @noinspection PhpUnusedParameterInspection */
                         $sValueN = null)
    {
        if(!in_array($sDirection, ['left', 'right', 'inner']))
            throw new Exception('Invalid direction');

        $newTablesArray = [];
        foreach(explode('.', $sTableName) as $t)
            $newTablesArray[] = '`'.$t.'`';

        $sTableName = implode('.', $newTablesArray);

        $this->aLoadedValues = [];
        $this->iPointer = 0;

        for($i = 3; $i < func_num_args(); $i++)
            $this->aLoadedValues[] = func_get_arg($i);

        $sVariable = preg_replace_callback("/(%[siaw])/", [$this, 'variable_check2'], $sVariable);

        $this->sJoins .= ' '. $sDirection.' join '.$sTableName.' '.$sSuffix.' on '.$sVariable;

        return $this;
    }

    public function having($sVariable, /** @noinspection PhpUnusedParameterInspection */
                           $sValue1 = null, /** @noinspection PhpUnusedParameterInspection */
                           $sValue2 = null, /** @noinspection PhpUnusedParameterInspection */
                           $sValueN = null)
    {
        $this->aLoadedValues = [];
        $this->iPointer = 0;

        for($i = 1; $i < func_num_args(); $i++)
            $this->aLoadedValues[] = func_get_arg($i);

        $sVariable = preg_replace_callback("/(%[siaw])/", [$this, 'variable_check2'], $sVariable);

        $this->sHaving .= ($this->sHaving ? ' AND ' : '').'('.$sVariable.')';

        return $this;
    }


    private function escapeInt($value)
    {
        if($value === NULL)
            return 'NULL';

        if(!is_numeric($value))
            throw new Exception("Integer (?i) placeholder expects numeric value, ".gettype($value)." given");

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
        foreach($data as $value)
        {
            $query .= $comma.$this->escapeString($value);
            $comma = ",";
        }

        return $query;
    }

    private function escapeString($value)
    {
        //if($value === NULL)
        //    return 'NULL';


        $this->iImId++;
        $this->aExecuteValues[':strim'.$this->iImId] = $value;
        return ':strim'.$this->iImId;

        return "'".$this->escape($value)."'";
    }

    public function orderBy($sFieldName, $sOrder = 'ASC')
    {
        $this->aOrderBy[] = $sFieldName.' '.$sOrder;

        return $this;
    }

    public function limit($iLimit = null, $iOffset = null)
    {
        if(!is_null($iOffset))
            $this->aOtherParams['offset'] = (int)$iOffset;

        if(!is_null($iLimit))
            $this->aOtherParams['limit'] = (int)$iLimit;

        return $this;
    }

    /**
     * @param $iTime int        When -1 — existing cache cleaning
     * @return $this
     */
    public function cache($iTime)
    {
        $this->aOtherParams['cache'] = $iTime;

        return $this;
    }

    public function get($sType = 'matrix')
    {
        $this->prepareSql('select');
        $this->prepareResult();

        if($sType == 'first')
            return $this->aResult[0];
        elseif($sType == 'array')
        {
            $aRet = [];
            foreach($this->aResult as $aItem)
                $aRet[] = $aItem[array_keys($aItem)[0]];

            return $aRet;
        }
        elseif($sType == 'assoc')
        {
            $aRet = [];
            foreach($this->aResult as $aItem)
                $aRet[array_values($aItem)[0]] = array_values($aItem)[1];

            return $aRet;
        }

        return (array)$this->aResult;
    }

    /**
     * @param callable $obLoader
     * @return array
     */
    public function load(callable $obLoader, $sFieldNameForFactory = null)
    {
        $this->prepareSql('select');
        $this->prepareResult();

        $aRet = [];
        foreach($this->aResult as $v)
        {
            if (!is_null($sFieldNameForFactory))
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

    private function prepareSql($mainAction)
    {
        //The resulting query
        if ($mainAction == 'select')
        {
            $this->sResultSql = 'SELECT '.($this->bDistinct ? 'DISTINCT ' : '');

            //Collect the required fields:
            $this->sResultSql .= implode(', ', $this->aFields);
        }
        else if ($mainAction == 'delete')
            $this->sResultSql = 'DELETE ';

        //Collect tables
        $aResult = [];
        foreach($this->aTables as $aTableItem)
            $aResult[] = $aTableItem['name'].' '.$aTableItem['suffix'];
        $this->sResultSql .= $aResult ? ' FROM '.implode(', ', $aResult) : '';

        //collect join-expressions
        $this->sResultSql .= $this->sJoins ? ' '.$this->sJoins.' ' : '';

        //collect where-expressions
        $this->sResultSql .= $this->sWhere ? ' WHERE '.$this->sWhere : '';

        //collect having-expressions
        $this->sResultSql .= $this->sHaving ? ' HAVING '.$this->sHaving : '';

        //collect group-by expressions
        $this->sResultSql .= ($this->aGroupBy ? ' GROUP BY '.implode(', ', $this->aGroupBy) : '');

        //collect order-by expressions
        $this->sResultSql .= ($this->aOrderBy ? ' ORDER BY '.implode(', ', $this->aOrderBy) : '');

        if($this->aOtherParams['limit'] && $this->aOtherParams['offset'])
            $this->sResultSql .= " LIMIT ".$this->aOtherParams['offset'].", ".$this->aOtherParams['limit'];
        elseif($this->aOtherParams['limit'])
            $this->sResultSql .= " LIMIT ".$this->aOtherParams['limit'];
    }

    private function prepareResult()
    {
        if (!$this->sResultSql)
            throw new Exception('Invalid call sequence');

        //maybe from cache
        $sCacheKey = sha1($this->sResultSql.implode(array_values($this->aExecuteValues)));
        $iCache = (int)$this->aOtherParams['cache'];

        $obApc = Apc::getInstance();

        if ($iCache > 0 && !$obApc->add($sCacheKey, 1, $iCache))
        {
            $this->aResult = (array)$obApc->fetch($sCacheKey.'v');
            $this->iCalcFoundRows = (int)$obApc->fetch($sCacheKey.'f');

            $this->saveForProfiling($this->sResultSql, 'from cache');

            //debug:
            if ($sDumpName = $this->aOtherParams['getDump'])
                Debugger::clientLog('HIDDEN'.($sDumpName ? 'sql' : $sDumpName).' [cache]: '.$this->sResultSql,
                    $this->aResult);

            return;
        }

        if ($iCache == -1)
        {
            $obApc->delete([$sCacheKey.'v', $sCacheKey.'f']);
            $this->saveForProfiling($this->sResultSql, 'clean cache query');
        }
        else
            $this->saveForProfiling($this->sResultSql, 'direct query');

        //Begin
        $this->CreateQuery($this->sResultSql, !$this->aOtherParams['foundRows'], $this->aExecuteValues);

        $matrix = [];

        //field types
        $aFields = [];
        for($i=0;$i<1000;$i++)
        {
            if (!$aMetaInfo = $this->stmp->getColumnMeta($i))
                break;

            $aFields[$aMetaInfo['name']] = $aMetaInfo['native_type']; //pdo_type
        }

        $k = 0;
        while($row = $this->stmp->fetch(PDO::FETCH_ASSOC))
        {
            $matrix[$k] = $row;

            //Change some rows types:
            foreach($aFields as $field=>$type)
            {
                switch ( $type )
                {
                    // Convert INT to an integer.
                    case 'LONG':
                    case 'LONGLONG':
                    case 'TINY':
                    case 'SHORT':
                    case 'INT24':
                        $matrix[$k][$field] = intval($matrix[$k][$field]);
                        break;
                    // Convert FLOAT to a float.
                    case 'FLOAT':
                    case 'NEWDECIMAL':
                    case 'DOUBLE':
                        $matrix[$k][$field] = floatval($matrix[$k][$field]);
                        break;
                    case 'TIMESTAMP':
                    case 'DATE':
                    case 'DATETIME':
                        /*$obTemp = new \DateTime($matrix[$k][$field->name]);
                        $matrix[$k][$field->name] = $obTemp->getTimestamp();*/
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

        $this->iCalcFoundRows = (int)$this->_foundRows;

        $this->free();

        $this->aResult = (array)$matrix;

        if ($sDumpName = $this->aOtherParams['getDump'])
            Debugger::clientLog('HIDDEN'.($sDumpName ? 'sql' : $sDumpName).': [query]: '.$this->sResultSql,
                $this->aResult);

        if ($iCache)
        {
            $obApc->store($sCacheKey.'v', $this->aResult, $iCache*2);
            $obApc->store($sCacheKey.'f', $this->iCalcFoundRows, $iCache*2);
        }
    }

    private function saveForProfiling($sSql, $sGroupName)
    {
        self::$profilingInfo[$this->sServerName][$sGroupName][] = $sSql;
    }

    public function dump($sDescription = 'query')
    {
        $this->aOtherParams['getDump'] = $sDescription;

        return $this;
    }




    //////////////////////////////////////////
    public function run($strSQL)
    {
        $this->CreateQuery($strSQL, true);

        $result1 = $this->stmp->rowCount();
        $this->free();

        return $result1;
    }

    private function CreateQuery($strSQLQuery, $foundRowsDisable = false, $params = null)
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
            if (!$stmt = self::getPdo()->prepare($strSQLQuery))
                throw new Exception('Problem when prepare query');

            $stmt->execute($params);
            $this->stmp = $stmt;

            if($calc_found_rows_enable)
            {
                $sql = "select found_rows();";
                if (!$stmt2 = self::getPdo()->query($sql))
                    throw new Exception('Problem when prepare "found_rows" query');
                $this->_foundRows = (int)$stmt2->fetch(PDO::FETCH_COLUMN);
                $stmt2->closeCursor();
            }
        }catch(\PDOException $e) {
            Debugger::log('Mysql error:', self::getPdo()->error, 'Query:', $strSQLQuery);
            throw new Exception('Mysql error #'.$e->getCode(), 1);
        }

        return 1;
    }

    public function lookup($ColumnName, $TableName, $CriteriaColumn, $CriteriaCondition, $cache_time = 0)
    {
        return $this
                   ->select($ColumnName)
                   ->from($TableName)
                   ->where("`{$CriteriaColumn}` = %s", $CriteriaCondition)
                   ->cache($cache_time)
                   ->limit(1)
                   ->get('first')[$ColumnName];
    }

    public function free()
    {
        if($this->stmp)
            $this->stmp->closeCursor();

        $this->_foundRows = 0;
    }

    public function delete()
    {
        $this->prepareSql('delete');

        $this->CreateQuery($this->sResultSql, true, $this->aExecuteValues);
        $this->free();
    }

    public function insert($table, $fields, $getInsertId = false, $update = false, $ignore = false)
    {
        $tablesArray = explode('.', $table);

        $newTablesArray = array();
        foreach($tablesArray as $t)
        {
            $newTablesArray[] = "`{$t}`";
        }
        $table = implode('.', $newTablesArray);

        try
        {
            $updateFields = array();
            $updateValues = array();
            $updateAsSqlFields = array();

            foreach($fields as $name => $value)
            {
                $commitAsSql = false; //Сохранять как sql-код поля, которые начинаются с >


                if($name && mb_substr($name, 0, 1) == '>')
                {
                    $name = mb_substr($name, 1);
                    $commitAsSql = true;
                }

                if($name && mb_substr($name, 0, 1) == '>')
                {
                    $name = mb_substr($name, 1);
                    $updateAsSqlFields[] = "`{$name}`=".$value;
                }
                else
                {
                    $updateFields[] = "`{$name}`";
                    $updateValues[] = $commitAsSql ? $value : "'".$this->escape($value)."'";
                }
            }

            $added_update_sql = "";

            if($update)
            {
                $added_update_sql = " ON DUPLICATE KEY UPDATE ";

                $f2 = 0;
                foreach($updateFields as $f1)
                {
                    if($f2)
                        $added_update_sql .= ",";
                    $added_update_sql .= "{$f1} = VALUES({$f1})";
                    $f2++;
                }
                foreach($updateAsSqlFields as $f1)
                {
                    if($f2)
                        $added_update_sql .= ",";
                    $added_update_sql .= $f1;
                    $f2++;
                }
            }

            $this->CreateQuery(
                "INSERT ".($ignore ? "IGNORE " : "")."INTO {$table} (".
                join(',', $updateFields).
                ") VALUES (".
                join(',', $updateValues).
                "){$added_update_sql};",
                true,
                $this->aExecuteValues
            );


            if($getInsertId)// && !$update)
            { //При update даже если произошел первичный insert вернется affected_rows!
                $ret = self::getPdo()->lastInsertId();
                $this->free();

                return $ret;
            }
            else
            {
                $result1 = $this->stmp->rowCount();
                $this->free();

                return $result1;
            }

        } catch(Exception $e)
        {
            $err = $e;
            Debugger::log("SQL Error: $err");
        }

        return false;
    }

    public function update($table, $field, $criteriaField, $criteria, $value)
    {
        $tablesArray = mb_split('\.', $table);

        $newTablesArray = array();
        foreach($tablesArray as $t)
        {
            $newTablesArray[] = "`{$t}`";
        }
        $table = join('.', $newTablesArray);

        $err = '';
        try
        {
            $this->CreateQuery(
                "UPDATE {$table}
                    SET `{$field}` = '".$this->escape($value)."'
                    WHERE `{$criteriaField}` = '{$criteria}'",
                true
            );
        } catch(Exception $e)
        {
            $err = $e;
            Debugger::log("SQL Error: $err");
        }

        $result1 = $this->stmp->rowCount();
        $this->free();

        if(!$err)
            return $result1;

        return false;
    }
}