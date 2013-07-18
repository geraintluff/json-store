<?php

include_once(dirname(__FILE__).'/json-store-search.php');
include_once(dirname(__FILE__).'/jsv4.php');

class JsonStorePendingArray {
	static private $queue = array();

	static public function requestPending(&$target, $config, $groupId, $pathPrefix) {
		foreach (self::$queue as $pending) {
			if ($pending->config == $config && $pending->pathPrefix == $pathPrefix) {
				$pending->add($target, $groupId);
				return;
			}
		}
		$pending = new JsonStorePendingArray($config, $pathPrefix);
		$pending->add($target, $groupId);
		self::$queue[] = $pending;
	}
	
	static public function executePending() {
		while (count(self::$queue)) {
			$pending = array_shift(self::$queue);
			$pending->execute();
		}
	}

	private $config;
	private $targets = array();
	private $pathPrefix;
	private function __construct($config, $pathPrefix="") {
		$this->config = $config;
		$this->pathPrefix = $pathPrefix;
	}
	
	private function add(&$target, $groupId) {
		$this->targets[$groupId] =& $target;
	}

	private function execute() {
		JsonStore::loadArrayMultiple($this->targets, $this->config, TRUE, $this->pathPrefix);
	}
}

class JsonStore {
	static public $problemSchemasFile = NULL;

	static public function executePending() {
		JsonStorePendingArray::executePending();
	}

	static private $cache = array();
	static public function cached() {
		$params = func_get_args();
		$cacheKey = self::joinJsonPointer($params);
		if (isset(self::$cache[$cacheKey])) {
			return self::$cache[$cacheKey];
		}
	}
	static public function removeCached() {
		$params = func_get_args();
		$cacheKey = self::joinJsonPointer($params);
		unset(self::$cache[$cacheKey]);
	}
	static public function setCached() {
		$params = func_get_args();
		$value = array_pop($params);
		$cacheKey = self::joinJsonPointer($params);
		self::$cache[$cacheKey] = $value;
		return $value;
	}
	
	static public $showQueries = FALSE;
	static protected function mysqlErrorMessage() {
		return self::$mysqlConnector->error;
	}
	
	static public function mysqlEscape($value) {
		return self::$mysqlConnector->escape($value);
	}

	static public function mysqlQuote($value) {
		return self::$mysqlConnector->quote($value);
	}
	
	static public function mysqlQuote($value) {
		return self::$mysqlConnector->quote($value);
	}
	
	static private $mysqlConnector = NULL;
	static public function setConnection($connectionObj) {
		self::$mysqlConnector = $connectionObj;
	}
	static public function mysqlQuery($sql) {
		if (self::$showQueries) {
			echo('<div style="font-size: 0.9em; color: blue;">' . htmlentities($sql) . '</div>');
		}
		return self::$mysqlConnector->query($sql);
	}

	static public function splitJsonPointer($path) {
		if ($path == "") {
			return array();
		} else if ($path[0] != "/") {
			throw new Exception("JSON Pointers must start with '/': $path");
		}
		$path = substr($path, 1);
		$result = array();
		foreach (explode('/', $path) as $part) {
			$result[] = str_replace('~0', '~', str_replace('~1', '/', $part));
		}
		return $result;
	}
	
	static public function joinJsonPointer($parts) {
		$result = "";
		foreach ($parts as $part) {
			$result .= "/".str_replace('/', '~1', str_replace('~', '~0', $part));
		}
		return $result;
	}
	
	public static function escapedColumn($columnName, $config=NULL) {
		if ($config && isset($config['alias'][$columnName])) {
			$columnName = $config['alias'][$columnName];
		}
		if ($columnName[0] != "`") {
			$columnName = "`".str_replace("`", "``", $columnName)."`";
		}
		return $columnName;
	}

	private static function unescapedColumn($columnName, $config) {
		if (isset($config['alias'][$columnName])) {
			$columnName = $config['alias'][$columnName];
		}
		return $columnName;
	}
	
	private static function lookupColumn($columnName, $config) {
		if (isset($config['invAlias'][$columnName])) {
			$columnName = $config['invAlias'][$columnName];
		}
		return $columnName;
	}

