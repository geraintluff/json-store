<?php

include_once dirname(__FILE__).'/../common.php';

require_once dirname(__FILE__).'/idea.gen.php';

class Idea extends Idea_gen {
	static public function search($schema=NULL, $orderBy=NULL) {
		if (!$schema) {
			$schema = new StdClass;
		}
		if (!$orderBy) {
			$orderBy = array('integer/id' => 'ASC');
		}
		$sql = JsonStore::queryFromSchema('Idea', $schema, $orderBy);
		json_debug($sql);
		$results = self::mysqlQuery($sql);
		foreach ($results as $idx => $result) {
			$results[$idx] = new Idea($result);
		}
		return $results;
	}
	
	static public function open($id) {
		$schema = new StdClass;
		$schema->properties->id->enum = array($id);
		$results = self::search($schema);
		return count($results) ? $results[0] : NULL;
	}
	
	static public function create($obj) {
		if (!$obj) {
			$obj = json_decode('
				{
					"title": "New idea"
				}
			');
		}
		unset($obj->id);
		return new Idea($obj);
	}
	
	public function put($obj) {
		$obj->id = $this->id;
		$patch = json_diff($this, $obj);
		if (count($patch) == 0) {
			return;
		}
		foreach ($obj as $key => $value) {
			$this->$key = $value;
		}
		foreach ($this as $key => $value) {
			if (!isset($obj->$key)) {
				unset($this->$key);
			}
		}
		$this->save();
	}
}
JsonStore::addMysqlConfig('Idea', array(
	"table" => "ideas",
	"keyColumn" => "integer/id",
	"columns" => array(
		"json" => "json",
		"integer/id" => "id",
		"string/title" => "title",
		"string/feasibility" => "feasibility"
	)
));

?>