<?php

session_start();

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
	
	static public function open($userId) {
		if (JsonStore::cached('User', $userId)) {
			return JsonStore::cached('User', $userId);
		}
		$schema = new StdClass;
		$schema->properties->id->enum = array($userId);
		$results = self::search($schema);
		if (count($results) == 1) {
			return JsonStore::setCached('User', $userId, $results[0]);
		}
		return NULL;
	}

	static public function openUsername($username) {
		$schema = new StdClass;
		$schema->properties->username->enum = array((string)$username);
		$results = self::search($schema);
		if (count($results) == 1) {
			return $results[0];
			return JsonStore::setCached('User', $user->id, $results[0]);
		}
		return NULL;
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
	
	static public function current() {
		if (!isset($_SESSION['current_user'])) {
			return NULL;
		}
		$userId = $_SESSION['current_user'];
		return self::open($userId);
	}
	
	static public function anonymous() {
		if ($cached = JsonStore::cached('User', 'anonymous')) {
			return $cached;
		}
		return JsonStore::setCached('User', 'anonymous', self::create((object)array(
			"name" => "anonymous",
			"username" => $_SERVER['REMOTE_ADDR']
		)));
	}
	
	static public function logout() {
		link_header(JSON_ROOT.'/', 'invalidates');
		link_header(JSON_ROOT.'/users/me/', 'invalidates');
		unset($_SESSION['current_user']);
	}

	public function login() {
		link_header(JSON_ROOT.'/', 'invalidates');
		link_header(JSON_ROOT.'/users/me/', 'invalidates');
		link_header(JSON_ROOT."/users/{$this->id}/", 'invalidates');
		$_SESSION['current_user'] = $this->id;
	}

	public function get() {
		$clone = clone($this);
		unset($clone->password);
		if (!isset($this->id)) {
			$clone->loginUrl = JSON_ROOT.'/users/login';
		} else if ($this == User::current()) {
			$clone->editUrl = "";
			$clone->logoutUrl = JSON_ROOT.'/users/logout';
		}
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