	public static function loadArrayMultiple(&$targets, $config, $delayLoading, $pathPrefix="") {
		$whereIn = array();
		foreach ($targets as $id => &$target) {
			$whereIn[] = "'".self::mysqlEscape($id)."'";
			$arrayValue = self::pointerGet($target, $pathPrefix);
			if (!is_array($arrayValue)) {
				self::pointerSet($target, $pathPrefix, array());
			}
		}
		$whereIn = '('.implode(', ', $whereIn).')';
		$sql = "SELECT * FROM {$config['table']} WHERE ".self::escapedColumn("group", $config)." IN {$whereIn} ORDER BY ".self::escapedColumn("index", $config);
		
		$result = self::mysqlQuery($sql);
		$groupColumn = self::unescapedColumn("group", $config);
		$indexColumn = self::unescapedColumn("index", $config);
		foreach ($result as $idx => $row) {
			$groupId = $row[$groupColumn];
			$index = $row[$indexColumn];
			$target =& $targets[$groupId];
			$target = self::loadObject($row, $config, $delayLoading, $target, $pathPrefix."/".$index);
		}
	}

	private static function loadArray(&$target, $config, $delayLoading, $groupId, $pathPrefix="") {
		$sql = "SELECT * FROM {$config['table']} WHERE ".self::escapedColumn("group", $config)."=". (int)$groupId . " ORDER BY ".self::escapedColumn("index", $config);
		$result = self::mysqlQuery($sql);
		$arrayValue = self::pointerGet($target, $pathPrefix);
		if (!is_array($arrayValue)) {
			self::pointerSet($target, $pathPrefix, array());
		}
		foreach ($result as $index => $row) {
			self::loadObject($row, $config, $delayLoading, $target, $pathPrefix."/".$index);
		}
		return $target;
	}
	
