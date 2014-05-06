<?php
namespace Brainfit\Io\Data;

use Brainfit\Model\Exception;
use Brainfit\Settings;
use Brainfit\Util\Debugger;

/**
 * Class Query
 * @package Io\Data
 */
class Query
{
    private $server;
    private $user;
    private $password;
    private $defaultDb;
    private $useUtf8;

    /** @var  \mysqli */
    private $mysqli;

    /** @var  \mysqli_result */
    private $result;
    private $_foundRows;

    public function __construct($sServerName = 'main')
    {
        $this->server = Settings::get('MYSQL', 'servers', $sServerName, 'server');
        $this->user = Settings::get('MYSQL', 'servers', $sServerName, 'login');
        $this->password = Settings::get('MYSQL', 'servers', $sServerName, 'password');
        $this->defaultDb = Settings::get('MYSQL', 'servers', $sServerName, 'db');
        $this->useUtf8 = Settings::get('MYSQL', 'switchToUTF8');
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

    public function escape($data)
    {
        $this->lazyInit(); //TODO: Может снижать пр-сть

        return $this->mysqli->real_escape_string($data);
    }


    //    Новшества
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

    public function reset()
    {
        $this->aTables = [];
        $this->aFields = [];
        $this->bDistinct = false;
        $this->sWhere = null;
        $this->sJoins = null;
        $this->sHaving = null;
        $this->sResultSql = null;
        $this->aOtherParams = [];
        $this->aOrderBy = [];
        $this->aGroupBy = [];

        $this->aLoadedValues = null;
        $this->iPointer = 0;

        $this->iCalcFoundRows = 0;

        return $this;
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

        $this->sJoins = $sDirection.' join '.$sTableName.' '.$sSuffix.' on '.$sVariable;

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
        if($value === NULL)
            return 'NULL';

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

    public function cache($iTime)
    {
        $this->aOtherParams['cache'] = $iTime;

        return $this;
    }

    public function get($sType = 'matrix')
    {
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

    public function load(callable $obLoader)
    {
        $this->prepareResult();

        $aRet = [];
        foreach($this->aResult as $v)
            $aRet[] = $obLoader($v);

        return $aRet;
    }

    public function getFoundRows()
    {
        return $this->iCalcFoundRows;
    }

    private function prepareResult()
    {
        //The resulting query
        $this->sResultSql = 'SELECT '.($this->bDistinct ? 'DISTINCT ' : '');

        //Collect the required fields:
        $this->sResultSql .= implode(', ', $this->aFields);

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

        //Begin
        $this->CreateQuery($this->sResultSql, !$this->aOtherParams['foundRows']);

        //TODO: Use $this->aOtherParams['offset/limit/cache']

        $matrix = [];
        $k = 0;
        $fields = $this->result->fetch_fields(); //TODO: cache this

        //Parse
        $aFields = [];
        foreach($fields as $field)
            $aFields[$field->name] = $field;

        while($row = $this->result->fetch_array(MYSQLI_ASSOC))
        {
            $matrix[$k] = $row;

            //Change some rows types:
            foreach($aFields as $field)
            {
                switch ( $field->type )
                {
                    // Convert INT to an integer.
                    case MYSQLI_TYPE_TINY:
                    case MYSQLI_TYPE_SHORT:
                    case MYSQLI_TYPE_LONG:
                    case MYSQLI_TYPE_LONGLONG:
                    case MYSQLI_TYPE_INT24:
                        $matrix[$k][$field->name] = intval($matrix[$k][$field->name]);
                        break;
                    // Convert FLOAT to a float.
                    case MYSQLI_TYPE_FLOAT:
                    case MYSQLI_TYPE_DOUBLE:
                        $matrix[$k][$field->name] = floatval($matrix[$k][$field->name]);
                        break;
                    // Convert TIMESTAMP to a DateTime object.
                    /*case MYSQLI_TYPE_TIMESTAMP:
                    case MYSQLI_TYPE_DATE:
                    case MYSQLI_TYPE_DATETIME:
                        $obTemp = new \DateTime($matrix[$k][$field->name]);
                        $matrix[$k][$field->name] = $obTemp->getTimestamp();
                        break;*/
                }
            }

            $k++;
        }

        $this->iCalcFoundRows = $this->_foundRows;

        $this->free();

        $this->aResult = (array)$matrix;
    }

    public function dump($sDescription = null)
    {
        $this->prepareResult();

        Debugger::clientLog('HIDDEN'.(is_null($sDescription) ? 'sql' : $sDescription)
            .': '.$this->sResultSql, $this->aResult);

        return $this;
    }




    //////////////////////////////////////////
    public function run($strSQL)
    {
        $this->CreateQuery($strSQL, true);

        $result1 = $this->mysqli->affected_rows;
        $this->free();

        return $result1;
    }

    public function CreateQuery($strSQLQuery, $foundRowsDisable = false)
    {
        $this->lazyInit();

        if(!$strSQLQuery)
            return 0;

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
            Debugger::log('Mysql error:', $this->mysqli->error, 'Query:', $strSQLQuery);
            throw new Exception('Mysql error #'.$this->mysqli->errno, 1);
        }

        return 1;
    }

    public function free()
    {
        if($this->result)
            $this->result->free();
        $this->_foundRows = 0;
    }
}