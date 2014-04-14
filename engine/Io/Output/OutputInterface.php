<?php
namespace Io\Output;

interface OutputInterface
{
    public static function getInstance();

    public function assign($name, $value);

    public function get();

    public function getVar($name);
}