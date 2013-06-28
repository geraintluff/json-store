<?php

class JsonStoreSearch {
	public function __construct($config, $schema, $path="") {
		$this->config = $config;
		$this->schema = $schema;
		$this->path = $path;
	}
	
	public function mysqlQuery($tableName=NULL, $orderBy=NULL) {
		if ($tableName == NULL) {
			$tableName = "t";
			$result = "SELECT {$tableName}.*\n\tFROM {$this->config['table']} {$tableName}\n\tWHERE ";
			$result .= $this->mysqlQuery($tableName, $sortColumns);
			if ($orderBy && !is_array($orderBy)) {
				$orderBy = array(
					$orderBy => "ASC"
				);
			}
			if (count($orderBy)) {
				$newOrder = array();
				foreach ($orderBy as $orderColumn => $direction) {
					$newOrder[$orderColumn] = "{$tableName}.".JsonStore::escapedColumn($orderColumn, $this->config)." {$direction}";
				}
				$result .= "\nORDER BY ".implode(", ", $newOrder);
			}
			return $result;
		}
		
		$constraints = new JsonStoreSearchAnd();
		foreach ($this->schema as $keyword => $value) {
			if ($keyword == "type") {
				$constraints->add(new JsonStoreSearchType($this->config, $value, $this->path));
			} else if ($keyword == "properties") {
				foreach ($value as $propertyName => $subSchema) {
					$path = $this->path.JsonStore::joinJsonPointer(array($propertyName));
					$constraints->add(new JsonStoreSearch($this->config, $subSchema, $path));
				}
			} elseif ($keyword == "enum") {
				$constraints->add(new JsonStoreSearchEnum($this->config, $value, $this->path));
			} else {
				return "1 /* Unknown schema keyword: $keyword */";
			}
		}
		return $constraints->mysqlQuery();
	}
}
class JsonStoreSearchAnd {
	public $components = array();
	public function __construct() {
	}
	
	public function add($component) {
		$this->components[] = $component;
	}
	
	public function mysqlQuery($tableName="t") {
		if (count($this->components) == 0) {
			return "1";
		} else {
			$result = array();
			foreach ($this->components as $c) {
				$result[] = $c->mysqlQuery($tableName);
			}
			if (count($result) == 1) {
				return $result[0];
			}
			return "(".implode(' AND ', $result).")";
		}
	}
}
class JsonStoreSearchConstraint {
	public function __construct($config, $values, $path) {
		$this->config = $config;
		$this->values = $values;
		$this->path = $path;
	}
	protected function hasColumn($type, $path) {
		$columns = $this->config['columns'];
		return isset($columns[$type.$path]);
	}
	protected function subColumns($objPath) {
		$columns = $this->config['columns'];
		$result = array();
		foreach ($columns as $columnName => $alias) {
			$parts = explode('/', $columnName, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			if (substr($path, 0, strlen($objPath) + 1) == $objPath."/") {
				$result[] = $columnName;
			}
		}
		return $result;
	}
	protected function tableColumn($tableName, $type, $path="") {
		return $tableName.".".JsonStore::escapedColumn($type.$path, $this->config);
	}
}
class JsonStoreSearchEnum extends JsonStoreSearchConstraint {
	public function mysqlQuery($tableName) {
		$path = $this->path;

		$options = array();
		foreach ($this->values as $enumValue) {
			if ($this->hasColumn('json', $path)) {
				$options[] .= $this->tableColumn($tableName, 'json', $path)." = '".JsonStore::mysqlEscape(json_encode($enumValue))."'";
			} else if ($this->hasColumn('boolean', $path) && is_bool($enumValue)) {
				$options[] .= $this->tableColumn($tableName, 'boolean', $path)." = ".($enumValue ? '1' : '0');
			} else if ($this->hasColumn('number', $path) && is_numeric($enumValue) && !is_string($enumValue)) {
				$options[] .= $this->tableColumn($tableName, 'number', $path)." = '".$enumValue."'";
			} else if ($this->hasColumn('integer', $path) && is_int($enumValue)) {
				$options[] .= $this->tableColumn($tableName, 'integer', $path)." = ".$enumValue;
			} else if ($this->hasColumn('string', $path) && is_string($enumValue)) {
				$options[] .= $this->tableColumn($tableName, 'string', $path)." = '".JsonStore::mysqlEscape($enumValue)."'";
			} else {
				return "1 /* can't check enum properly (value ".json_encode($enumValue)." at: $path)*/";
			}
		}
		return "(".implode(' OR ', $options).")";
	}
}
class JsonStoreSearchType extends JsonStoreSearchConstraint {
	public function mysqlQuery($tableName) {
		$path = $this->path;
		$columns = $this->config['columns'];

		$options = array();
		$values = is_array($this->values) ? $this->values : array($this->values);
		foreach ($values as $type) {
			if ($columns['json'.$path]) {
				if ($type == "object") {
					$options[] .= $this->tableColumn($tableName, 'json', $path)." LIKE '{%";
				} else if ($type == "array") {
					$options[] .= $this->tableColumn($tableName, 'json', $path)." LIKE '[%";
				} else if ($type == "string") {
					$options[] .= $this->tableColumn($tableName, 'json', $path)." LIKE '\\\"%";
				} else if ($type == "number") {
					$options[] .= $this->tableColumn($tableName, 'json', $path)." REGEXP '^[0-9']'";
				} else if ($type == "integer") {
					$options[] .= $this->tableColumn($tableName, 'json', $path)." REGEXP '^[0-9']+$'";
				} else if ($type == "boolean") {
					$options[] .= $this->tableColumn($tableName, 'json', $path)." = 'true'";
					$options[] .= $this->tableColumn($tableName, 'json', $path)." = 'false'";
				} else if ($type == "null") {
					$options[] .= $this->tableColumn($tableName, 'json', $path)." = 'null'";
				} else {
					return "1 /* unknown value for \"type\" (value ".json_encode($type)." at: $path)*/";
				}
			} else if ($type == "object") {
				$objColumns = $this->subColumns($path);
				if (count($objColumns)) {
					foreach ($objColumns as $columnName) {
						$options[] .= $this->tableColumn($tableName, $columnName)." IS NOT NULL";
					}
				} else {
					return "1 /* can't check type properly (value ".json_encode($type)." at: $path)*/";
				}
			} else {
				return "1 /* can't check type properly (value ".json_encode($type)." at: $path)*/";
			}
		}
		return "(".implode(' OR ', $options).")";
	}
}
?>