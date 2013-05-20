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

class TestClass extends JsonStore {
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
}
JsonStore::addMysqlConfig('TestClass', array(
	"table" => "json_store_test",
	"columns" => array(
		"json",
		"integer/id",
		"string/title",
		"array/arr" => array(
			"table" => "json_store_array",
			"columns" => array("integer", "integer/id", "string/name")
		)
	),
	"keyColumn" => "integer/id"
));

TestClass::$showQueries = TRUE;

echo '<pre>';
echo '<h2>Loading object:</h2>';
$obj = TestClass::open(808);
var_dump($obj);

echo '<pre>';
echo '<h2>Create object:</h2>';
$obj = TestClass::create();
var_dump($obj);

echo '<hr>';
echo '<h2>Modify values and save:</h2>';
$obj->title .= " :)";
$obj->randomValue = rand();
var_dump($obj->save());

echo '<hr>';
echo '<h2>Add array and save:</h2>';
$obj->arr = array(
	(object)array("id" => 1, "name" => "item 1"),
	200
);
var_dump($obj->save());

echo '<hr>';
echo '<h2>Delete:</h2>';
var_dump($obj->delete());

echo '<hr>';
echo '<h2>Re-save:</h2>';
var_dump($obj->save());

echo '<hr>';
echo '<h2>Final value:</h2>';
var_dump($obj);

echo '<hr>';
echo '<h2>Loaded from DB:</h2>';
$loaded = TestClass::open($obj->id);
var_dump($loaded);
echo '</pre>';

?>
