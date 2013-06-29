<?php
	include_once 'common.php';
	include_once 'classes/idea.php';

	$method = $_SERVER['REQUEST_METHOD'];
	$jsonData = json_decode(file_get_contents('php://input'));
	if ($params = matchUriTemplate('/')) {
		if ($method == "GET") {
			$ideas = Idea::search();
			json_exit($ideas, SCHEMA_ROOT.'/idea#/definitions/array');
		} else if ($method == "POST") {
			$idea = Idea::create($jsonData);
			$idea->save();
			link_header(JSON_ROOT.'/ideas/', 'invalidates');
			json_exit($idea->id);
		}
		json_error(405, "Invalid method: $method", $method);
	} else if ($params = matchUriTemplate('/{id}')) {
		$idea = Idea::open($params->id);
		if ($method == "GET") {
			json_exit($idea, SCHEMA_ROOT.'/idea');
		} else if ($method == "PUT") {
			$idea->put($jsonData);
			link_header(JSON_ROOT.'/ideas/', 'invalidates');
			json_exit($idea, SCHEMA_ROOT.'/idea');
		} else if ($method == "DELETE") {
			$idea->delete();
			link_header(JSON_ROOT.'/ideas/', 'invalidates');
			json_exit("deleted");
		}
		json_error(405, "Invalid method: $method", $method);
	}
	json_error(404);
?>