	public static function loadObject($dbRow, $config, $delayLoading=FALSE, &$result=NULL, $pathPrefix="") {
		if (!$result) {
			$result = new StdClass;
		}
		ksort($dbRow);
		foreach ($dbRow as $column => $value) {
			$column = self::lookupColumn($column, $config);
			if (is_null($value)) {
				continue;
			}
			$parts = explode('/', $column, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			$path = $pathPrefix.$path;
			if ($type == "json") {
				self::pointerMerge($result, $path, json_decode($value));
			} else if ($type == "integer") {
				self::pointerMerge($result, $path, (int)$value);
			} else if ($type == "number") {
				self::pointerMerge($result, $path, (float)$value);
			} else if ($type == "string") {
				self::pointerMerge($result, $path, $value);
			} else if ($type == "boolean") {
				self::pointerMerge($result, $path, (boolean)$value);
			} else if ($type == "array") {
				$arrayConfig = $config['columns'][$column];
				if (!$arrayConfig['parentColumn'] && !is_null($value)) {
					if (!$delayLoading) {
						self::loadArray($result, $arrayConfig, FALSE, $value, $path);
					} else {
						JsonStorePendingArray::requestPending($result, $arrayConfig, $value, $path);
					}
				}
			}
		}
		foreach ($config['columns'] as $column => $subConfig) {
			$parts = explode('/', $column, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			$path = $pathPrefix.$path;
			if ($type == "array") {
				if ($subConfig['parentColumn']) {
					$groupColumn = $subConfig['parentColumn'];
					$value = $dbRow[$groupColumn];
					if (!is_null($value)) {
						if (!$delayLoading) {
							self::loadArray($result, $subConfig, FALSE, $value, $path);
						} else {
							JsonStorePendingArray::requestPending($result, $subConfig, $value, $path);
						}
					}
				}
			}
		}
		return $result;
	}
	
	static public function pointerMerge(&$target, $path, $value) {
		foreach (self::splitJsonPointer($path) as $part) {
			if (!isset($target) || (!is_object($target) && !is_array($target))) {
				$target = new StdClass;
			}
			if (is_object($target)) {
				if (!isset($target->$part)) {
					$target->$part = new StdClass;
				}
				$target =& $target->$part;
			} else if (is_array($target)) {
				$target =& $target[$part];
			} else {
				$target = new StdClass;
				$target->$part = new StdClass;
				$target =& $target->$part;
			}
		}
		if (is_object($value) && is_object($target)) {
			foreach ($value as $key => $subValue) {
				$target->$key = $subValue;
			}
		} else {
			$target = $value;
		}
	}

	static public function pointerSet(&$target, $path, $value) {
		foreach (self::splitJsonPointer($path) as $part) {
			if (!isset($target) || (!is_object($target) && !is_array($target))) {
				$target = new StdClass;
			}
			if (is_object($target)) {
				if (!isset($target->$part)) {
					$target->$part = new StdClass;
				}
				$target =& $target->$part;
			} else if (is_array($target)) {
				$target =& $target[$part];
			} else {
				$target = new StdClass;
				$target->$part = new StdClass;
				$target =& $target->$part;
			}
		}
		$target = $value;
	}

	static public function pointerRemove(&$target, $path) {
		$pathParts = self::splitJsonPointer($path);
		$finalPart = array_pop($pathParts);
		foreach ($pathParts as $part) {
			if (!isset($target)) {
				return;
			}
			if (is_object($target)) {
				if (!isset($target->$part)) {
					return;
				}
				$target =& $target->$part;
			} else if (is_array($target)) {
				if (!isset($target[$part])) {
					return;
				}
				$target =& $target[$part];
			} else {
				return;
			}
		}
		if (is_object($target)) {
			unset($target->$finalPart);
		} else if (is_array($target)) {
			unset($target[$part]);
		}
	}
	
	static public function pointerGet(&$target, $path) {
		foreach (self::splitJsonPointer($path) as $part) {
			if (!isset($target)) {
				return NULL;
			}
			if (is_object($target)) {
				if (!isset($target->$part)) {
					return NULL;
				}
				$target =& $target->$part;
			} else if (is_array($target)) {
				if (!isset($target[$part])) {
					return NULL;
				}
				$target =& $target[$part];
			} else {
				return NULL;
			}
		}
		return $target;
	}
	
	static private $mysqlConfigs = array();
	static public function addMysqlConfig($className, $config) {
		$newColumns = array();
		if (!isset($config['alias'])) {
			$config['alias'] = array();
		}
		foreach ($config['alias'] as $columnName => $aliasName) {
			if (!isset($config['columns'][$columnName])) {
				$config['columns'][$columnName] = $columnName;
			}
		}

		foreach ($config['columns'] as $columnName => $subConfig) {
			if (is_numeric($columnName)) {
				$columnName = $subConfig;
			}
			if (is_array($subConfig)) {
				$subConfig = self::addMysqlConfig(NULL, $subConfig);
			} else if (is_string($subConfig) && $subConfig != $columnName && !isset($config['alias'][$columnName])) {
				$config['alias'][$columnName] = $subConfig;
			}
			if ($columnName != 'group' && $columnName != 'index') {
				$newColumns[$columnName] = $subConfig;
			}
		}
		$config['columns'] = $newColumns;
		$config['invAlias'] = array_flip($config['alias']);
		
		if ($className) {
			self::$mysqlConfigs[$className] = $config;
		}
		return $config;
	}
	
	static public function getMysqlConfig($className) {
		return self::$mysqlConfigs[$className];
	}
	
	static public function schemaSearch($configName, $schemaObj, $orderBy=NULL) {
		$config = self::$mysqlConfigs[$configName];
		$search = new JsonStoreSearch($config, $schemaObj);
		$sql = $search->mysqlQuery(NULL, $orderBy);
		
		$results = self::mysqlQuery($sql);
		foreach ($results as $index => $item) {
			$results[$index] = self::loadObject($item, $config, TRUE);
		}
		self::executePending();
		
		if (strpos($sql, JsonStoreSearch::$INCOMPLETE_TAG)) {
			if (self::$problemSchemasFile) {
				$data = date('r')."\n\nConfig: $configName\n\nSchema:\n".json_encode($schemaObj)."\n\nQuery:\n".$sql;
				file_put_contents(self::$problemSchemasFile, $data);
			}
			$newResults = array();
			foreach ($results as $item) {
				$validation = Jsv4::validate($item, $schemaObj);
				if ($validation->valid) {
					$newResults[] = $item;
				}
			}
			return $newResults;
		} else {
			return $results;
		}
	}
	
	static public function queryFromSchema($className, $schema, $orderBy=NULL) {
		$config = self::$mysqlConfigs[$className];
		$search = new JsonStoreSearch($config, $schema);
		$sql = $search->mysqlQuery(NULL, $orderBy);
		return $sql;
	}
	
	protected function __construct($value=NULL, $delay=FALSE) {
		if (!$value) {
			return;
		}
		if (is_array($value)) {
			$config = $this->mysqlConfig();
			self::loadObject($value, $config, $delay, $this);
		} else {
			foreach ($value as $k => $v) {
				$this->$k = $v;
			}
		}
	}
	
	protected function mysqlConfig() {
		$className = get_class($this);
		return self::$mysqlConfigs[$className];
	}
	
	public function updateValue($obj) {
		$config = $this->mysqlConfig();
		if (isset($config['keyColumn'])) {
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			$value = self::pointerGet($this, $path);
			self::pointerSet($obj, $path, $value);
		}
		foreach ($obj as $key => $value) {
			$this->$key = $value;
		}
		foreach ($this as $key => $value) {
			if (!isset($obj->$key)) {
				unset($this->$key);
			}
		}
	}
	
	public function save() {
		$config = $this->mysqlConfig();
		if (isset($config['keyColumn'])) {
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			$value = self::pointerGet($this, $path);
			
			if (isset($value)) {
				return self::mysqlUpdate($this, $config);
			} else {
				return self::mysqlInsert($this, $config);
			}
		}
		$result = self::mysqlUpdate($this, $config);
		if ($result['affected_rows'] == 0) {
			$result = self::mysqlInsert($this, $config);
		}
		return $result;
	}
	
	public function delete() {
		return self::mysqlDelete($this, $this->mysqlConfig());
	}
		
	static public function mysqlValue($target, $column) {
		$parts = explode('/', $column, 2);
		$type = $parts[0];
		$path = count($parts) > 1 ? '/'.$parts[1] : '';
		$value = self::pointerGet($target, $path);
		if ($value instanceof JsonStore && $path != "") {
			throw new Exception("JsonStore objects cannot contain each other");
		}
		if ($type == "json") {
			return "'".self::mysqlEscape(json_encode($value))."'";
		} else if ($type == "integer") {
			return is_integer($value) ? $value : 'NULL';
		} else if ($type == "number") {
			return (is_numeric($value) && !is_string($value)) ? $value : NULL;
		} else if ($type == "string") {
			return is_string($value) ? "'".self::mysqlEscape($value)."'" : 'NULL';
		} else if ($type == "boolean") {
			return is_bool($value) ? ($value ? '1' : '0') : 'NULL';
		}
		return 'NULL';
	}
	
	static private function mysqlUpdate($value, $config) {
		$whereParts = array();
		if (isset($config['keyColumns'])) {
			$keyColumns = $config['keyColumns'];
		} else {
			$keyColumns = array($config['keyColumn']);
		}
		foreach ($keyColumns as $column) {
			$sqlValue = self::mysqlValue($value, $column);
			$column = self::escapedColumn($column, $config);
			$whereParts[] = "$column=".$sqlValue;
		}
		
		$sql = "UPDATE {$config['table']} SET
					".self::mysqlUpdateValues($value, $config, $whereParts)."
				WHERE ".implode(" AND ", $whereParts);
		$result = self::mysqlQuery($sql);
		if (!$result) {
			throw new Exception("Error saving: ".self::mysqlErrorMessage()."\n$sql");
		}
		return $result;
	}
	
	static private function mysqlUpdateValues($value, $config, $whereParts) {
		$columns = $config['columns'];
		$result = array();
		foreach ($columns as $column => $subConfig) {
			$parts = explode('/', $column, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			if ($type == "array") {
				self::mysqlDeleteArray($column, $value, $config, $whereParts);
				$subValue = self::pointerGet($value, $path);
				if (is_array($subValue)) {
					if (isset($subConfig['parentColumn'])) {
						$realColumn = self::lookupColumn($subConfig['parentColumn'], $config);
						$parts = explode('/', $realColumn, 2);
						$type = $parts[0];
						$path = count($parts) > 1 ? '/'.$parts[1] : '';
						$groupId = self::pointerGet($value, $path);
						self::mysqlInsertArray($subValue, $subConfig, $groupId);
						continue;
					}
					$sqlValue = self::mysqlInsertArray($subValue, $subConfig);
				} else {
					$sqlValue = 'NULL';
				}
			} else {
				$sqlValue = self::mysqlValue($value, $column);
			}

			if (is_array($subConfig) && isset($subConfig['parentColumn'])) {
				continue;
			}
			$column = self::escapedColumn($column, $config);
			$result[] = "$column=$sqlValue";
		}
		return implode(", ", $result);
	}
	
	static public function mysqlInsertColumns($config, $extras=NULL) {
		$columns = $config['columns'];
		$result = array();
		if ($extras) {
			foreach ($extras as $column) {
				$column = self::escapedColumn($column, $config);
				$result[] = $column;
			}
		}
		foreach ($columns as $column => $subConfig) {
			if (is_array($subConfig) && isset($subConfig['parentColumn'])) {
				continue;
			}
			$column = self::escapedColumn($column, $config);
			$result[] = $column;
		}
		return "(".implode(", ", $result).")";
	}

	static private function mysqlInsert(&$value, $config) {
		$sql = "INSERT INTO {$config['table']} ".self::mysqlInsertColumns($config)." VALUES
			".self::mysqlInsertValues($value, $config);
		$result = self::mysqlQuery($sql);

		if ($result && isset($config['keyColumn'])) {
			$insertId = $result['insert_id'];
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			if ($type == "string") {
				$insertId = (string)$insertId;
			}
			self::pointerSet($value, $path, $insertId);
			self::setCached(get_class($value), $insertId, $value);
		}

		// Insert all the arrays that relied on 'parentColumn', in case they relied on an insert_id
		$columns = $config['columns'];
		foreach ($columns as $column => $subConfig) {
			if (!is_array($subConfig) || !isset($subConfig['parentColumn'])) {
				continue;
			}
			$parts = explode('/', $column, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			
			if ($type == "array") {
				$subValue = self::pointerGet($value, $path);
				if (is_array($subValue)) {
					$realColumn = self::lookupColumn($subConfig['parentColumn'], $config);
					$parts = explode('/', $realColumn, 2);
					$type = $parts[0];
					$path = count($parts) > 1 ? '/'.$parts[1] : '';
					$groupId = self::pointerGet($value, $path);
					self::mysqlInsertArray($subValue, $subConfig, $groupId);
				}
			}
		}

		if (!$result) {
			throw new Exception("Error inserting: ".self::mysqlErrorMessage()."\n\t$sql\n");
		}
		return $result;
	}
	
	static private function mysqlInsertArray($value, $config, $groupId=NULL) {
		if (is_null($groupId)) {
			$groupId = 'NULL';
		}
		foreach ($value as $idx => $row) {
			$idx = (int)$idx;
			$sql = "INSERT INTO {$config['table']} ".self::mysqlInsertColumns($config, array('group', 'index'))." VALUES
				".self::mysqlInsertValues($row, $config, array($groupId, $idx));
			$result = self::mysqlQuery($sql);
			if ($groupId == 'NULL') {
				$groupId = $result['insert_id'];
			}
		}
		if ($groupId == 'NULL') {
			$groupId = '-1';
		}
		return $groupId;
	}
	
	static private function mysqlInsertValues($value, $config, $extras=NULL) {
		$columns = $config['columns'];
		$result = $extras ? $extras : array();
		foreach ($columns as $column => $subConfig) {
			if (is_array($subConfig) && isset($subConfig['parentColumn'])) {
				continue;
			}
			$parts = explode('/', $column, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			
			if ($type == "array") {
				$subValue = self::pointerGet($value, $path);
				if (is_array($subValue)) {
					$sqlValue = self::mysqlInsertArray($subValue, $subConfig);
				} else {
					$sqlValue = 'NULL';
				}
			} else {
				$sqlValue = self::mysqlValue($value, $column);
			}
			$result[] = $sqlValue;
		}
		return "(".implode(", ", $result).")";
	}
	
	static private function mysqlDelete($value, $config) {
		$whereParts = array();
		if (isset($config['keyColumns'])) {
			$keyColumns = $config['keyColumns'];
		} else {
			$keyColumns = array($config['keyColumn']);
		}
		foreach ($keyColumns as $column) {
			$sqlValue = self::mysqlValue($value, $column);
			$column = self::escapedColumn($column, $config);
			$whereParts[] = "$column=".$sqlValue;
		}
		
		foreach ($config['columns'] as $column => $subConfig) {
			$parts = explode('/', $column, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			if ($type == "array") {
				self::mysqlDeleteArray($column, $value, $config, $whereParts);
			}
		}
		
		$sql = "DELETE FROM {$config['table']}
				WHERE ".implode(" AND ", $whereParts);
		$result = self::mysqlQuery($sql);
		if (!$result) {
			throw new Exception("Error deleting: ".self::mysqlErrorMessage()."\n$sql");
		}
		if (isset($config['keyColumn'])) {
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			self::removeCached(get_class($value), self::pointerGet($value, $path));
			self::pointerRemove($value, $path);
		}
		if ($result['affected_rows'] == 0) {
			throw new Exception("Error deleting - no matches: \n$sql");
		}
		return $result;
	}

	static private function mysqlDeleteArray($arrayColumn, $value, $config, $whereParts) {
		$arrayConfig = $config['columns'][$arrayColumn];
		$arrayTable = $arrayConfig['table'];
		if ($arrayConfig['parentColumn']) {
			$arrayColumn = $arrayConfig['parentColumn'];
		}
		
		$arrayColumnSql = self::escapedColumn($arrayColumn, $config);
		$sql = "SELECT {$arrayColumnSql} FROM {$config['table']}
				WHERE ".implode(" AND ", $whereParts);
		$result = self::mysqlQuery($sql);
		
		$whereIn = array();
		foreach ($result as $row) {
			if (isset($row[$arrayColumn])) {
				$whereIn[] = (int)$row[$arrayColumn];
			}
		}
		
		if (count($whereIn)) {
			$sql = "DELETE FROM {$arrayTable} WHERE ".self::escapedColumn('group', $arrayConfig)." IN (".implode($whereIn).")";
			return self::mysqlQuery($sql);
		}
		return TRUE;
	}
}

abstract class JsonStoreConnection {
	public $error = FALSE;

	abstract public function query($sql);
	abstract public function escape($value);
<<<<<<< HEAD
=======
	
>>>>>>> 7ead51dfc75641ac5f8a7abe5607c8d792687156
	public function quote($value) {
		return "'".$this->escape($value)."'";
	}
}

class JsonStoreConnectionBasic extends JsonStoreConnection {
	private $mysqlConnection;
	
	public function __construct($hostname, $username, $password, $database) {
		$this->mysqlConnection = new mysqli($hostname, $username, $password, $database);
		if ($this->mysqlConnection->connect_errno) {
			throw new Exception("Failed to connext to MySQL: ".$this->mysqlConnection->connect_error);
		}
	}

	public function query($sql) {
		$mysqlConnection = $this->mysqlConnection;
		$result = $mysqlConnection->query($sql);
		if (!$result) {
			$this->error = $mysqlConnection->error;
			return FALSE;
		} else {
			$this->error = FALSE;
		}
		if ($result === TRUE) {
			return array(
				"insert_id" => $mysqlConnection->insert_id,
				"affected_rows" => $mysqlConnection->affected_rows,
				"info" => $mysqlConnection->info
			);
		}
		$resultArray = array();
		while ($row = $result->fetch_assoc()) {
			$resultArray[] = $row;
		}
		return $resultArray;
	}
	
	public function escape($value) {
		return $this->mysqlConnection->escape_string($value);
	}
}

?>