<?php

class JsonSchema extends StdClass {
	static private $subSchemaProperties = array("not", "items", "additionalProperties", "additionalItems");
	static private $subSchemaArrayProperties = array("items", "allOf", "oneOf", "anyOf");
	static private $subSchemaObjectProperties = array("properties", "definitions");
	static private $plainArrayProperties = array("required", "allOf");
	
	static public function fromModel($data) {
		$schema = new JsonSchema();
		if (is_object($data) || is_array($data)) {
			foreach ($data as $key => $value) {
				$schema->type = "object";
				$schema->properties->$key = self::fromModel($value);
			}
		} else {
			$schema->enum = array($data);
		}
		return $schema;
	}
	
	public function __construct($obj=NULL) {
		if ($obj) {
			foreach ($obj as $key => $value) {
				$this->$key = $value;
			}
		}
	}
	
	public function &__get($key) {
		if (in_array($key, self::$subSchemaProperties)) {
			$this->$key = new JsonSchema();
		} else if (in_array($key, self::$subSchemaObjectProperties)) {
			if (!isset($this->type)) {
				if ($key == "properties") {
					$this->type = "object";
				}
			}
			$this->$key = new JsonSchemaMap();
		} else if (in_array($key, self::$plainArrayProperties)) {
			$this->$key = array();
		}
		return $this->$key;
	}
	
	public function __set($key, $value) {
		if (is_array($value) && in_array($key, self::$subSchemaArrayProperties)) {
			foreach ($value as $idx => $item) {
				$value[$idx] = new JsonSchema($item);
			}
		} else if (in_array($key, self::$subSchemaProperties)) {
			$value = new JsonSchema($value);
		} else {
			if (!isset($this->type)) {
				if ($key == "pattern") {
					$this->type = "string";
				}
			}
		}
		$this->$key = $value;
	}
}
class JsonSchemaMap {
	public function __get($key) {
		$this->$key = new JsonSchema();
		return $this->$key;
	}
	public function __set($key, $value) {
		$this->$key = new JsonSchema($value);
	}
}

/** Full search, from a schema **/
class JsonStoreSearch {
	static public $INCOMPLETE_TAG = "incom`'plete"; // Sequence which can only occur in a comment, never a value or table/column name

	public function __construct($config, $schema, $path="") {
		$this->config = $config;
		$this->schema = $schema;
		$this->path = $path;
	}

	// This should produce a query that is the negation of self::mysqlQuery().
	// It should be completely equivalent to a NOT(...) clause - however, for readability of the query, NOT clauses should be pushed as far down as possible
	// Crucially, if the data in question is not defined (is NULL in the DB), then these contraints should pass
	//     -  for example, the negation of "a > 0" is not "a <= 0", but instead "(a <= 0 OR a IS NULL)", equivalent to "NOT(a > 0)";
	public function mysqlQueryNot($tableName) {
		return $this->mysqlQueryInner($tableName, TRUE);
	}
	
	// This function can also be used to generate the complete SELECT query (by omitting the table name)
	//   -  the actual logic is in self::mysqlQueryInner()
	public function mysqlQuery($tableName=NULL, $orderBy=NULL, $limit=NULL) {
		if ($tableName == NULL) {
			$tableName = "t";
			$result = "SELECT {$tableName}.*\n\tFROM {$this->config['table']} {$tableName}\n\tWHERE ";
			$result .= $this->mysqlQuery($tableName);
			if ($orderBy && !is_array($orderBy)) {
				$orderBy = array(
					$orderBy => "ASC"
				);
			}
			if (count($orderBy)) {
				$newOrder = array();
				foreach ($orderBy as $orderColumn => $direction) {
					foreach (array("boolean", "integer", "number", "string", "json") as $basicType) {
						$compositeColumn = $basicType.$orderColumn;
						if (isset($this->config['columns'][$compositeColumn])) {
							$newOrder[$compositeColumn] = "{$tableName}.".JsonStore::escapedColumn($compositeColumn, $this->config)." {$direction}";
						}
					}
					if (isset($this->config['columns'][$orderColumn])) {
						$newOrder[$orderColumn] = "{$tableName}.".JsonStore::escapedColumn($orderColumn, $this->config)." {$direction}";
					}
				}
				$result .= "\nORDER BY ".implode(", ", $newOrder);
			}
			if ($limit) {
				if (is_array($limit)) {
					if (isset($limit['count']) && isset($limit['to'])) {
						$limit['from'] = max(0, $limit['to'] - $limit['count']);
					} else if (isset($limit['to']) && isset($limit['from'])) {
						$limit['count'] = max(0, $limit['to'] - $limit['from']);
					}
					if (isset($limit['from'])) {
						$limit = "{$limit['from']}, {$limit['count']}";
					} else {
						$limit = "{$limit['count']}";
					}
				}
				$result .= "\nLIMIT {$limit}";
			}
			return $result;
		}
		return $this->mysqlQueryInner($tableName, FALSE);
	}
	
