<?php

if (!defined('DEBUG')) {
	define('DEBUG', false);
}

if (DEBUG) {
	ini_set('display_errors', 1);
	error_reporting(E_ALL | E_STRICT);
}

function http_response_text($code) {
	$statusTexts = array(100 => 'Continue', 101 => 'Switching Protocols', 200 => 'OK', 201 => 'Created', 202 => 'Accepted', 203 => 'Non-Authoritative Information', 204 => 'No Content', 205 => 'Reset Content', 206 => 'Partial Content', 300 => 'Multiple Choices', 301 => 'Moved Permanently', 302 => 'Moved Temporarily', 303 => 'See Other', 304 => 'Not Modified', 305 => 'Use Proxy', 400 => 'Bad Request', 401 => 'Unauthorized', 402 => 'Payment Required', 403 => 'Forbidden', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable', 407 => 'Proxy Authentication Required', 408 => 'Request Time-out', 409 => 'Conflict', 410 => 'Gone', 411 => 'Length Required', 412 => 'Precondition Failed', 413 => 'Request Entity Too Large', 414 => 'Request-URI Too Large', 415 => 'Unsupported Media Type', 500 => 'Internal Server Error', 501 => 'Not Implemented', 502 => 'Bad Gateway', 503 => 'Service Unavailable', 504 => 'Gateway Time-out',  505 => 'HTTP Version not supported');
	if (isset($statusTexts[$code])) {
		return $statusTexts[$code];
	} else {
		return "Unknown Code";
	}
}

if (!function_exists('http_response_code')) {
	function http_response_code($code = NULL) {
		if ($code !== NULL) {
			$text = http_response_text($code);
			$protocol = (isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.0');
			header($protocol . ' ' . $code . ' ' . $text);
			$GLOBALS['http_response_code'] = $code;
		} else {
			$code = (isset($GLOBALS['http_response_code']) ? $GLOBALS['http_response_code'] : 200);
		}
		return $code;
	}
}

$jsonLogMessages = array();
function json_debug($message) {
	global $jsonLogMessages;
	if (strlen($message) > 200) {
		$message = substr($message, 0, 197)."...";
	}
	if (DEBUG) {
		$jsonLogMessages[] = $message;
		$message = str_replace("\n", " / ", $message);
		header("X-Debug: $message", false);
	}
}

function json_error($message, $statusCode=NULL, $data=NULL) {
	global $jsonLogMessages;
	if (is_integer($message)) {
		$tmp = $statusCode;
		$statusCode = $message;
		$message = $tmp;
	}
	$statusCode = is_null($statusCode) ? 400 : $statusCode;
	http_response_code($statusCode);
	$result = (object)array(
		"statusCode" => $statusCode,
		"statusText" => http_response_text($statusCode),
		"message" => $message,
		"data" => $data
	);
	if (DEBUG) {
		$result->debug = TRUE;
		$result->debugMessages = $jsonLogMessages;
		$backtrace = array_slice(debug_backtrace(), 1);
		$result->trace = $backtrace;
	}
	header("Content-Type: application/json");
	echo json_encode($result);
	exit(1);
}

function json_exit($data, $schemaUrl=NULL) {
	return json_exit_raw(json_encode($data), $schemaUrl);
}

function json_exit_raw($jsonText, $schemaUrl=NULL) {
	global $jsonLogMessages;
	if ($schemaUrl != NULL) {
		header("Content-Type: application/json; profile=".$schemaUrl);
	} else {
		header("Content-Type: application/json");
	}
	echo $jsonText;
	exit(0);
}

function json_handle_error($errorNumber, $errorString, $errorFile, $errorLine) {
	if ($errorNumber&E_NOTICE) {
		if (DEBUG) {
			json_debug("Notice $errorNumber: $errorString in $errorFile:$errorLine");
		}
	} elseif ($errorNumber&E_STRICT) {
		if (DEBUG) {
			json_debug("Strict warning $errorNumber: $errorString in $errorFile:$errorLine");
		}
	} elseif ($errorNumber&E_WARNING) {
		if (DEBUG) {
			json_debug("Warning $errorNumber: $errorString in $errorFile:$errorLine");
		}
	} else {
		json_error("Error $errorNumber: $errorString", 500, array("file" => $errorFile, "line" => $errorLine));
	}
}

function link_header($url, $rel, $params=NULL) {
	if (is_array($rel)) {
		$params = $rel;
	} else if (!$params) {
		$params = array("rel" => $rel);
	} else {
		$params['rel'] = $rel;
	}
	$parts = array("Link: <$url>");
	foreach ($params as $key => $value) {
		if (strpos($value, ' ') !== FALSE) {
			$value = json_encode($value);
		}
		$parts[] = "$key=$value";
	}
	header(implode("; ", $parts), FALSE);
}

set_error_handler('json_handle_error', E_ALL|E_STRICT);

?>