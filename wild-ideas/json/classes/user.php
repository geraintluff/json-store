<?php

include_once dirname(__FILE__).'/../common.php';

class User extends JsonStore {
	static public function search($schema=NULL, $orderBy=NULL) {
		if (!$schema) {
			$schema = new StdClass;
		}
		if (!$orderBy) {
			$orderBy = array('string/username' => 'ASC');
		}
		$sql = JsonStore::queryFromSchema('User', $schema, $orderBy);
		json_debug($sql);
		$results = self::mysqlQuery($sql);
		foreach ($results as $idx => $result) {
			$results[$idx] = new User($result);
		}
		return $results;
	}
	
	static public function open($username) {
		$schema = new StdClass;
		$schema->properties->username->enum = array($username);
		$results = self::search($schema);
		return count($results) ? $results[0] : NULL;
	}
	
	static public function create($obj) {
		if (!$obj) {
			$obj = json_decode('
				{
					"username": "user'.rand().'"
				}
			');
		}
		unset($obj->id);
		return new User($obj);
	}
	
	public function get() {
		$clone = clone($this);
		unset($clone->password);
		unset($clone->id);
		$clone->editUrl = urlencode("{$this->username}");
		return $clone;
	}
	
	public function put($obj) {
		$obj->id = $this->id;
		$obj->password = $this->password;
		foreach ($obj as $key => $value) {
			$this->$key = $value;
		}
		foreach ($this as $key => $value) {
			if (!isset($obj->$key)) {
				unset($this->$key);
			}
		}
	}
	
	public function checkPassword($password) {
		$hash = hash($this->password->algorithm, $this->password->salt.$password);
		return $hash == $this->password->hash;
	}

	public function setPassword($password) {
		$this->password = new StdClass;
		$this->password->salt = openssl_random_pseudo_bytes(20);
		$this->password->algorithm = "sha256"; // yeah, I know - but bcrypt is only built-in for PHP 5.5+
		$this->password->hash = hash($this->password->algorithm, $this->password->salt.$password);
	}
}
JsonStore::addMysqlConfig('User', array(
	"table" => "users",
	"keyColumn" => "integer/id",
	"columns" => array(
		"integer/id" => "id",
		"string/username" => "username",
		"string/name" => "name",
		"string/password/salt" => "pw_salt",
		"string/password/algorithm" => "pw_algo",
		"string/password/hash" => "pw_hash"
	)
));

?>