	private function mysqlQueryInner($tableName, $inverted) {
		$constraints = new JsonStoreSearchAnd();
		$schema = clone $this->schema;
		if (isset($schema->type)) {
			if (!is_array($schema->type)) {
				$schema->type = array($schema->type);
			} else if (in_array('integer', $schema->type) && in_array('number', $schema->type)) {
				array_splice($schema->type, array_search('integer', $schema->type), 1);
			}	
		}
		
		// Go through known keywords, removing them after processing
		if (isset($schema->anyOf)) {
			$constraints->add(new JsonStoreSearchAnyOf($this->config, $schema->anyOf, $this->path));
			unset($schema->anyOf);
		}
		if (isset($schema->not)) {
			$constraints->add(new JsonStoreSearchNot(new JsonStoreSearch($this->config, $schema->not, $this->path)));
			unset($schema->not);
		}
		if (isset($schema->properties)) {
			$propertyConstraints = new JsonStoreSearchAnd();
			if (isset($schema->type)) {
				$objIndex = array_search("object", $schema->type);
				if ($objIndex !== FALSE) {
					// TODO: does this work if the property schemas just contain "not", and the properties are not defined?
					//  - 
					$schema->type[$objIndex] = $propertyConstraints;
				}
			} else {
				$or = new JsonStoreSearchOr();
				$or->add($propertyConstraints);
				$or->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("object"), $this->path)));
				$constraints->add($or);
			}
			foreach ($schema->properties as $propertyName => $subSchema) {
				$path = $this->path.JsonStore::joinJsonPointer(array($propertyName));
				$propertyConstraints->add(new JsonStoreSearch($this->config, $subSchema, $path));
			}
			unset($schema->properties);
		}
		if (isset($schema->pattern)) {
			$stringConstraints = new JsonStoreSearchAnd();
			if (isset($schema->type)) {
				$stringIndex = array_search("string", $schema->type);
				if ($stringIndex !== FALSE) {
					// The string constraints will also constitute a type-check
					$schema->type[$stringIndex] = $stringConstraints;
				}
			} else {
				$or = new JsonStoreSearchOr();
				$or->add($stringConstraints);
				$or->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("string"), $this->path)));
				$constraints->add($or);
			}
			if (isset($schema->pattern)) {
				$stringConstraints->add(new JsonStoreSearchPattern($this->config, $schema->pattern, $this->path));
				unset($schema->pattern);
			}
		}
		if (isset($schema->minimum) || isset($schema->maximum)) {
			$numberConstraints = new JsonStoreSearchAnd();
			$numberType = "number";
			if (isset($schema->type)) {
				$numberIndex = array_search("number", $schema->type);
				if ($numberIndex === FALSE) {
					$numberType = "integer";
					$numberIndex = array_search("integer", $schema->type);
				}
				if ($numberIndex !== FALSE) {
					// The numerical constraints will also constitute a type-check
					$schema->type[$numberIndex] = $numberConstraints;
				}
			} else {
				$or = new JsonStoreSearchOr();
				$or->add($numberConstraints);
				$or->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("number"), $this->path)));
				$constraints->add($or);
			}
			if (isset($schema->minimum)) {
				$exclusive = (isset($schema->exclusiveMinimum) ? $schema->exclusiveMinimum : FALSE);
				$numberConstraints->add(new JsonStoreSearchMinimum($this->config, $schema->minimum, $exclusive, $numberType, $this->path));
			}
			if (isset($schema->maximum)) {
				$exclusive = (isset($schema->exclusiveMaximum) ? $schema->exclusiveMaximum : FALSE);
				$numberConstraints->add(new JsonStoreSearchMaximum($this->config, $schema->maximum, $exclusive, $numberType, $this->path));
			}
			unset($schema->minimum);
			unset($schema->exclusiveMinimum);
			unset($schema->maximum);
			unset($schema->exclusiveMaximum);
		}
		if (isset($schema->type)) {
			$constraints->add(new JsonStoreSearchType($this->config, $schema->type, $this->path));
			unset($schema->type);
		}
		if (isset($schema->enum)) {
			$constraints->add(new JsonStoreSearchEnum($this->config, $schema->enum, $this->path));
			unset($schema->enum);
		}

		$unknownKeywords = array_keys(get_object_vars($schema));
		$prefix = count($unknownKeywords) ? "/* ".JsonStoreSearch::$INCOMPLETE_TAG.": Unknown schema keywords: ".implode(", ", $unknownKeywords)." */ " : "";
		if ($inverted) {
			return $prefix.$constraints->mysqlQueryNot($tableName);
		} else {
			return $prefix.$constraints->mysqlQuery($tableName);
		}
	}
}
class JsonStoreSearchAnd {
	public $components = array();
	public function __construct() {
	}
	
