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

	static public function mysqlEscapeColumns($columns) {
		$result = array();
		foreach ($columns as $column) {
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$result[] = $column;
		}
		return "(".implode(", ", $result).")";
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
	
	protected function __construct($dbRow) {
		if (!$dbRow) {
			return;
		}
		ksort($dbRow);
		foreach ($dbRow as $column => $value) {
			if (is_null($value)) {
				continue;
			}
			$parts = explode('/', $column, 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			if ($type == "json") {
				$this->merge($path, json_decode($value));
			} else if ($type == "integer") {
				$this->merge($path, (int)$value);
			} else if ($type == "number") {
				$this->merge($path, (float)$value);
			} else if ($type == "string") {
				$this->merge($path, $value);
			} else if ($type == "boolean") {
				$this->merge($path, (boolean)$value);
			}
		}
	}
	
	abstract protected function mysqlConfig();
	
	protected function merge($path, $value) {
		$target =& $this;
		foreach (self::splitJsonPointer($path) as $part) {
			if (!isset($target)) {
				$target = new StdClass;
			}
			if (is_object($target)) {
				if (!isset($target->$part)) {
					$target->$part = new StdClass;
				}
				$target =& $target->$part;
			} else if (is_array($target)) {
				$target =& $target[$part];
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

	protected function set($path, $value) {
		$target =& $this;
		foreach (self::splitJsonPointer($path) as $part) {
			if (!isset($target)) {
				$target = new StdClass;
			}
			if (is_object($target)) {
				if (!isset($target->$part)) {
					$target->$part = new StdClass;
				}
				$target =& $target->$part;
			} else if (is_array($target)) {
				$target =& $target[$part];
			}
		}
		$target = $value;
	}

	protected function remove($path) {
		$target =& $this;
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
			}
		}
		if (is_object($target)) {
			unset($target->$finalPart);
		} else if (is_array($target)) {
			unset($target[$part]);
		}
	}
	
	protected function get($path) {
		$target =& $this;
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
			}
		}
		return $target;
	}
	
	public function save() {
		$config = $this->mysqlConfig();
		if (isset($config['keyColumn'])) {
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			$value = $this->get($path);
			
			if (isset($value)) {
				return $this->mysqlUpdate();
			} else {
				return $this->mysqlInsert();
			}
		}
		$result = $this->mysqlUpdate();
		if ($result['affected_rows'] == 0) {
			$result = $this->mysqlInsert();
		}
		return $result;
	}
	
	public function delete() {
		return $this->mysqlDelete();
	}
		
	private function mysqlValue($column) {
		$parts = explode('/', $column, 2);
		$type = $parts[0];
		$path = count($parts) > 1 ? '/'.$parts[1] : '';
		$value = $this->get($path);
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
	
	private function mysqlUpdate() {
		$config = $this->mysqlConfig();
		$whereParts = array();
		if (isset($config['keyColumns'])) {
			$keyColumns = $config['keyColumns'];
		} else {
			$keyColumns = array($config['keyColumn']);
		}
		foreach ($keyColumns as $column) {
			$sqlValue = $this->mysqlValue($column);
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$whereParts[] = "$column=".$sqlValue;
		}
		
		$config = $this->mysqlConfig();
		$sql = "UPDATE {$config['table']} SET
					".$this->mysqlUpdateValues($config['columns'])."
				WHERE ".implode(" AND ", $whereParts);
		$result = self::mysqlQuery($sql);
		if (!$result) {
			throw new Exception("Error saving: ".$this->mysqlErrorMessage."\n$sql");
		}
		return $result;
	}

	private function mysqlUpdateValues($columns) {
		$result = array();
		foreach ($columns as $column) {
			$sqlValue = $this->mysqlValue($column);
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$result[] = "$column=$sqlValue";
		}
		return implode(", ", $result);
	}
	
	private function mysqlInsert() {
		$config = $this->mysqlConfig();
		$sql = "INSERT INTO {$config['table']} ".self::mysqlEscapeColumns($config['columns'])." VALUES
			".$this->mysqlInsertValues($config['columns']);
		$result = self::mysqlQuery($sql);
		if ($result && isset($config['keyColumn'])) {
			$insertId = $result['insert_id'];
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			if ($type == "string") {
				$insertId = (string)$insertId;
			}
			$this->set($path, $insertId);
		}
		if (!$result) {
			throw new Exception("Error inserting: ".$this->mysqlErrorMessage."\n$sql");
		}
		return $result;
	}
	
	private function mysqlInsertValues($columns) {
		$result = array();
		foreach ($columns as $column) {
			$sqlValue = $this->mysqlValue($column);

			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$result[] = $sqlValue;
		}
		return "(".implode(", ", $result).")";
	}
	
	private function mysqlDelete() {
		$config = $this->mysqlConfig();
		$whereParts = array();
		if (isset($config['keyColumns'])) {
			$keyColumns = $config['keyColumns'];
		} else {
			$keyColumns = array($config['keyColumn']);
		}
		foreach ($keyColumns as $column) {
			$sqlValue = $this->mysqlValue($column);
			if ($column[0] != "`") {
				$column = "`".str_replace("`", "``", $column)."`";
			}
			$whereParts[] = "$column=".$sqlValue;
		}
		
		$config = $this->mysqlConfig();
		$sql = "DELETE FROM {$config['table']}
				WHERE ".implode(" AND ", $whereParts);
		$result = self::mysqlQuery($sql);
		if (!$result) {
			throw new Exception("Error deleting: ".$this->mysqlErrorMessage."\n$sql");
		}
		if (isset($config['keyColumn'])) {
			$parts = explode('/', $config['keyColumn'], 2);
			$type = $parts[0];
			$path = count($parts) > 1 ? '/'.$parts[1] : '';
			$this->remove($path);
		}
		if ($result['affected_rows'] == 0) {
			throw new Exception("Error deleting - no matches: \n$sql");
		}
		return $result;
	}
}
JsonStore::connectToDatabase(MYSQL_HOSTNAME, MYSQL_USERNAME, MYSQL_PASSWORD, MYSQL_DATABASE);

?>