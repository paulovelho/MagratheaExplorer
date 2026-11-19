<?php

namespace MagratheaExplorer\Tests\Support;

/**
 * Runs tests/Support/tls-server.php with a throwaway self-signed certificate (for `fetch.test`,
 * `other.test` and 127.0.0.1), for SafeFetcherTest. Needs the `openssl` command; Unavailable()
 * says why when it can't run, for markTestSkipped().
 */
final class TlsTestServer {

	public readonly int $port;
	/** The certificate, which is its own CA: SafeFetcher's $caInfo. */
	public readonly string $caFile;
	private $process;
	private string $dir;

	public static function Unavailable(): ?string {
		if(!extension_loaded("curl")) return "ext-curl isn't loaded";
		if(!function_exists("proc_open")) return "proc_open() is disabled";
		$which = @shell_exec("command -v openssl 2>/dev/null");
		if(!is_string($which) || trim($which) === "") return "no openssl command";
		return null;
	}

	public static function Start(): self {
		$server = new self();
		$server->dir = ScratchDir::Create("explorer_tls_");
		$key = $server->dir."/key.pem";
		$cert = $server->dir."/cert.pem";
		self::Run(["openssl", "req", "-x509", "-newkey", "ec", "-pkeyopt", "ec_paramgen_curve:prime256v1", "-nodes",
			"-keyout", $key, "-out", $cert, "-days", "2", "-subj", "/CN=fetch.test",
			"-addext", "subjectAltName=DNS:fetch.test,DNS:other.test,IP:127.0.0.1"]);
		$server->caFile = $cert;

		$process = proc_open([PHP_BINARY, __DIR__."/tls-server.php", $cert, $key],
			[0 => ["pipe", "r"], 1 => ["pipe", "w"], 2 => ["file", $server->dir."/stderr.log", "a"]], $pipes);
		if(!is_resource($process)) throw new \RuntimeException("could not start tls-server.php");
		$server->process = $process;
		$read = [$pipes[1]];
		$write = $except = null;
		if(stream_select($read, $write, $except, 10) !== 1) {
			$server->Stop();
			throw new \RuntimeException("tls-server.php didn't start: ".@file_get_contents($server->dir."/stderr.log"));
		}
		$server->port = (int)trim((string)fgets($pipes[1]));
		return $server;
	}

	/** https://<host>:<port><path>. The host is resolved by the test's resolver, never by DNS. */
	public function Url(string $path, string $host = "fetch.test"): string {
		return "https://".$host.":".$this->port.$path;
	}

	public function Stop(): void {
		if(is_resource($this->process)) {
			proc_terminate($this->process, 15);
			proc_close($this->process);
		}
		ScratchDir::Remove($this->dir);
	}

	/** What /bytes/<n> and friends send. Must match tls-server.php's body(). */
	public static function Body(int $n): string {
		$block = hash("sha256", "explorer", true);
		return substr(str_repeat($block, intdiv($n, 32) + 1), 0, $n);
	}

	private static function Run(array $command): void {
		$process = proc_open($command, [1 => ["pipe", "w"], 2 => ["pipe", "w"]], $pipes);
		$output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
		if(proc_close($process) !== 0) throw new \RuntimeException("openssl failed: ".$output);
	}

}
