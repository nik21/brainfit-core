<?php
namespace Brainfit\Io\Data;

//TODO: We refactored this class

use Brainfit\Io\Data\Drivers\Memcached;
use Brainfit\Model\Exception;
use Brainfit\Util\Debugger;

class Sql implements DataInterface
{
    protected $mysqli;
    protected $redis;

    protected $_foundRows;
    protected $result;

    private $server;
    private $user;
    private $password;
    private $defaultDb;
    private $useUtf8;

    const RETURN_AS_ARRAY = 8; //Вернуть выборку одной колонки, как массив. Т.е. select codes from ... даст с данной опцией array(32,2141,2134,121223)
    const RETURN_AS_ASSOC = 256; //Первая колонка -- код, вторая -- значение. Т.е. select a,b from ... даст array(a1=>b1, a2=>b2)

    public function __construct($server, $user, $password, $defaultDb,
                                $useUtf8 = true)
    {
        $this->server = $server;
        $this->user = $user;
        $this->password = $password;
        $this->defaultDb = $defaultDb;
        $this->useUtf8 = $useUtf8;
    }

    private function lazyInit()
    {
        if($this->mysqli)
            return;

        $this->mysqli = new \mysqli($this->server, $this->user, $this->password, $this->defaultDb);

        if(mysqli_connect_errno())
            throw new Exception('Невозможно работать с sql: '.mysqli_connect_error().', '
                .mysqli_connect_errno(), 0);

        if($this->useUtf8)
            $this->mysqli->set_charset('utf8');
    }


    public function lookup($ColumnName, $TableName, $CriteriaColumn, $CriteriaCondition, $cache_time = 0)
    {

        if($cache_time)
        {
            $memcache = Memcached::getInstance();
            $sha1 = sha1($ColumnName.$TableName.$CriteriaColumn.$CriteriaCondition);

            $ret = $memcache->get("FO_DBLkup_{$sha1}");
        }

        if(!$cache_time || $ret === false)
        {

            $tablesArray = mb_split('\.', $TableName);

            $newTablesArray = array();
            foreach($tablesArray as $t)
            {
                $newTablesArray[] = "`{$t}`";
            }
            $TableName = join('.', $newTablesArray);


            //$myDBQueryForLookup = self::getInstance();

            $this->CreateQuery("select `".$ColumnName."` from ".$TableName." where `$CriteriaColumn` = '".$this->escape($CriteriaCondition)."' limit 1", true);

            $row = $this->result->fetch_row();
            $ret = $row[0];

            $this->free();

            if($cache_time)
                $memcache->set("FO_DBLkup_{$sha1}", $ret, 0, $cache_time);
        }

        return $ret;
    }

