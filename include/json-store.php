<?php

/*
	Defines:
		*	MYSQL_HOSTNAME
		*	MYSQL_USERNAME
		*	MYSQL_PASSWORD
		*	MYSQL_DATABASE
*/
require_once(dirname(__FILE__).'/config.php');

abstract class JsonStore {
	static public $showQueries = FALSE;
	static private $mysqlConnection;
	static private $mysqlErrorMessage = FALSE;
	static public function connectToDatabase($hostname, $username, $password, $database) {
		self::$mysqlConnection = new mysqli($hostname, $username, $password, $database);
		if (self::$mysqlConnection->connect_errno) {
			throw new Exception("Failed to connext to MySQL: ".self::$mysqlConnection->connect_error);
		}
	}
	static protected function mysqlErrorMessage() {
		return self::$mysqlErrorMessage;
	}
	
	static public function mysqlEscape($value) {
		return self::$mysqlConnection->escape_string($value);
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
	
	static public function mysqlQuery($sql) {
		if (self::$showQueries) {
			echo('<div style="font-size: 0.9em; color: blue;">' . htmlentities($sql) . '</div>');
		}
		$mysqlConnection = self::$mysqlConnection;
		$result = $mysqlConnection->query($sql);
		if (!$result) {
			self::$mysqlErrorMessage = $mysqlConnection->error;
			return FALSE;
		} else {
			self::$mysqlErrorMessage = FALSE;
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

	private static function loadArray(&$target, $config, $groupId, $pathPrefix="") {
		$sql = "SELECT * FROM {$config['table']} WHERE `group`=". (int)$groupId . " ORDER BY `index`";
		$result = self::mysqlQuery($sql);
		$arrayValue = self::pointerGet($target, $pathPrefix);
		if (!is_array($arrayValue)) {
			self::pointerSet($target, $pathPrefix, array());
		}
		foreach ($result as $index => $row) {
			self::loadObject($row, $config, $target, $pathPrefix."/".$index);
		}
		return $target;
	}
	
	public static function loadObject($dbRow, $config, $result=NULL, $pathPrefix="") {
		if (!$result) {
			$result = new StdClass;
		}
		ksort($dbRow);
		foreach ($dbRow as $column => $value) {
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
				if (!is_null($value)) {
					$arrayConfig = $config['columns'][$column];
					self::loadArray($result, $arrayConfig, $value, $path);
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
		foreach ($config['columns'] as $columnName => $subConfig) {
			if (is_numeric($columnName)) {
				$columnName = $subConfig;
			}
			if (is_array($subConfig)) {
				$subConfig = self::addMysqlConfig(NULL, $subConfig);
			}
			$newColumns[$columnName] = $subConfig;
		}
		$config['columns'] = $newColumns;
		
		if ($className) {
			self::$mysqlConfigs[$className] = $config;
		}
		return $config;
	}
	
	protected function __construct($value=NULL) {
		if (!$value) {
			return;
		}
		if (is_array($value)) {
			$config = $this->mysqlConfig();
			$value = self::loadObject($value, $config);
		}
		foreach ($value as $k => $v) {
			$this->$k = $v;
		}
	}
	
	private function mysqlConfig() {
		$className = get_class($this);
		return self::$mysqlConfigs[$className];
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
		
	static private function mysqlValue($target, $column) {
		$parts = explode('/', $column, 2);
		$type = $parts[0];
		$path = count($parts) > 1 ? '/'.$parts[1] : '';
		$value = self::pointerGet($target, $path);
		if ($type == "json") {
			return "'".self::mysqlEscape(json_encode($value))."'";
		} else if ($type == "integer") {
			return is_integer($value) ? $value : 'NULL';
		} else if ($type == "number") {
			return (is_numeric($value) && !is_string($value)) ? $value : NULL;
		} else if ($type == "string") {
			return is_string($value) ? "'".self::mysqlEscape($value)."'" : 'NULL';
		} else if ($type == "boolean") {
			return is_boolean($value) ? ($value ? '1' : '0') : 'NULL';
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
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$whereParts[] = "$column=".$sqlValue;
		}
		
		$sql = "UPDATE {$config['table']} SET
					".self::mysqlUpdateValues($value, $config, $whereParts)."
				WHERE ".implode(" AND ", $whereParts);
		$result = self::mysqlQuery($sql);
		if (!$result) {
			throw new Exception("Error saving: ".$value->mysqlErrorMessage."\n$sql");
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
					$sqlValue = self::mysqlInsertArray($subValue, $subConfig);
				} else {
					$sqlValue = 'NULL';
				}
			} else {
				$sqlValue = self::mysqlValue($value, $column);
			}

			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$result[] = "$column=$sqlValue";
		}
		return implode(", ", $result);
	}
	
	static public function mysqlInsertColumns($columns, $extras=NULL) {
		$result = $extras ? $extras : array();
		foreach ($columns as $column => $config) {
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$result[] = $column;
		}
		return "(".implode(", ", $result).")";
	}

	static private function mysqlInsert(&$value, $config) {
		$sql = "INSERT INTO {$config['table']} ".self::mysqlInsertColumns($config['columns'])." VALUES
			".self::mysqlInsertValues($value, $config['columns']);
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
		}
		if (!$result) {
			throw new Exception("Error inserting: ".$value->mysqlErrorMessage."\n$sql");
		}
		return $result;
	}
	
	static private function mysqlInsertArray($value, $config) {
		$groupId = 'NULL';
		foreach ($value as $idx => $row) {
			$idx = (int)$idx;
			$sql = "INSERT INTO {$config['table']} ".self::mysqlInsertColumns($config['columns'], array('`group`', '`index`'))." VALUES
				".self::mysqlInsertValues($row, $config['columns'], array($groupId, $idx));
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
	
	static private function mysqlInsertValues($value, $columns, $extras=NULL) {
		$result = $extras ? $extras : array();
		foreach ($columns as $column => $subConfig) {
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
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
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
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
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
			throw new Exception("Error deleting: ".self::mysqlErrorMessage."\n$sql");
		}
		if (isset($config['keyColumn'])) {
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
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
		
		$arrayColumnSql = $arrayColumn;
		if ($arrayColumnSql[0] != "`") {
			$arrayColumnSql = "`".str_replace("`", "``", $arrayColumnSql)."`";
		}
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
			$sql = "DELETE FROM {$arrayTable} WHERE `group` IN (".implode($whereIn).")";
			return self::mysqlQuery($sql);
			var_dump($sql);
		}
		return TRUE;
	}
}
JsonStore::connectToDatabase(MYSQL_HOSTNAME, MYSQL_USERNAME, MYSQL_PASSWORD, MYSQL_DATABASE);

?>