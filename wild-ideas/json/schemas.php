<?php
	include 'common.php';
	
	define('SCHEMA_DIR', 'schemas-plain');

	$pathInfo = $_SERVER['PATH_INFO'];
	
	$filename = $pathInfo;
	$filename = str_replace('..', '.', $filename);
	$filename = SCHEMA_DIR.$filename;
	
	if (!file_exists($filename)) {
		if (file_exists($filename.".json")) {
			$filename .= ".json";
		} else {
			json_error(404);
		}
	}
	
	$jsonText = file_get_contents($filename);
	$jsonText = str_replace("{JSON_ROOT}", JSON_ROOT, $jsonText);
	
	json_exit_raw($jsonText);
?>