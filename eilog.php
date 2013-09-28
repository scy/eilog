<?php

define('EILOG_DIR_DATA', __DIR__ . '/data');

function build_data($data, $defaults = array()) {
	// If $data is not an array, create a simple error message of whatever it is.
	if (!is_array($data)) {
		$data = array('message' => $data);
	}
	// Set defaults.
	foreach ($defaults as $k => $v) {
		if (!isset($data[$k])) {
			$data[$k] = $v;
		}
	}
	// Return data.
	return $data;
}

function enforce_auth() {
	$auth_ok = false;
	$user = get_server_var('PHP_AUTH_USER', false);
	if ($user !== false) {
		// Check whether the user exists.
		$credentials = read_credentials();
		if (isset($credentials[$user]) && $credentials[$user]['password'] === get_server_var('PHP_AUTH_PW')) {
			$auth_ok = true;
		} else {
			// Delay when the password is wrong.
			sleep(2);
		}
	}
	if ($auth_ok) {
		// FIXME: Security checks on the user name.
		define('EILOG_USER', $user);
		return $user;
	}
	// Response to send when user is not authenticated.
	response(error(array(
		'_statuscode' => '401 Unauthorized',
		'_headers' => 'WWW-Authenticate: Basic realm="eilog"',
		'code' => 'UNAUTHORIZED',
		'message' => 'You need to authorize with HTTP Basic credentials if you want to communicate with eilog.',
	)));
	exit;
}

function error($data) {
	return build_data($data, array(
		'_statuscode' => '400 Invalid Request',
		'code' => 'UNKNOWN',
		'success' => false,
	));
}

function fail($data) {
	$data = array_merge(array(
		'_statuscode' => '500 Internal Server Error',
	), $data);
	response(error($data));
	exit;
}

function get_data($method, $query) {
	// TODO: Implement for GET and POST.
	switch ($method) {
		case 'GET':
			$str = $query;
			break;
		default:
			$str = file_get_contents('php://input');
	}
	$parsed = array();
	parse_str($str, $parsed);
	return $parsed;
}

function get_request() {
	$uri = get_server_var('REQUEST_URI');
	list($path, $query) = explode('?', $uri, 2);
	$method = strtoupper(get_server_var('REQUEST_METHOD'));
	return array(
		'method' => $method,
		'data' => get_data($method, $query),
		'path' => $path,
		'query' => $query,
		'query_data' => get_data('GET', $query),
	);
}

function get_server_var($var, $default = null) {
	if (!isset($_SERVER[$var])) {
		if ($default !== null) {
			return $default;
		}
		fail(array(
			'code' => 'MISSING_SERVER_VAR',
			'message' => "The required \$_SERVER variable '$var' is not set.",
		));
	}
	return $_SERVER[$var];
}

// From <http://stackoverflow.com/a/4254008/417040>.
function is_assoc($array) {
	return is_array($array) ? (bool)count(array_filter(array_keys($array), 'is_string')) : false;
}

function match_routes($req) {
	// TODO: Yeah, I know. It's a global variable. Quarter me.
	global $EILOG_ROUTES;
	$res = array(
		'handler' => null,
		'other_methods' => array(),
		'request' => $req,
	);
	foreach ($EILOG_ROUTES as $route => $handler) {
		list($route_method, $route_path) = explode(':', $route);
		if ($route_path == $req['path']) {
			if ($route_method != $req['method']) {
				$res['other_methods'][$route_method] = true;
				continue;
			}
			$res['handler'] = $handler;
		}
	}
	$res['other_methods'] = count($res['other_methods'])
	                      ? array_keys($res['other_methods'])
	                      : false;
	return $res;
}

function read_credentials() {
	// TODO: Add caching.
	// TODO: Make this a real JSON database or something.
	$credentials = include EILOG_DIR_DATA . '/users/credentials.php';
	// If there are no credentials defined, return empty array.
	return is_array($credentials)
	     ? $credentials
	     : array();
}