    public function query($aColumnNameOrColumnsList, $sTableName, $aConditions, $iOffset = null, $iLimit = null,
                          $sOrderBy = '', $cache_time = 0, $bLongMemFoundRows = false)
    {
        if(!is_array($aColumnNameOrColumnsList))
            $aColumnNameOrColumnsList = array($aColumnNameOrColumnsList);

        $sSha = '';
        foreach($aConditions as $iN => $aE)
        {
            if(is_array($aE))
            {
                $aK = array_keys($aE);
                $sK = array_shift($aK);
                $sSha .= $sK.'.'.$aE[$sK].'.';
            }
            else
            {
                $sSha .= $iN.'.'.$aE.'.';
            }
        }

        foreach($aColumnNameOrColumnsList as $i => $aE)
        {
            $sSha .= $aE.'.';
            if($bAsNative = mb_substr($aE, 0, 1) == '%')
                $aE = mb_substr($aE, 1);
            $bAsIs = $aE == '*' || $bAsNative;

            //тут бы для $aE $this->escape делать, да только базе худо
            $aColumnNameOrColumnsList[$i] = ($bAsIs ? $aE : '`'.$aE.'`');
            $sSha .= 'd'.$i.'s'.$aE;
        }

        $sha1 = sha1('dase1'.$sTableName.$sOrderBy.$sSha.'a'.$iLimit.'b'.$iOffset);
        $sShaWithoutOffset = sha1('dase1'.$sTableName.$sOrderBy.$sSha.'v'.$cache_time.'d');

        if($cache_time)
            $memcache = Memcached::getInstance();

        if($cache_time && $cache_time < 2)
            $cache_time = 2;

        if($cache_time && !$memcache->add("FO_DBLkup1Lock1_{$sha1}", 1, 0, $cache_time))
        {
            $aData = $memcache->get("FO_DBLkup1_{$sha1}");
            if(!is_null($aData))
                return (array)$aData;
        }


        $matrix = array();
        $iFoundRows = 0;


        //Если включен режим долгого fr, то
        $bEnableFoundRows = false; //без found rowse
        if(!is_null($iLimit) && !is_null($iOffset))
            $bEnableFoundRows = true; //с

        if($bEnableFoundRows && $bLongMemFoundRows &&
            !$memcache->add("FO_DBLkup22Lock_{$sShaWithoutOffset}", 1, 0, 1200)
        )
        {
            $iFoundRows = $memcache->get("FO_DBLkup22_{$sShaWithoutOffset}");
            $bEnableFoundRows = is_null($iFoundRows) ? true : false;
            $iFoundRows = (int)$iFoundRows;
        }


        foreach(explode('.', $sTableName) as $t)
        {
            $newTablesArray[] = '`'.$t.'`';
        }
        $sTableName = implode('.', $newTablesArray);

        $aWhereCondition = array();
        foreach($aConditions as $ak2 => $aCondition)
        {
            if(is_string($ak2))
            {
                $sFieldName = $ak2;
                $sFieldValue = $aCondition;
            }
            else
            {
                $aK = array_keys($aCondition);
                $sFieldName = array_shift($aK);
                $sFieldValue = $aCondition[$sFieldName];
            }


            $sPrefix = substr($sFieldName, 0, 1);

            if($sPrefix == '%')
            {
                $sFieldName = mb_substr($sFieldName, 1);
                $aWhereCondition[] = '`'.$sFieldName.'` LIKE \''.$this->escape($sFieldValue).'\'';
            }

            elseif($sPrefix == '>')
            {
                $sFieldName = mb_substr($sFieldName, 1);
                $aWhereCondition[] = '`'.$sFieldName.'` > \''.$this->escape($sFieldValue).'\'';
            }

            elseif($sPrefix == '<')
            {
                $sFieldName = mb_substr($sFieldName, 1);
                $aWhereCondition[] = '`'.$sFieldName.'` < \''.$this->escape($sFieldValue).'\'';
            }

            elseif($sPrefix == '!')
            {
                $sFieldName = mb_substr($sFieldName, 1);
                $aWhereCondition[] = '`'.$sFieldName.'` != \''.$this->escape($sFieldValue).'\'';
            }

            elseif($sPrefix == '*')
            {
                $sFieldName = mb_substr($sFieldName, 1);
                $aWhereCondition[] = '`'.$sFieldName.'` LIKE \'%'.$this->escape($sFieldValue).'%\'';
            }

            elseif($sFieldName == 'OR' && is_array($sFieldValue))
            {
                $aTemp = array();
                foreach($sFieldValue as $kk => $vv)
                    $aTemp[] = '`'.$kk.'`=\''.$this->escape($vv).'\'';

                $aWhereCondition[] = '('.implode(' OR ', $aTemp).')';
            }

            elseif(is_array($sFieldValue))
            {
                $aWhereCondition[] = '`'.$sFieldName.'` IN (\''.implode('\',\'', $sFieldValue).'\')';
            }

            else
            {
                $aWhereCondition[] = '`'.$sFieldName.'`=\''.$this->escape($sFieldValue).'\'';
            }
        }


        $sSql = 'select '.implode(', ', $aColumnNameOrColumnsList).' from '.$sTableName
            .($aWhereCondition ? ' where '.implode(' AND ', $aWhereCondition) : '').' '.$sOrderBy
            .' '.(!is_null($iLimit) && !is_null($iOffset) ? ' limit '.(int)$iOffset.','.(int)$iLimit : '');

        $this->CreateQuery($sSql, !$bEnableFoundRows);


        $k = 0;
        $fields = $this->result->fetch_fields(); //TODO: cache this
        while($row = $this->result->fetch_array(MYSQLI_ASSOC))
        {
            $matrix[$k] = $row;

            //Следует изменить типы некоторых rows:
            foreach($fields as $field)
                if($field->type == 3 && !is_null($matrix[$k][$field->name]))
                    $matrix[$k][$field->name] = intval($matrix[$k][$field->name]);

            $k++;
        }

        if($bEnableFoundRows)
        {
            $iFoundRows = (int)$this->_foundRows;
            if($cache_time && $bLongMemFoundRows)
                $memcache->set("FO_DBLkup22_{$sShaWithoutOffset}", $iFoundRows, 0, 1260);
        }

        $this->free();

        $matrix[0]['FoundRows'] = $iFoundRows;

        if($cache_time)
            $memcache->set("FO_DBLkup1_{$sha1}", (array)$matrix, 0, $cache_time * 2);

        return (array)$matrix;
    }

