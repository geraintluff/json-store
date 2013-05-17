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
	
	public function save() {
		$columns = array("json", "integer/id", "string/title");
		if (isset($this->id)) {
			$sql = "UPDATE json_store_test SET
						".$this->mysqlUpdateValues($columns)."
					WHERE `integer/id`='".self::mysqlEscape($this->id)."'";
			$result = self::mysqlQuery($sql);
		} else {
			$sql = "INSERT INTO json_store_test ".$this->mysqlColumns($columns)." VALUES
				".$this->mysqlInsertValues($columns);
			$result = self::mysqlQuery($sql);
			if ($result) {
				$this->id = $result['insert_id'];
			}
		}
		var_dump($sql);
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