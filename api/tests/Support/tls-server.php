<?php
// A tiny HTTPS server for SafeFetcherTest, started by TlsTestServer: `php tls-server.php <cert> <key>`.
// Listens on 127.0.0.1 (a free port, printed on the first line of stdout) and forks per
// connection (pcntl), so a response that trickles doesn't hold up the next test. HTTP/1.1, one
// request per connection. Routes:
//   /bytes/<n>          200, Content-Length, n deterministic bytes (TlsTestServer::Body($n))
//   /close/<n>          200, no Content-Length, n bytes, then the connection closes
//   /chunked/<n>        200, chunked, n bytes
//   /lie/<n>            200, Content-Length n + 10, n bytes, then the connection closes
//   /status/<code>      that status, a short body
//   /redirect/<n>       302 to /redirect/<n-1>; /redirect/0 is 200 "done"
//   /location?to=<url>  302 to that Location
//   /no-location        302 without a Location
//   /trickle            200, Content-Length 1000, one byte a second
//   /slow/<s>           sleeps s seconds, then 200 "late"
//   /gzip               200, Content-Encoding: gzip
//   /disposition        200, Content-Disposition: attachment; filename="from-header.txt"
//   /headers            200, the request's headers as the body
// Not part of the app: nothing under src/ loads it.

[$cert, $key] = [$argv[1] ?? "", $argv[2] ?? ""];
$context = stream_context_create(["ssl" => ["local_cert" => $cert, "local_pk" => $key, "verify_peer" => false]]);
$server = stream_socket_server("tcp://127.0.0.1:0", $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if($server === false) {
	fwrite(STDERR, "listen failed: ".$errstr."\n");
	exit(1);
}
$name = stream_socket_get_name($server, false);
echo substr($name, strrpos($name, ":") + 1), "\n";
fflush(STDOUT);

$fork = function_exists("pcntl_fork");
$children = [];
if($fork) {
	pcntl_async_signals(true);
	$stop = function() use (&$children) {
		foreach($children as $pid) posix_kill($pid, SIGKILL);
		exit(0);
	};
	pcntl_signal(SIGTERM, $stop);
	pcntl_signal(SIGINT, $stop);
}

while(true) {
	if($fork) {
		while(($pid = pcntl_waitpid(-1, $status, WNOHANG)) > 0) unset($children[$pid]);
	}
	$conn = @stream_socket_accept($server, 1);
	if($conn === false) continue;
	if(!$fork) {
		serve($conn);
		continue;
	}
	$pid = pcntl_fork();
	if($pid === 0) {
		fclose($server);
		serve($conn);
		exit(0);
	}
	if($pid > 0) $children[$pid] = $pid;
	fclose($conn);
}

function serve($conn): void {
	stream_set_blocking($conn, true);
	if(@stream_socket_enable_crypto($conn, true, STREAM_CRYPTO_METHOD_TLS_SERVER) !== true) {
		@fclose($conn); // the client refused the certificate, as a test may want
		return;
	}
	stream_set_timeout($conn, 5);
	$requestLine = fgets($conn);
	if($requestLine === false) return;
	$headers = "";
	while(($line = fgets($conn)) !== false && rtrim($line, "\r\n") !== "") $headers .= $line;
	$target = explode(" ", trim($requestLine))[1] ?? "/";
	$path = parse_url($target, PHP_URL_PATH) ?? "/";
	parse_str(parse_url($target, PHP_URL_QUERY) ?? "", $query);
	$parts = explode("/", trim($path, "/"));
	$n = isset($parts[1]) ? (int)$parts[1] : 0;

	switch($parts[0]) {
		case "bytes":
			respond($conn, 200, ["Content-Length: ".$n], body($n));
			break;
		case "close":
			respond($conn, 200, ["Connection: close"], body($n));
			break;
		case "chunked":
			respond($conn, 200, ["Transfer-Encoding: chunked"], "");
			foreach(str_split(body($n), 1000) as $chunk) @fwrite($conn, dechex(strlen($chunk))."\r\n".$chunk."\r\n");
			@fwrite($conn, "0\r\n\r\n");
			break;
		case "lie":
			respond($conn, 200, ["Content-Length: ".($n + 10)], body($n));
			break;
		case "status":
			respond($conn, $n, ["Content-Length: 6"], "status");
			break;
		case "redirect":
			if($n > 0) respond($conn, 302, ["Location: /redirect/".($n - 1), "Content-Length: 0"], "");
			else respond($conn, 200, ["Content-Length: 4"], "done");
			break;
		case "location":
			respond($conn, 302, ["Location: ".($query["to"] ?? ""), "Content-Length: 0"], "");
			break;
		case "no-location":
			respond($conn, 302, ["Content-Length: 0"], "");
			break;
		case "trickle":
			respond($conn, 200, ["Content-Length: 1000"], "");
			for($i = 0; $i < 60; $i++) {
				if(@fwrite($conn, "x") === false || feof($conn)) break;
				fflush($conn);
				sleep(1);
			}
			break;
		case "slow":
			sleep($n);
			respond($conn, 200, ["Content-Length: 4"], "late");
			break;
		case "gzip":
			$gz = gzencode("hello");
			respond($conn, 200, ["Content-Encoding: gzip", "Content-Length: ".strlen($gz)], $gz);
			break;
		case "disposition":
			respond($conn, 200, ['Content-Disposition: attachment; filename="from-header.txt"', "Content-Length: 2"], "hi");
			break;
		case "headers":
			respond($conn, 200, ["Content-Length: ".strlen($headers)], $headers);
			break;
		default:
			respond($conn, 404, ["Content-Length: 9"], "not found");
	}
	@fflush($conn);
	// fclose() sends TLS close_notify; shutting the TCP socket down first would skip it, and curl
	// (OpenSSL 3) takes a close without it as a truncated body.
	@fclose($conn);
}

function respond($conn, int $status, array $headers, string $body): void {
	$out = "HTTP/1.1 ".$status." X\r\n".implode("", array_map(fn($h) => $h."\r\n", $headers))."\r\n".$body;
	@fwrite($conn, $out);
}

/** Must match TlsTestServer::Body(). */
function body(int $n): string {
	$block = hash("sha256", "explorer", true);
	return substr(str_repeat($block, intdiv($n, 32) + 1), 0, $n);
}
