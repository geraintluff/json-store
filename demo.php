<?php

/*

CREATE TABLE `json_store_test` (
  `json` text NOT NULL,
  `integer/id` int(11) unsigned NOT NULL auto_increment,
  `string/title` varchar(255) default NULL,
  PRIMARY KEY  (`integer/id`)
) ENGINE=MyISAM AUTO_INCREMENT=6 DEFAULT CHARSET=latin1;

*/

include dirname(__FILE__).'/include/json-store.php';

class TestClass extends StoredJson {
	static public function open($id) {
		$sql = "SELECT * FROM json_store_test WHERE `integer/id`='".self::mysqlEscape($id)."'";
		$rows = self::mysqlQuery($sql);
		if (count($rows)) {
			return new TestClass($rows[0]);
		}
	}
	
	static public function create() {
		$result = new TestClass();
		$result->title = "Hello, World!";
		return $result;
	}
	
	protected function mysqlConfig() {
		return array(
			"table" => "json_store_test",
			"columns" => array("json", "integer/id", "string/title"),
			"keyColumn" => "integer/id"
		);
	}
	
	public function save() {
		if (isset($this->id)) {
			$result = $this->mysqlUpdate("integer/id");
		} else {
			$result = $this->mysqlInsert($this->id);
		}
		var_dump($result);
		if (!$result) {
			var_dump($this);
			die($this->mysqlErrorMessage);
			throw new Exception("Error saving TestClass: ".$this->mysqlErrorMessage."\n$sql");
		}
	}
}

echo '<pre>';
echo '<h2>Create object:</h2>';
$obj = TestClass::create();
var_dump($obj);

echo '<hr>';
echo '<h2>Modify title:</h2>';
$obj->title .= "!";
$obj->save();

echo '<hr>';
echo '<h2>Add random value:</h2>';
$obj->randomValue = rand();
$obj->save();

echo '<hr>';
echo '<h2>Final value:</h2>';
var_dump($obj);
echo '</pre>';

?>