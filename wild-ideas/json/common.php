<?php

// Assume this file is placed in the root of the JSON app
$JSON_ROOT = str_replace('\\', '/', dirname(substr(__FILE__, strlen($_SERVER['DOCUMENT_ROOT']))));
define('JSON_ROOT', $JSON_ROOT);
define('SCHEMA_ROOT', JSON_ROOT.'/schemas');

define('DEBUG', true);

require_once dirname(__FILE__).'/../../include/common.php';

?>