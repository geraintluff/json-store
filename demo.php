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
		if ($cached = self::cached('TestClass', $id)) {
			return $cached;
		}
		$sql = "SELECT * FROM json_store_test WHERE `int_id`='".self::mysqlEscape($id)."'";
		$rows = self::mysqlQuery($sql);
		if (count($rows)) {
			return self::setCached('TestClass', $id, new TestClass($rows[0]));
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
			"columns" => array("integer", "integer/id", "string/name"),
			"alias" => array(
				"group" => "array_group",
				"index" => "pos",
				"integer" => "int_value",
				"integer/id" => "obj_id",
				"string/name" => "obj_name"
			)
		)
	),
	"alias" => array(
		"integer/id" => "int_id",
		"string/title" => "str_title",
		"array/arr" => "array_group"
	),
	"keyColumn" => "integer/id"
));

try {
	TestClass::$showQueries = TRUE;

	echo '<pre>';
	echo '<h2>Loading object:</h2>';
	$obj808 = TestClass::open(808);
	var_dump($obj808);

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
	echo '<h2>Reloaded from cache:</h2>';
	$loaded = TestClass::open($obj->id);
	var_dump($loaded);

	echo '<hr>';
	echo '<h2>Reloaded from DB:</h2>';
	JsonStore::removeCached('TestClass', $obj->id);
	$loaded = TestClass::open($obj->id);
	var_dump($loaded);
	echo '</pre>';
} catch (Exception $e) {
	echo '<div style="color: #C00; margin: 0.4em; padding: 0.4em; border: 1px solid #844; border-radius: 2px; background-color: #F8E8E8;">'.htmlentities($e).'</div>';
}

?>
