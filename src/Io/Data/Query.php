<?php
namespace Brainfit\Io\Data;

use Brainfit\Model\Exception;
use Brainfit\Settings;
use Brainfit\Util\Debugger;

/**
 * Class Query
 * @package Io\Data
 */
class Query extends Sql
{
    public function __construct($sServerName = 'main')
    {
        $sServer = Settings::get('MYSQL', 'servers', $sServerName, 'server');
        $sUser = Settings::get('MYSQL', 'servers', $sServerName, 'login');
        $sPassword = Settings::get('MYSQL', 'servers', $sServerName, 'password');
        $sDefaultDb = Settings::get('MYSQL', 'servers', $sServerName, 'db');

        parent::__construct($sServer, $sUser, $sPassword, $sDefaultDb, Settings::get('MYSQL', 'switchToUTF8'));
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

    public function select($sField1, $sField2 = null, $sFieldN = null)
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

    public function where($sVariable, $sValue1 = null, $sValue2 = null, $sValueN = null)
    {
        $this->aLoadedValues = [];
        $this->iPointer = 0;

        for($i = 1; $i < func_num_args(); $i++)
            $this->aLoadedValues[] = func_get_arg($i);

        $sVariable = preg_replace_callback("/(%[siaw])/", [$this, 'variable_check2'], $sVariable);

        $this->sWhere .= ($this->sWhere ? ' AND ' : '').'('.$sVariable.')';

        return $this;
    }

    public function join($sDirection, $sTableName, $sSuffix, $sVariable, $sValue1 = null, $sValue2 = null, $sValueN = null)
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

    public function having($sVariable, $sValue1 = null, $sValue2 = null, $sValueN = null)
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
                if($field->type == 3 && !is_null($matrix[$k][$field->name]))
                    $matrix[$k][$field->name] = intval($matrix[$k][$field->name]);

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
}