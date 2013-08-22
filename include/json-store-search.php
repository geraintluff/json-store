<?php

class JsonSchema extends StdClass {
	static private $subSchemaProperties = array("not", "items", "additionalProperties", "additionalItems");
	static private $subSchemaArrayProperties = array("items", "allOf", "oneOf", "anyOf");
	static private $subSchemaObjectProperties = array("properties", "definitions");
	static private $plainArrayProperties = array("required", "allOf");
	static private $objectKeywords = array("properties");
	static private $arrayKeywords = array("items", "maxItems", "minItems");
	
	static public function fromModel($data) {
		$schema = new JsonSchema();
		if (is_array($data) && count(array_keys($data)) && isset($data[0])) {
			// Single-item array - take to be "should contain"
			$schema->not->items->not = self::fromModel($data[0]);
		} elseif (is_object($data) || is_array($data)) {
			foreach ($data as $key => $value) {
				$schema->type = "object";
				$schema->properties->$key = self::fromModel($value);
			}
		} else {
			$schema->enum = array($data);
		}
		return $schema;
	}
	
	private $autoFillType;
	private $userSetType = FALSE;
	
	public function __construct($obj=NULL, $autoFillType=TRUE) {
		$this->autoFillType = $autoFillType;
		if ($obj) {
			foreach ($obj as $key => $value) {
				$this->$key = $value;
			}
		}
	}
	
	private function setTypeIfNeeded($key) {
		if ($key == "type") {
			$this->userSetType = TRUE;
			return;
		}
		if (!$this->autoFillType || $this->userSetType) {
			return;
		}
		$expectedType = NULL;
		if (in_array($key, self::$objectKeywords)) {
			$expectedType = "object";
		} else if (in_array($key, self::$arrayKeywords)) {
			$expectedType = "array";
		}
		if ($expectedType) {
			if (!isset($this->type)) {
				$this->type = $expectedType;
			} else if (is_array($this->type)) {
				if (!in_array($expectedType, $this->type)) {
					$this->type[] = $expectedType;
				}
			} else if (is_string($this->type)) {
				if ($this->type != $expectedType) {
					$this->type = array($this->type, $expectedType);
				}
			}
		}
		// If type was set above, then this will have been reset
		$this->userSetType = TRUE;
	}
	
	public function &__get($key) {
		if ($key == "contains") {
			$this->setTypeIfNeeded($key);
			// Not in official spec - just a short-hand for not->items->not
			$not = $this->__get("not");
			return $not->items->not;
		}
		if (in_array($key, self::$subSchemaProperties)) {
			$this->setTypeIfNeeded($key);
			if ($key == "not") {
				$this->$key = new JsonSchema(NULL, !$this->autoFillType);
			} else {
				$this->$key = new JsonSchema();
			}
		} else if (in_array($key, self::$subSchemaObjectProperties)) {
			$this->setTypeIfNeeded($key);
			$this->$key = new JsonSchemaMap();
		} else if (in_array($key, self::$plainArrayProperties)) {
			$this->setTypeIfNeeded($key);
			$this->$key = array();
		}
		return $this->$key;
	}
	
	public function __set($key, $value) {
		$this->setTypeIfNeeded($key);
		if (is_array($value) && in_array($key, self::$subSchemaArrayProperties)) {
			foreach ($value as $idx => $item) {
				$value[$idx] = new JsonSchema($item);
			}
		} else if (in_array($key, self::$subSchemaProperties)) {
			$value = new JsonSchema($value);
		}
		$this->$key = $value;
	}
	
