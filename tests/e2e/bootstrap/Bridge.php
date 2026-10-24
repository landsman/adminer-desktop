<?php
declare(strict_types=1);

namespace Desktop\Tests;

use Desktop\Mcp\Handshake;
use RuntimeException;

/**
 * The MCP bridge the scenarios talk to, spawned the way an agent spawns it.
 *
 * Two implementations share one suite: PHP's — app/mcp.php, the baseline — and, when the run
 * sets ADMINER_DESKTOP_MCP_BRIDGE to the binary, the launcher's Go bridge. Both are a child
 * process reading stdin and writing stdout, so a scenario proves the thing that ships rather
 * than a class by itself.
 *
 * The data dir arrives in ADMINER_DESKTOP_DATA, as the launcher passes it; the one scenario
 * about finding it without that variable overrides HOME instead.
 */
final class Bridge
{
	/** @var resource|null the live bridge, started by the first exchange() */
	private $process = null;

	/** @var array<int,resource> */
	private array $pipes = [];

	private string $dataDir;

	/** @param array<string,string|null> $env overrides for the child; null removes a variable */
	function __construct(private readonly string $root, Handshake $handshake, private readonly array $env = [])
	{
		$this->dataDir = dirname((string) $handshake->path());
	}

	/** One message in, one message out — or null when there is nothing to say. */
	function exchange(string $line): ?string
	{
		$this->start();
		if (!is_resource($this->pipes[0] ?? null) || !is_resource($this->pipes[1] ?? null)) {
			throw new RuntimeException('the bridge has no pipes');
		}
		fwrite($this->pipes[0], $line . "\n");
		fflush($this->pipes[0]);
		return $this->read($this->pipes[1]);
	}

	/** Run the bridge over a stream of messages, returning everything it wrote. */
	function pump(string $input): string
	{
		$in = tmpfile();
		if ($in === false) {
			throw new RuntimeException('could not make a temporary stream');
		}
		fwrite($in, $input);
		rewind($in);
		$pipes = [];
		$process = proc_open($this->command(), [0 => $in, 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, null, $this->environment());
		if (!is_resource($process)) {
			throw new RuntimeException('could not start the bridge');
		}
		$out = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($process);
		fclose($in);
		return $out;
	}

	/** The same message sent twice over a socket stdin, as Claude Code hands its servers one, with
	 * a quiet spell between that outlasts the read timeout. PHP's is default_socket_timeout, cut
	 * to 1 s for this child alone so the spell can outlast it quickly; php-cli takes no -d, so it
	 * goes in through PHP_INI_SCAN_DIR. The Go bridge reads no ini and has no timeout to cut.
	 */
	function pumpQuiet(string $line): string
	{
		$ini = sys_get_temp_dir() . '/adminer-desktop-quiet-' . getmypid();
		@mkdir($ini, 0700, true);
		file_put_contents("$ini/quiet.ini", "default_socket_timeout=1\n");
		$env = $this->environment();
		$env['PHP_INI_SCAN_DIR'] = $ini;
		$pipes = [];
		$process = proc_open($this->command(), [0 => ['socket'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $pipes, null, $env);
		if (!is_resource($process)) {
			throw new RuntimeException('could not start the bridge');
		}
		fwrite($pipes[0], $line . "\n");
		fflush($pipes[0]);
		usleep(2_000_000);
		fwrite($pipes[0], $line . "\n");
		fflush($pipes[0]);
		fclose($pipes[0]);
		$out = (string) stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		proc_close($process);
		@unlink("$ini/quiet.ini");
		@rmdir($ini);
		return $out;
	}

	function close(): void
	{
		if ($this->process === null) {
			return;
		}
		foreach ($this->pipes as $pipe) {
			if (is_resource($pipe)) {
				fclose($pipe);
			}
		}
		proc_terminate($this->process);
		proc_close($this->process);
		$this->process = null;
		$this->pipes = [];
	}

	private function start(): void
	{
		if ($this->process !== null) {
			return;
		}
		$this->pipes = [];
		$process = proc_open($this->command(), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'a']], $this->pipes, null, $this->environment());
		if (!is_resource($process)) {
			throw new RuntimeException('could not start the bridge');
		}
		$this->process = $process;
	}

	/** @return list<string> */
	private function command(): array
	{
		$binary = (string) (getenv('ADMINER_DESKTOP_MCP_BRIDGE') ?: '');
		if ($binary !== '') {
			return [$binary, '-mcp'];
		}
		$app = (string) (getenv('ADMINER_DESKTOP_E2E_APP') ?: $this->root . '/app');
		return [$this->root . '/bin/frankenphp', 'php-cli', $app . '/mcp.php'];
	}

	/** @return array<string,string> */
	private function environment(): array
	{
		$env = getenv();
		$env = is_array($env) ? $env : [];
		$env['ADMINER_DESKTOP_DATA'] = $this->dataDir;
		foreach ($this->env as $name => $value) {
			if ($value === null) {
				unset($env[$name]);
			} else {
				$env[$name] = $value;
			}
		}
		return $env;
	}

	/** One answer, given as long as the bridge's own call timeout: every exchange may start a
	 * php-cli and go through the gate into adminer, which a loaded runner takes its time over.
	 * Silence is not read here — that is pump()'s, which knows when the bridge has finished.
	 * @param resource $stream
	 */
	private function read($stream): ?string
	{
		$read = [$stream];
		$write = null;
		$except = null;
		if (stream_select($read, $write, $except, 60) !== 1) {
			throw new RuntimeException('the bridge gave no answer within 60 s');
		}
		$line = fgets($stream);
		return $line === false ? null : rtrim($line, "\r\n");
	}
}
