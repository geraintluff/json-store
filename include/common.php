<?php

require_once(dirname(__FILE__).'/match-uri-template.php');
require_once(dirname(__FILE__).'/json-utils.php');
require_once(dirname(__FILE__).'/json-diff.php');
require_once(dirname(__FILE__).'/json-store.php');

/*
	Defines:
		*	MYSQL_HOSTNAME
		*	MYSQL_USERNAME
		*	MYSQL_PASSWORD
		*	MYSQL_DATABASE
*/
require_once(dirname(__FILE__).'/config.php');
JsonStore::setConnection(new JsonStoreConnectionBasic(MYSQL_HOSTNAME, MYSQL_USERNAME, MYSQL_PASSWORD, MYSQL_DATABASE));

/*
class FakeConnection extends JsonStoreConnection {
	function __construct($resultsPrefix="") {
		$this->resultsPrefix = $resultsPrefix;
	}

	function query($sql) {
		$signature = $this->resultsPrefix.md5($sql).".json";
		if (!file_exists($signature)) {
			$fileData = array(
				"query" => $sql,
				"results" => array()
			);
			file_put_contents($signature, json_encode($fileData));
		}
		$fileData = json_decode(file_get_contents($signature), TRUE);
		$result = array();
		foreach ($fileData['results'] as $item) {
			foreach ($item as $key => $value) {
				if (!is_string($value)) {
					$item[$key] = json_encode($value);
				}
			}
			$result[] = $item;
		}
		return $result;
	}
	function escape($string) {
		return str_replace("'", "\'", $string);
	}
}
JsonStore::setConnection(new FakeConnection('fake/'));
*/

?>