	public function enum($arg1) {
		$args = func_get_args();
		if (count($args) == 1 && is_array($arg1)) {
			$this->enum = $arg1;
		} else {
			$this->enum = $args;
		}
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

class JsonStoreQueryConstructor {
	public function __construct($table, $alias) {
		$this->table = $table;
		$this->alias = $alias;
		$this->tableJoins = array("{$table} {$alias}");
	}
	public function __toString() {
		return $this->alias;
	}
	public function selectFrom() {
		return implode("\n\t", $this->tableJoins);
	}
	
	public function addLeftJoin($subTable, $joinOn) {
		if (is_string($subTable)) {
			$sql = "LEFT JOIN ".str_replace("\n", "\n\t", $subTable." ON $joinOn");
		} else {
			$sql = "LEFT JOIN (".str_replace("\n", "\n\t", $subTable->selectFrom().") ON $joinOn");
		}
		$this->tableJoins[] = $sql;
	}
	
	public function addJoin($subTable, $joinOn) {
		if (is_string($subTable)) {
			$sql = "JOIN ".str_replace("\n", "\n\t", $subTable." ON $joinOn");
		} else {
			$sql = "JOIN (".str_replace("\n", "\n\t", $subTable->selectFrom().") ON $joinOn");
		}
		$this->tableJoins[] = $sql;
	}
}

/** Full search, from a schema **/
class JsonStoreSearch {
	static public $INCOMPLETE_TAG = "incom`'plete"; // Sequence which can only occur in a comment, never a value or table/column name

	public function __construct($config, $schema, $path="") {
		$this->config = $config;
		$this->schema = $schema;
		if (!$schema) {
			$this->schema = new StdClass;
		}
		$this->path = $path;
	}

	public function tableColumn($tableName, $type, $path="") {
		return $tableName.".".JsonStore::escapedColumn($type.$path, $this->config);
	}
	
	// This should produce a query that is the negation of self::mysqlQuery().
	// It should be completely equivalent to a NOT(...) clause - however, for readability of the query, NOT clauses should be pushed as far down as possible
	// Crucially, if the data in question is not defined (is NULL in the DB), then these contraints should pass
	//	 -  for example, the negation of "a > 0" is not "a <= 0", but instead "(a <= 0 OR a IS NULL)", equivalent to "NOT(a > 0)";
	public function mysqlQueryNot($tableName) {
		return $this->mysqlQueryInner($tableName, TRUE);
	}
	
	public function mysqlQueryCount() {
		$tableName = new JsonStoreQueryConstructor($this->config['table'], "t");
		$whereConditions = str_replace("\n", "\n\t", $this->mysqlQuery($tableName));
		$result = "SELECT COUNT(*) AS count\n\tFROM {$tableName->selectFrom()}\n\tWHERE {$whereConditions}";
		return $result;
	}
	
	// This function can also be used to generate the complete SELECT query (by omitting the table name)
	//   -  the actual logic is in self::mysqlQueryInner()
	public function mysqlQuery($tableName=NULL, $orderBy=NULL, $limit=NULL) {
		if ($tableName == NULL) {
			$tableName = new JsonStoreQueryConstructor($this->config['table'], "t");
			$whereConditions = str_replace("\n", "\n\t", $this->mysqlQuery($tableName));
			$result = "SELECT DISTINCT {$tableName}.*\n\tFROM {$tableName->selectFrom()}\n\tWHERE {$whereConditions}";
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
		$typeArray = NULL;
		if (isset($schema->type)) {
			$typeArray = $schema->type;
			unset($schema->type);
			if (!is_array($typeArray)) {
				$typeArray = array($typeArray);
			} else if (in_array('integer', $typeArray) && in_array('number', $typeArray)) {
				array_splice($typeArray, array_search('integer', $typeArray), 1);
			}	
		}
		
		// Go through known keywords, removing them after processing
		if (isset($schema->anyOf)) {
			$constraints->add(new JsonStoreSearchAnyOf($this->config, $schema->anyOf, $this->path));
			unset($schema->anyOf);
		}
		if (isset($schema->items) || isset($schema->maxItems) || isset($schema->minItems)) {
			$arrayConstraints = new JsonStoreSearchAnd();
			if (isset($typeArray)) {
				$objIndex = array_search("array", $typeArray);
				if ($objIndex !== FALSE) {
					// TODO: does this work if the item schemas just contain "not", and the properties are not defined?
					$typeArray[$objIndex] = $arrayConstraints;
					$arrayConstraints->add(new JsonStoreSearchType($this->config, array("array"), $this->path));
				}
			} else {
				$or = new JsonStoreSearchOr();
				$or->add($arrayConstraints);
				if (!isset($this->config['columns']['array'.$this->path]['parentColumn'])) {
					$or->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("array"), $this->path)));
				}
				$constraints->add($or);
			}
			if (isset($schema->items) && !is_array($schema->items)) {
				$arrayConstraints->add(new JsonStoreSearchItems($this->config, $schema->items, $this->path));
				unset($schema->items);
			}
			if (isset($schema->maxItems) || isset($schema->minItems)) {
				$arrayConstraints->add(new JsonStoreSearchItemLimits($this->config, array(
					"minItems" => isset($schema->minItems) ? $schema->minItems : NULL,
					"maxItems" => isset($schema->maxItems) ? $schema->maxItems : NULL
				), $this->path));
				unset($schema->minItems);
				unset($schema->maxItems);
			}
		} else if (isset($schema->not) && isset($schema->not->items)) {
			// We can *splice*
		}
		if (isset($schema->properties)) {
			$propertyConstraints = new JsonStoreSearchAnd();
			if (isset($typeArray)) {
				$objIndex = array_search("object", $typeArray);
				if ($objIndex !== FALSE) {
					// TODO: does this work if the property schemas just contain "not", and the properties are not defined?
					$typeArray[$objIndex] = $propertyConstraints;
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
			if (isset($typeArray)) {
				$stringIndex = array_search("string", $typeArray);
				if ($stringIndex !== FALSE) {
					// The string constraints will also constitute a type-check
					$typeArray[$stringIndex] = $stringConstraints;
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
			if (isset($typeArray)) {
				$numberIndex = array_search("number", $typeArray);
				if ($numberIndex === FALSE) {
					$numberType = "integer";
					$numberIndex = array_search("integer", $typeArray);
				}
				if ($numberIndex !== FALSE) {
					// The numerical constraints will also constitute a type-check
					$typeArray[$numberIndex] = $numberConstraints;
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
		if (isset($typeArray)) {
			$constraints->add(new JsonStoreSearchType($this->config, $typeArray, $this->path));
		}
		if (isset($schema->enum)) {
			$constraints->add(new JsonStoreSearchEnum($this->config, $schema->enum, $this->path));
			unset($schema->enum);
		}
		if (isset($schema->not)) {
			$constraints->add(new JsonStoreSearchNot(new JsonStoreSearch($this->config, $schema->not, $this->path)));
			unset($schema->not);
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
		$result = array();
		foreach ($this->components as $c) {
			$option = $c->mysqlQueryNot($tableName);
			if ($option === '1') {
				return '1';
			} else if ($option !== '0') {
				$result[] = $option;
			}
		}
		if (count($result) == 0) {
			return "0";
		} else if (count($result) == 1) {
			return $result[0];
		}
		foreach ($result as $idx => $option) {
			$result[$idx] = str_replace("\n", "\n\t", $option);
		}
		return "(".implode("\n\tOR ", $result).")";
	}
	
	public function mysqlQuery($tableName) {
		$result = array();
		foreach ($this->components as $c) {
			$option = $c->mysqlQuery($tableName);
			if ($option === '0') {
				return '0';
			} else if ($option !== '1') {
				$result[] = $option;
			}
		}
		if (count($result) == 0) {
			return "1";
		} else if (count($result) == 1) {
			return $result[0];
		}
		foreach ($result as $idx => $option) {
			$result[$idx] = str_replace("\n", "\n\t", $option);
		}
		return "(".implode("\n\tAND ", $result).")";
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
		$result = array();
		foreach ($this->components as $c) {
			$option = $c->mysqlQuery($tableName);
			if ($option === '1') {
				return '1';
			} else if ($option !== '0') {
				$result[] = $option;
			}
		}
		if (count($result) == 0) {
			return "0";
		} else if (count($result) == 1) {
			return $result[0];
		}
		foreach ($result as $idx => $option) {
			$result[$idx] = str_replace("\n", "\n\t", $option);
		}
		return "(".implode("\n\tOR ", $result).")";
	}
	
	public function mysqlQueryNot($tableName) {
		$result = array();
		foreach ($this->components as $c) {
			$option = $c->mysqlQueryNot($tableName);
			if ($option === '0') {
				return '0';
			} else if ($option !== '1') {
				$result[] = $option;
			}
		}
		if (count($result) == 0) {
			return "1";
		} else if (count($result) == 1) {
			return $result[0];
		}
		foreach ($result as $idx => $option) {
			$result[$idx] = str_replace("\n", "\n\t", $option);
		}
		return "(".implode("\n\tAND ", $result).")";
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
			} else if ($type == "array" && $this->hasColumn('array', $path)) {
				$columnName = 'array'.$path;
				$arrayConfig = $columns[$columnName];
				if (!isset($arrayConfig['parentColumn'])) {
					$options[] = $this->tableColumn($tableName, $columnName)." IS NOT NULL";
				} else {
					$otherOptions = new JsonStoreSearchAnd();
					// If parentColumn is set, then it's *always* an array, unless you can find something else it might be
					$objColumns = $this->subColumns($path);
					if (count($objColumns)) {
						$otherOptions->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("object"), $this->path)));
					}
					if ($this->hasColumn('boolean', $path)) {
						$otherOptions->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("boolean"), $this->path)));
					}
					if ($this->hasColumn('string', $path)) {
						$otherOptions->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("string"), $this->path)));
					}
					if ($this->hasColumn('number', $path)) {
						$otherOptions->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("number"), $this->path)));
					} else if ($this->hasColumn('integer', $path)) {
						$otherOptions->add(new JsonStoreSearchNot(new JsonStoreSearchType($this->config, array("integer"), $this->path)));
					}
					$options[] = $otherOptions->mysqlQuery($tableName);
				}
			} else if ($this->hasColumn('json', $path)) {
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
			} else if ($type == "array" && $this->hasColumn('array', $path)) {
				$columnName = 'array'.$path;
				$arrayConfig = $columns[$columnName];
				if (!isset($arrayConfig['parentColumn'])) {
					$andOptions[] = $this->tableColumn($tableName, $columnName)." IS NULL";
				} else {
					$otherOptions = new JsonStoreSearchOr();
					// If parentColumn is set, then it's *always* an array, unless you can find something else it might be
					$objColumns = $this->subColumns($path);
					if (count($objColumns)) {
						$otherOptions->add(new JsonStoreSearchType($this->config, array("object"), $this->path));
					}
					if ($this->hasColumn('boolean', $path)) {
						$otherOptions->add(new JsonStoreSearchType($this->config, array("boolean"), $this->path));
					}
					if ($this->hasColumn('string', $path)) {
						$otherOptions->add(new JsonStoreSearchType($this->config, array("string"), $this->path));
					}
					if ($this->hasColumn('number', $path)) {
						$otherOptions->add(new JsonStoreSearchType($this->config, array("number"), $this->path));
					} else if ($this->hasColumn('integer', $path)) {
						$otherOptions->add(new JsonStoreSearchType($this->config, array("integer"), $this->path));
					}
					$andOptions[] = $otherOptions->mysqlQuery($tableName);
				}
			} else if ($this->hasColumn('json', $path)) {
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
			return "$column > ".JsonStore::mysqlQuote($value);
		} else {
			return "$column >= ".JsonStore::mysqlQuote($value);
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
			return "$column < ".JsonStore::mysqlQuote($value);
		} else {
			return "$column <= ".JsonStore::mysqlQuote($value);
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

class JsonStoreSearchItems extends JsonStoreSearchConstraint {
	public function mysqlQuery($tableName) {
		if (!$this->hasColumn("array", $this->path)) {
			return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check array items at {$this->path} */";
		}
		$columnName = "array".$this->path;
		$parentConfig = $this->config;
		$itemSchema = $this->values;
		$arrayConfig = $parentConfig['columns'][$columnName];

		$subSearch = new JsonStoreSearch($arrayConfig, $itemSchema, "");
		$subTable = new JsonStoreQueryConstructor($arrayConfig['table'], $tableName."_items");
		$subSql = $subSearch->mysqlQueryNot($subTable);
	
		$joinOn = $subSearch->tableColumn($subTable, "group")." = ".$this->tableColumn($tableName, $arrayConfig['parentColumn']);
		$joinOn .= " AND ".$subSql;
		if (isset($arrayConfig['parentColumn'])) {
			$tableName->addLeftJoin($subTable, $joinOn);
		} else {
			$tableName->addLeftJoin($subTable, $joinOn);
		}
		
		return $subSearch->tableColumn($subTable, "group")." IS NULL";
	}

	public function mysqlQueryNot($tableName) {
		if (!$this->hasColumn("array", $this->path)) {
			return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check array items at {$this->path} */";
		}
		$columnName = "array".$this->path;
		$parentConfig = $this->config;
		$itemSchema = $this->values;
		$arrayConfig = $parentConfig['columns'][$columnName];

		$subSearch = new JsonStoreSearch($arrayConfig, $itemSchema, "");
		$subTable = new JsonStoreQueryConstructor($arrayConfig['table'], $tableName."_items");
		$subSql = $subSearch->mysqlQueryNot($subTable);
	
		$joinOn = $subSearch->tableColumn($subTable, "group")." = ".$this->tableColumn($tableName, $arrayConfig['parentColumn']);
		$joinOn .= " AND ".$subSql;
		if (isset($arrayConfig['parentColumn'])) {
			$tableName->addLeftJoin($subTable, $joinOn);
		} else {
			$tableName->addLeftJoin($subTable, $joinOn);
		}
		
		return $subSearch->tableColumn($subTable, "group")." IS NOT NULL";
	}
}

class JsonStoreSearchItemLimits extends JsonStoreSearchConstraint {
	protected function mysqlQueryInner($tableName, $inverted=FALSE) {
		if (!$this->hasColumn("array", $this->path)) {
			return "1 /* ".JsonStoreSearch::$INCOMPLETE_TAG.": can't check array items at {$this->path} */";
		}
		$columnName = "array".$this->path;
		$parentConfig = $this->config;
		$limitParams = (object)$this->values;
		$arrayConfig = $parentConfig['columns'][$columnName];

		$subSearch = new JsonStoreSearch($arrayConfig, new JsonSchema, "");
		$subTable = new JsonStoreQueryConstructor($arrayConfig['table'], $tableName."_itemlimits");

		$joinOn = $subSearch->tableColumn($subTable, "group")." = ".$this->tableColumn($tableName, $arrayConfig['parentColumn']);
		$subQuery = "(\n\tSELECT COUNT(*) AS `row_count`\n\t\tFROM ".str_replace("\n", "\n\t", $subTable->selectFrom())."\n\tWHERE {$joinOn}\n)";
	
		$condition = NULL;
		if (!$inverted) {
			if (isset($limitParams->minItems)) {
				if (isset($limitParams->maxItems)) {
					$condition = "BETWEEN ".JsonStore::mysqlQuote($limitParams->minItems)." AND ".JsonStore::mysqlQuote($limitParams->maxItems);
				} else {
					$condition = ">= ".JsonStore::mysqlQuote($limitParams->minItems);
				}
			} else {
				$condition = "<= ".JsonStore::mysqlQuote($limitParams->maxItems);
			}
		} else {
			if (isset($limitParams->minItems)) {
				if (isset($limitParams->maxItems)) {
					$condition = "NOT BETWEEN ".JsonStore::mysqlQuote($limitParams->minItems)." AND ".JsonStore::mysqlQuote($limitParams->maxItems);
				} else {
					$condition = "< ".JsonStore::mysqlQuote($limitParams->minItems);
				}
			} else {
				$condition = "> ".JsonStore::mysqlQuote($limitParams->maxItems);
			}
		}
		return "{$subQuery} {$condition}";
	}
	
	public function mysqlQuery($tableName) {
		return $this->mysqlQueryInner($tableName, FALSE);
	}

	public function mysqlQueryNot($tableName) {
		return $this->mysqlQueryInner($tableName, TRUE);
	}
}

?>