<?php
class Idea_gen extends JsonStore {
	static public function search($schema=NULL, $orderBy=NULL) {
		if (!$schema) {
			$schema = new StdClass;
		}
		if (!$orderBy) {
			$orderBy = array("integer\/id" => 'ASC');
		}
		$results = JsonStore::schemaSearch('Idea', $schema, $orderBy);
		foreach ($results as $idx => $result) {
			if ($cached = JsonStore::cached('Idea', $result->id)) {
				$results[$idx] = $cached;
				continue;
			}
			$results[$idx] = JsonStore.setCached('Idea', $result->id, new Idea($result));
		}
		return $results;
	}

	static public function open($id) {
		$model = newStdClass;
		$model->id = $id;
		$results = self::search(JsonSchema::fromModel($model));
		return count($results) ? $results[0] : NULL;
	}

	public function put($obj) {
		$obj->id = $this->id;
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
	
	public function get() {
		return $this;
	}
}
?>