	public function add($component) {
		$this->components[] = $component;
	}
	
	public function mysqlQueryNot($tableName) {
		if (count($this->components) == 0) {
			return "0";
		} else if (count($this->components) == 1) {
			return $this->components[0]->mysqlQueryNot($tableName);
		} else {
			$result = array();
			foreach ($this->components as $c) {
				$result[] = $c->mysqlQueryNot($tableName);
			}
			if (count($result) == 1) {
				return $result[0];
			}
			return "(".implode(' OR ', $result).")";
		}
	}
	
	public function mysqlQuery($tableName) {
		if (count($this->components) == 0) {
			return "1";
		} else if (count($this->components) == 1) {
			return $this->components[0]->mysqlQuery($tableName);
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
class JsonStoreSearchOr {
	public $components = array();
	public function __construct() {
	}
	
	public function add($component) {
		$this->components[] = $component;
	}
	
	public function mysqlQuery($tableName) {
		if (count($this->components) == 0) {
			return "0";
		} else if (count($this->components) == 1) {
			return $this->components[0]->mysqlQuery($tableName);
		} else {
			$result = array();
			foreach ($this->components as $c) {
				$result[] = $c->mysqlQuery($tableName);
			}
			if (count($result) == 1) {
				return $result[0];
			}
			return "(".implode(' OR ', $result).")";
		}
	}
	
	public function mysqlQueryNot($tableName) {
		if (count($this->components) == 0) {
			return "1";
		} else if (count($this->components) == 1) {
			return $this->components[0]->mysqlQueryNot($tableName);
		} else {
			$result = array();
			foreach ($this->components as $c) {
				$result[] = $c->mysqlQueryNot($tableName);
			}
			if (count($result) == 1) {
				return $result[0];
			}
			return "(".implode(' AND ', $result).")";
		}
	}
}
class JsonStoreSearchNot {
	public function __construct($innerSearch) {
		$this->innerSearch = $innerSearch;
	}
	
	public function mysqlQuery($tableName) {
		return $this->innerSearch->mysqlQueryNot($tableName);
	}

	public function mysqlQueryNot($tableName) {
		return $this->innerSearch->mysqlQuery($tableName);
	}
}
class JsonStoreSearchAnyOf {
	public function __construct($config, $values, $path) {
		$this->options = array();
		foreach ($values as $schema) {
			$this->options[] = new JsonStoreSearch($config, $schema, $path);
		}
	}
	
	public function mysqlQuery($tableName) {
		if (count($this->options) == 0) {
			return "0";
		} else if (count($this->options) == 1) {
			return $this->options[0]->mysqlQuery($tableName);
		} else {
			$options = array();
			foreach ($this->options as $option) {
				$options[] = $option->mysqlQuery($tableName);
			}
			return "(".implode(" OR ", $options).")";
		}
	}
	
	public function mysqlQueryNot($tableName) {
		if (count($this->options) == 0) {
			return "1";
		} else if (count($this->options) == 1) {
			return $this->options[0]->mysqlQueryNot($tableName);
		} else {
			$options = array();
			foreach ($this->options as $options) {
				$options[] = $option->mysqlQueryNot($tableName);
			}
			return "(".implode(" AND ", $options).")";
		}
	}
}
abstract class JsonStoreSearchConstraint {
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
	
	public function mysqlQueryNot($tableName) {
		return " /* ".JsonStoreSearch::$INCOMPLETE_TAG.": Warning: falling back to NOT() clause */ NOT (".$this->mysqlQuery($tableName).")";
	}
	
	abstract public function mysqlQuery($tableName);
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
				return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check enum properly (value ".json_encode($enumValue)." at: $path)*/";
			}
		}
		if (count($options) == 0) {
			return "0 /* no options for enum */";
		} else if (count($options) == 1) {
			return $options[0];
		}
		return "(".implode(' OR ', $options).")";
	}
	public function mysqlQueryNot($tableName) {
		$path = $this->path;

		$options = array();
		foreach ($this->values as $enumValue) {
			if ($this->hasColumn('boolean', $path) && is_bool($enumValue)) {
				$options[] .= "NOT (".$this->tableColumn($tableName, 'boolean', $path)." = ".($enumValue ? '1' : '0').")";
			} else if ($this->hasColumn('number', $path) && is_numeric($enumValue) && !is_string($enumValue)) {
				$options[] .= "NOT (".$this->tableColumn($tableName, 'number', $path)." = '".$enumValue."'".")";
			} else if ($this->hasColumn('integer', $path) && is_int($enumValue)) {
				$options[] .= "NOT (".$this->tableColumn($tableName, 'integer', $path)." = ".$enumValue.")";
			} else if ($this->hasColumn('string', $path) && is_string($enumValue)) {
				$options[] .= "NOT (".$this->tableColumn($tableName, 'string', $path)." = '".JsonStore::mysqlEscape($enumValue)."')";
			} else if ($this->hasColumn('json', $path)) {
				$options[] .= "NOT (".$this->tableColumn($tableName, 'json', $path)." = '".JsonStore::mysqlEscape(json_encode($enumValue))."')";
			} else {
				return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check enum properly (value ".json_encode($enumValue)." at: $path)*/";
			}
		}
		if (count($options) == 0) {
			return "1";
		} else if (count($options) == 1) {
			return $options[0];
		}
		return "(".implode(' AND ', $options).")";
	}
}
class JsonStoreSearchType extends JsonStoreSearchConstraint {
	public function mysqlQuery($tableName) {
		$path = $this->path;
		$columns = $this->config['columns'];

		$options = array();
		$values = is_array($this->values) ? $this->values : array($this->values);
		foreach ($values as $type) {
			if (is_object($type)) {
				// Assume it's a search object
				// TODO: check using instanceof
				$options[] = $type->mysqlQuery($tableName);
				continue;
			}
			if ($type == "boolean" && $this->hasColumn('boolean', $path)) {
				$options[] = $this->tableColumn($tableName, 'boolean', $path)." IS NOT NULL";
			} else if ($type == "string" && $this->hasColumn('string', $path)) {
				$options[] = $this->tableColumn($tableName, 'string', $path)." IS NOT NULL";
			} else if ($type == "number" && $this->hasColumn('number', $path)) {
				$options[] = $this->tableColumn($tableName, 'number', $path)." IS NOT NULL";
			} else if ($type == "integer" && $this->hasColumn('integer', $path)) {
				$options[] = $this->tableColumn($tableName, 'integer', $path)." IS NOT NULL";
			} else if ($type == "integer" && $this->hasColumn('number', $path)) {
				$options[] = "MOD(".$this->tableColumn($tableName, 'number', $path).", 1) == 0";
			} else if ($columns['json'.$path]) {
				if ($type == "object") {
					$options[] = $this->tableColumn($tableName, 'json', $path)." LIKE '{%'";
				} else if ($type == "array") {
					$options[] = $this->tableColumn($tableName, 'json', $path)." LIKE '[%'";
				} else if ($type == "string") {
					$options[] = $this->tableColumn($tableName, 'json', $path)." LIKE '\\\"%'";
				} else if ($type == "number") {
					$options[] = $this->tableColumn($tableName, 'json', $path)." REGEXP '^[0-9']'";
				} else if ($type == "integer") {
					$options[] = $this->tableColumn($tableName, 'json', $path)." REGEXP '^[0-9']+$'";
				} else if ($type == "boolean") {
					$options[] = $this->tableColumn($tableName, 'json', $path)." = 'true'";
					$options[] = $this->tableColumn($tableName, 'json', $path)." = 'false'";
				} else if ($type == "null") {
					$options[] = $this->tableColumn($tableName, 'json', $path)." = 'null'";
				} else {
					return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": unknown value for \"type\" (value ".json_encode($type)." at: $path)*/";
				}
			} else if ($type == "object") {
				// If the object has known properties, assume that any object would have at least one of them
				$objColumns = $this->subColumns($path);
				if (count($objColumns)) {
					foreach ($objColumns as $columnName) {
						$options[] = $this->tableColumn($tableName, $columnName)." IS NOT NULL";
					}
				} else {
					return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check type properly (value ".json_encode($type)." at: $path)*/";
				}
			} else {
				return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check type properly (value ".json_encode($type)." at: $path)*/";
			}
		}
		if (count($options) == 0) {
			return "0 /* no types */";
		} else if (count($options) == 1) {
			return $options[0];
		}
		return "(".implode(' OR ', $options).")";
	}