    public function run($strSQL)
    {
        try
        {
            $this->CreateQuery($strSQL, true);
        } catch(\Exception $e)
        {
            $err = $e;
            \Util\Debugger::log("SQL Error: $err");
        }

        $result1 = $this->mysqli->affected_rows;
        $this->free();

        if(!$err)
            return $result1;


        return false;
    }

    public function runFunction($strSQL)
    {
        try
        {
            $this->CreateQuery($strSQL, true);
            $row = $this->result->fetch_row();

            $ret = $row[0];

            $this->free();

            return $ret;
        } catch(\Exception $e)
        {
            $err = $e;
            \Util\Debugger::log("SQL Error: $err");
        }

        return false;
    }

    public function delete($table, $criteriaField, $criteria)
    {
        $tablesArray = mb_split('\.', $table);

        $newTablesArray = array();
        foreach($tablesArray as $t)
        {
            $newTablesArray[] = "`{$t}`";
        }
        $table = join('.', $newTablesArray);

        try
        {
            //TODO: Защиту
            $this->CreateQuery(
                "DELETE FROM {$table} WHERE `{$criteriaField}` = '{$criteria}'",
                true
            );

            $this->free();

            return true;
        } catch(\Exception $e)
        {
            $err = $e;
            Debugger::log("SQL Error: $err");

            return false;
        }
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
                true
            );


            if($getInsertId)// && !$update)
            { //При update даже если произошел первичный insert вернется affected_rows!
                $ret = $this->mysqli->insert_id;
                $this->free();

                return $ret;
            }
            else
            {
                $result1 = $this->mysqli->affected_rows;
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

        $result1 = $this->mysqli->affected_rows;
        $this->free();

        if(!$err)
            return $result1;

        return false;
    }

    public function getLikeCompatibleFieldTypes($sql)
    {
        $aResult = [];

        $this->CreateQuery($sql, true);

        if(!$this->result)
        {
            $this->free();

            return;
        }

        $fields = $this->result->fetch_fields(); //TODO: cache this
        foreach($fields as $obField)
        {
            //Даты пропускаем
            if($obField->type == 10 || $obField->type == 7)
                continue;

            $aResult[] = $obField->name;
        }

        $this->free();

        return $aResult;
    }

    public function matrix($sql, $order = '', $keys = 0, $cacheTime = 0, $from = 0, $count = 0, $iWantCalcFoundRows = false)
    {
        $memcache = Memcached::getInstance();
        $sPreKey = 'FODBMatrix2';

        $matrix = array();


        $disable_found_rows = !$iWantCalcFoundRows;

        if($iWantCalcFoundRows)
        {
            $sha1 = sha1($sql);

            //Проверим, вдруг кэшировали количество
            if($cacheTime)
            {
                $sha1_count = $memcache->get($sPreKey."count_{$sha1}");

                if($sha1_count != false)
                    $disable_found_rows = true;
            }
        }

        if($order)
            $sql .= ' '.$order;

        if($count) //Добавить limit в конец запроса
            $sql .= " LIMIT {$from}, {$count}";


        $sha1_full = sha1($sql.$keys);
        if(!$cacheTime || !$memcache->add($sPreKey.'sem'.$sha1_full, 1, 0, $cacheTime))
        {
            //из кэша или кэш не активирован
            if($cacheTime)
            {
                $aData = $memcache->get($sPreKey."full1_{$sha1_full}");
                if($aData !== false)
                    return $aData;
            }
        }

        //Выбирать

        $this->CreateQuery($sql, $disable_found_rows);

        if(!$this->result)
        {
            $this->free();

            return;
        }

        $k = 0;
        $fields = $this->result->fetch_fields(); //TODO: cache this


        while($row = $this->result->fetch_array(MYSQLI_ASSOC))
        {
            $matrix[$k] = $row;

            //Следует изменить типы некоторых rows:
            foreach($fields as $field)
                if($field->type == 3 && !is_null($matrix[$k][$field->name]))
                    $matrix[$k][$field->name] = intval($matrix[$k][$field->name]);

            $k++;
        }


        if($iWantCalcFoundRows && !$disable_found_rows)
        { // не выключен но есть и каунт
            //Закэшировать!
            if(isset($matrix[0]))
                $matrix[0]['FoundRows'] = (int)$this->_foundRows;

            //Закэшируем.
            if($cacheTime)
                $memcache->set($sPreKey."count_{$sha1}", $matrix[0]['FoundRows'], 0, $cacheTime * 2);
        }
        elseif($iWantCalcFoundRows && $disable_found_rows)
        { //выключен и есть каунт
            //значит взят из кэша $sha1_count. Удалим старьё
            if(isset($matrix[0]))
                $matrix[0]['FoundRows'] = (int)$sha1_count;
        }
        else
        {
            if(isset($matrix[0]) && $iWantCalcFoundRows)
                $matrix[0]['FoundRows'] = $this->_foundRows;
        }

        $this->free();

        //Преобразование в нужный нам формат:
        if($keys == self::RETURN_AS_ARRAY)
            $matrix = $this->matrix2Array($matrix);
        elseif($keys == self::RETURN_AS_ASSOC)
            $matrix = $this->matrix2Assoc($matrix);

        if($cacheTime && gettype($matrix) == 'array')
            $memcache->set($sPreKey."full1_{$sha1_full}", $matrix, 0, $cacheTime * 2);

        return $matrix;
    }