function require_params($req, $params, $take = 'data') {
	if (!is_assoc($req)) {
		fail(array(
			'code' => 'NONSENSE_REQ_ARRAY',
			'message' => 'The req is not an associative array.',
		));
	}
	// Since we receive a complete request, take only the data part.
	$data = isset($req[$take]) ? $req[$take] : array();
	$notfound = array_filter($params, function ($param) use ($data) {
		return !isset($data[$param]);
	});
	$count = count($notfound);
	if ($count) {
		fail(array(
			'_statuscode' => '400 Bad Request',
			'code' => 'MISSING_PARAM',
			'message' => sprintf(
				'Your request lacks %s required parameter%s: %s',
				$count == 1 ? 'this' : "these $count",
				$count == 1 ? '' : 's',
				implode(', ', $notfound)
			),
		));
	}
	return $data;
}

function response($data) {
	// If a HTTP status code has been specified, send it.
	if (isset($data['_statuscode']) && is_string($data['_statuscode'])) {
		// TODO: Use HTTP version the client requested.
		header(sprintf(
			'HTTP/1.0 %s',
			$data['_statuscode']
		));
	}
	// If there are additional headers to send, send them.
	if (isset($data['_headers'])) {
		if (!is_array($data['_headers'])) {
			$data['_headers'] = explode("\n", $data['_headers']);
		}
		foreach ($data['_headers'] as $header) {
			header($header);
		}
	}
	// Send content type.
	// TODO: Check what client requested and use that.
	header('Content-Type: application/json; encoding=UTF-8');
	// Remove internal keys from $data.
	$send = array();
	foreach ($data as $k => $v) {
		if (substr($k, 0, 1) !== '_') {
			$send[$k] = $v;
		}
	}
	// Send data.
	echo json_encode($send) . "\n";
	exit;
}

function run_handler($match) {
	$handler = $match['handler'];
	if ($handler === null) {
		if ($match['other_methods']) {
			fail(array(
				'_statuscode' => '405 Method Not Allowed',
				'code' => 'BAD_METHOD',
				'message' => sprintf(
					'This resource does not support that method. Is is available via: %s',
					implode(', ', $match['other_methods'])
				),
			));
		}
		fail(array(
			'_statuscode' => '404 Not Found',
			'code' => 'NO_HANDLER',
			'message' => 'There is no functionality at this place.',
		));
	}
	if (!function_exists($handler)) {
		fail(array(
			'_statuscode' => '501 Not Implemented',
			'code' => 'HANDLER_MISSING',
			'message' => 'There should be functionality at this place, but it is not (yet?) implemented.',
		));
	}
	$result = call_user_func_array(
		$handler, array(
			$match['request'],
		)
	);
	if (is_string($result)) {
		$result = success($result);
	}
	if (!is_assoc($result)) {
		fail(array(
			'code' => 'HANDLER_RETURNED_NONSENSE',
			'message' => 'The code that should handle this request returned something strange.',
		));
	}
	return $result;
}

function success($data) {
	return build_data($data, array(
		'_statuscode' => '200 OK',
		'success' => true,
	));
}

// The main function that gets called from the endpoint.
function eilog() {
	$user = enforce_auth();
	$req = get_request();
	$match = match_routes($req);
	$response = run_handler($match);
	response($response);
}

function do_put_entry($req) {
	$data = require_params($req, array('text'));
	$date = microtime(true);
	$date = DateTime::createFromFormat(
		'U.u',
		(strpos($date, '.') === false) ? "$date.0" : $date
	);
	$dir = 'entries/'
	     . EILOG_USER
	     . $date->format('/Y/m/d');
	$fulldir = EILOG_DIR_DATA . "/$dir";
	$file = $date->format('H-i-s.u') . '.json';
	$contents = array(
		'text' => $data['text'],
		'epoch' => $date->format('U.u'),
		'date' => $date->format('c'),
	);
	// TODO: Error checking.
	mkdir($fulldir, 0750, true);
	file_put_contents("$fulldir/$file", json_encode($contents));
	return success(array(
		'file' => "$dir/$file",
		'contents' => $contents,
	));
}

$EILOG_ROUTES = array(
	'POST:/entry' => 'do_put_entry',
	'PUT:/entry' => 'do_put_entry',
);