	public function mysqlQueryNot($tableName) {
		$path = $this->path;
		$columns = $this->config['columns'];

		$andOptions = array();
		$values = is_array($this->values) ? $this->values : array($this->values);
		foreach ($values as $type) {
			if (is_object($type)) {
				// Assume it's a search object
				// TODO: check using instanceof
				$andOptions[] = $type->mysqlQueryNot($tableName);
				continue;
			}
			if ($type == "boolean" && $this->hasColumn('boolean', $path)) {
				$andOptions[] = $this->tableColumn($tableName, 'boolean', $path)." IS NULL";
			} else if ($type == "string" && $this->hasColumn('string', $path)) {
				$andOptions[] = $this->tableColumn($tableName, 'string', $path)." IS NULL";
			} else if ($type == "number" && $this->hasColumn('number', $path)) {
				$andOptions[] = $this->tableColumn($tableName, 'number', $path)." IS NULL";
			} else if ($type == "integer" && $this->hasColumn('integer', $path)) {
				$andOptions[] = $this->tableColumn($tableName, 'integer', $path)." IS NULL";
			} else if ($type == "integer" && $this->hasColumn('number', $path)) {
				$andOptions[] = "(".$this->tableColumn($tableName, 'number', $path)." IS NULL OR MOD(".$this->tableColumn($tableName, 'number', $path).", 1) <> 0)";
			} else if ($columns['json'.$path]) {
				if ($type == "object") {
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." LIKE '{%')";
				} else if ($type == "array") {
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." LIKE '[%')";
				} else if ($type == "string") {
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." LIKE '\\\"%')";
				} else if ($type == "number") {
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." REGEXP '^[0-9']')";
				} else if ($type == "integer") {
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." REGEXP '^[0-9']+$')";
				} else if ($type == "boolean") {
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." = 'true')";
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." = 'false')";
				} else if ($type == "null") {
					$andOptions[] = "NOT(".$this->tableColumn($tableName, 'json', $path)." = 'null')";
				} else {
					return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": unknown value for \"type\" (value ".json_encode($type)." at: $path)*/";
				}
			} else if ($type == "object") {
				// If the object has known properties, assume that any object would have at least one of them
				$objColumns = $this->subColumns($path);
				if (count($objColumns)) {
					foreach ($objColumns as $columnName) {
						$andOptions[] = $this->tableColumn($tableName, $columnName)." IS NULL";
					}
				} else {
					return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check type properly (value ".json_encode($type)." at: $path)*/";
				}
			} else {
				return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check type properly (value ".json_encode($type)." at: $path)*/";
			}
		}
		if (count($andOptions) == 0) {
			return "1 /* no types */";
		} else if (count($andOptions) == 1) {
			return $andOptions[0];
		}
		return "(".implode(' AND ', $andOptions).")";
	}
}
class JsonStoreSearchMinimum extends JsonStoreSearchConstraint {
	public function __construct($config, $value, $exclusive, $numberType, $path) {
		$values = (object)array(
			"value" => $value,
			"exclusive" => $exclusive,
			"numberType" => $numberType
		);
		parent::__construct($config, $values, $path);
	}
	public function mysqlQuery($tableName) {
		$path = $this->path;
		$value = (float)$this->values->value;
		
		if ($this->hasColumn('number', $path)) {
			$column = $this->tableColumn($tableName, 'number', $path);
		} else if ($this->values->numberType == "integer" && $this->hasColumn('integer', $path)) {
			$column = $this->tableColumn($tableName, 'integer', $path);
		} else {
			return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check numerical ({$this->values->numberType}) limits at $path */";
		}
		if ($this->values->exclusive) {
			return "$column > $value";
		} else {
			return "$column >= $value";
		}
	}
	public function mysqlQueryNot($tableName) {
		$path = $this->path;
		if ($this->hasColumn('number', $path) || ($this->values->numberType == "integer" && $this->tableColumn($tableName, 'number', $path))) {
			return parent::mysqlQueryNot($tableName);
		}
		return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check numerical ({$this->values->numberType}) limits at $path */";
	}
}