    private function matrix2Assoc($matrixData)
    {
        //выяснение названия колонки:
        if(!$matrixData || !$matrixData[0])
            return array();

        $colName = array_keys($matrixData[0]);
        $colName1 = $colName[0];
        $colName2 = $colName[1];

        if($colName1 == 'FoundRows' || !$colName1 || !$colName2)
            return array();

        $result = array();
        foreach($matrixData as $v)
        {
            $result[$v[$colName1]] = $v[$colName2];
        }

        return $result;
    }

    private function matrix2Array($matrixData)
    {
        //Преобразовать матрицу с одной колонкой в массив

        //выяснение названия колонки:
        if(!$matrixData || !$matrixData[0])
            return array();

        $colName = array_keys($matrixData[0]);
        $colName = $colName[0];

        if($colName == 'FoundRows')
            return array();

        $result = array();
        foreach($matrixData as $v)
        {
            $result[] = $v[$colName];
        }

        return $result;
    }


    //class
    public function free()
    {
        if($this->result)
            $this->result->free();
        $this->_foundRows = 0;
    }

    public function escape($data)
    {
        $this->lazyInit(); //TODO: Может снижать пр-сть

        return $this->mysqli->real_escape_string($data);
    }

    public function CreateQuery($strSQLQuery, $foundRowsDisable = false)
    {
        $this->lazyInit();

        if(!$strSQLQuery)
            return 0;

        $strSQLQuery = trim($strSQLQuery);

        $calc_found_rows_enable = 0;

        if(!$foundRowsDisable)
        {
            if(mb_eregi('LIMIT ', $strSQLQuery) && mb_eregi('^SELECT', $strSQLQuery))
            {
                //Если включено лимитирование и это инструкция SELECT, то посчитать, при условии разрешения
                $calc_found_rows_enable = 1;

                $strSQLQuery = mb_eregi_replace("(^SELECT)(.*)", "SELECT SQL_CALC_FOUND_ROWS\\2;SELECT FOUND_ROWS();", $strSQLQuery);
            }
        }

        //\Util\Debugger::log('MYSQL', $strSQLQuery);
        //$GLOBALS['prev']=microtime(true);


        if($this->mysqli->multi_query($strSQLQuery))
        {
            do
            {
                /* store first result set */

                $this->result = $this->mysqli->store_result();


                if($calc_found_rows_enable)
                {
                    if($this->mysqli->more_results() && $this->mysqli->next_result())
                    {
                        list($total) = $this->mysqli->store_result()->fetch_row();
                        $this->_foundRows = $total;
                    }
                }
                else
                    $this->_foundRows = is_object($this->result) ? $this->result->num_rows : 0;


            } while($this->mysqli->more_results() && $this->mysqli->next_result());


        }
        else
        {
            $err = "MySQL error {$this->mysqli->error} <br> Query:<br> {$strSQLQuery}";
            Debugger::log($err);
            throw new Exception($err, $this->mysqli->errno);
        }

        //if ($GLOBALS['prev']) $GLOBALS['profiler'][0] += intval((microtime(true)-$GLOBALS['prev'])*1000);

        return 1;
    }
}
