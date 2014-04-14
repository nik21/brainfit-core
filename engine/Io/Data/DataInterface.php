<?php
namespace Brainfit\Io\Data;

interface DataInterface
{
    public function lookup($ColumnName, $TableName, $CriteriaColumn,
                           $CriteriaCondition, $cache_time = 0);

    public function run($strSQL);

    public function insert($table, $fields, $getInsertId = false,
                           $update = false, $ignore = false);

    public function update($table, $field, $criteriaField, $criteria, $value);

    public function matrix($sql, $order = '', $keys = 0, $cacheTime = 0,
                           $from = 0, $count = 0);
}