class JsonStoreSearchMaximum extends JsonStoreSearchConstraint {
	public function __construct($config, $value, $exclusive, $numberType, $path) {
		$values = (object)array(
			"value" => $value,
			"exclusive" => $exclusive,
			"numberType" => $numberType
		);
		parent::__construct($config, $values, $path);
	}
	public function mysqlQuery($tableName) {
		$path = $this->path;
		$value = (float)$this->values->value;
		
		if ($this->hasColumn('number', $path)) {
			$column = $this->tableColumn($tableName, 'number', $path);
		} else if ($this->values->numberType == "integer" && $this->hasColumn('integer', $path)) {
			$column = $this->tableColumn($tableName, 'integer', $path);
		} else {
			return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check numerical ({$this->values->numberType}) limits at $path */";
		}
		if ($this->values->exclusive) {
			return "$column < $value";
		} else {
			return "$column <= $value";
		}
	}
	public function mysqlQueryNot($tableName) {
		$path = $this->path;
		if ($this->hasColumn('number', $path) || ($this->values->numberType == "integer" && $this->tableColumn($tableName, 'number', $path))) {
			return parent::mysqlQueryNot($tableName);
		}
		return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check numerical ({$this->values->numberType}) limits at $path */";
	}
}

class JsonStoreSearchPattern extends JsonStoreSearchConstraint {
	public function mysqlQuery($tableName) {
		$path = $this->path;
		$pattern = $this->values;
		
		if ($this->hasColumn('string', $path)) {
			$column = $this->tableColumn($tableName, 'string', $path);
		} else {
			return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check string pattern at $path */";
		}
		return "$column REGEXP ".JsonStore::mysqlQuote($pattern);
	}
	public function mysqlQueryNot($tableName) {
		if ($this->hasColumn('string', $path)) {
			return parent::mysqlQueryNot($tableName);
		} else {
			return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check string pattern at $path */";
		}
	}
}

?>