<?php
	include_once 'common.php';
	include_once 'classes/user.php';

	$method = $_SERVER['REQUEST_METHOD'];
	$jsonData = json_decode(file_get_contents('php://input'));
	if ($params = matchUriTemplate('/')) {
		if ($method == "GET") {
			$users = User::search();
			foreach ($users as $idx => $user) {
				$users[$idx] = $user->get();
			}
			json_exit($users, SCHEMA_ROOT.'/user#/definitions/array');
		} elseif ($method == "POST") {
			$existing = User::open($jsonData->username);
			if ($existing) {
				json_error(400, "User already exists", $jsonData->username);
			}
			$user = User::create($jsonData);
			$user->setPassword($user->password);
			$user->save();
			json_exit($user->get(), SCHEMA_ROOT.'/user');
		}
		json_error(405, "Invalid method: $method", $method);
	} else if ($params = matchUriTemplate('/{username}/password')) {
		$user = User::open($params->username);
		if ($method == "PUT" || $method == "POST") {
			if (!$user->checkPassword($jsonData->oldPassword)) {
				json_error(403, "Incorrect password");
			}
			$user->setPassword($jsonData->password);
			$user->save();
			json_exit($user->get(), SCHEMA_ROOT.'/user');
		}
		json_error(405, "Invalid method: $method", $method);
	} else if ($params = matchUriTemplate('/{username}')) {
		$user = User::open($params->username);
		if ($method == "GET") {
			json_exit($user->get(), SCHEMA_ROOT.'/user');
		} else if ($method == "PUT") {
			$user->put($jsonData);
			$user->save();
			json_exit($user->get(), SCHEMA_ROOT.'/user');
		}
		json_error(405, "Invalid method: $method", $method);
	}
	json_error(404);
?>