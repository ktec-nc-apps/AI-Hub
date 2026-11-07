<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Service;

use Psr\Log\LoggerInterface;

/**
 * Signing the Claude command line tool in again from the admin settings (owner, 2026-11-07:
 * "Failed to authenticate: OAuth session expired" left people with nothing to do).
 *
 * `claude auth login` prints a link and then waits for the code the sign-in page shows. It is
 * started in the background with its input on a named pipe, so the admin can open the link,
 * sign in, and paste the code into the settings page; the code is then written to the pipe.
 * Everything lives in a folder of its own under the tool's home, readable by the web server only.
 */
class CliLoginService {
	private const DIR = '.aihub-login';
	private const LIFETIME = 900;   // seconds the waiting login is kept

	public function __construct(
		private ConfigService $config,
		private LoggerInterface $logger,
	) {
	}

	/** Whether the Claude command line tool is set up here at all. */
	public function available(): bool {
		$bin = $this->config->getCliPath('claude');
		return $bin !== '' && is_executable($bin) && $this->config->getCliHome() !== '';
	}

	/** @return array{loggedIn: bool, method: string, email: string, waiting: bool} */
	public function status(): array {
		$out = ['loggedIn' => false, 'method' => '', 'email' => '', 'waiting' => $this->waiting()];
		if (!$this->available()) {
			return $out;
		}
		$run = $this->run([$this->config->getCliPath('claude'), 'auth', 'status'], 20);
		$j = json_decode($run['stdout'], true);
		if (is_array($j)) {
			$out['loggedIn'] = !empty($j['loggedIn']);
			$out['method'] = (string)($j['authMethod'] ?? '');
			$out['email'] = (string)($j['email'] ?? $j['account']['email'] ?? $j['emailAddress'] ?? '');
		}
		return $out;
	}

	/**
	 * Start a sign-in and return the link to open. A sign-in left waiting is ended first.
	 * @return array{ok: bool, url: string, detail: string}
	 */
	public function start(): array {
		if (!$this->available()) {
			return ['ok' => false, 'url' => '', 'detail' => 'not available'];
		}
		$this->cancel();
		$dir = $this->dir();
		if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
			return ['ok' => false, 'url' => '', 'detail' => 'Could not make ' . $dir];
		}
		if (!function_exists('posix_mkfifo') || !posix_mkfifo($dir . '/in', 0600)) {
			return ['ok' => false, 'url' => '', 'detail' => 'Could not make the input pipe'];
		}
		// Opened read-write ("0<>"), so the tool starts at once and does not see the end of its
		// input while it waits for the code; it ends by itself after LIFETIME seconds.
		$script = 'echo $$ > ' . escapeshellarg($dir . '/pid') . '; exec timeout ' . self::LIFETIME . ' '
			. escapeshellarg($this->config->getCliPath('claude')) . ' auth login --claudeai 0<>' . escapeshellarg($dir . '/in')
			. ' >' . escapeshellarg($dir . '/out') . ' 2>&1';
		$launch = ['/bin/sh', '-c', '(' . (is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : '') . '/bin/sh -c ' . escapeshellarg($script) . ' &) </dev/null >/dev/null 2>&1'];
		$p = @proc_open($launch, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $dir, $this->env());
		if (!is_resource($p)) {
			return ['ok' => false, 'url' => '', 'detail' => 'Could not start the command line tool'];
		}
		proc_close($p);
		$deadline = microtime(true) + 20;
		while (microtime(true) < $deadline) {
			$text = (string)@file_get_contents($dir . '/out');
			if (preg_match('~https://\S+oauth/authorize\S+~', $text, $m)) {
				return ['ok' => true, 'url' => $m[0], 'detail' => ''];
			}
			// the pid file is written by the launched shell a moment after it starts
			if (is_file($dir . '/pid') && !$this->alive()) {
				break;
			}
			usleep(300000);
		}
		$text = trim((string)@file_get_contents($dir . '/out'));
		$this->cancel();
		return ['ok' => false, 'url' => '', 'detail' => mb_substr($text, 0, 300)];
	}

	/**
	 * Hand the waiting sign-in the code the sign-in page showed.
	 * @return array{ok: bool, detail: string, status: array}
	 */
	public function finish(string $code): array {
		$code = trim($code);
		if ($code === '' || strlen($code) > 1000 || !preg_match('/^[A-Za-z0-9#_.~\-]+$/', $code)) {
			return ['ok' => false, 'detail' => 'bad code', 'status' => $this->status()];
		}
		$dir = $this->dir();
		if (!$this->alive()) {
			$this->cancel();
			return ['ok' => false, 'detail' => 'expired', 'status' => $this->status()];
		}
		$before = strlen((string)@file_get_contents($dir . '/out'));
		$fifo = @fopen($dir . '/in', 'w');
		if ($fifo === false) {
			return ['ok' => false, 'detail' => 'Could not reach the waiting sign-in', 'status' => $this->status()];
		}
		fwrite($fifo, $code . "\n");
		fclose($fifo);
		$deadline = microtime(true) + 40;
		while (microtime(true) < $deadline && $this->alive()) {
			usleep(500000);
		}
		$said = trim(substr((string)@file_get_contents($dir . '/out'), $before));
		$this->cancel();
		$status = $this->status();
		if (!$status['loggedIn']) {
			$this->logger->warning('AI-Hub: signing the Claude command line tool in again failed: ' . mb_substr($said, 0, 300));
		}
		if (!$status['loggedIn'] && preg_match('/invalid code|full code/i', $said)) {
			return ['ok' => false, 'detail' => 'bad code', 'status' => $status];
		}
		return ['ok' => $status['loggedIn'], 'detail' => mb_substr(preg_replace('~https://\S+~', '', $said) ?? '', 0, 300), 'status' => $status];
	}

	/** End a waiting sign-in and remove its folder. */
	public function cancel(): void {
		$dir = $this->dir();
		$pid = (int)@file_get_contents($dir . '/pid');
		if ($pid > 1 && function_exists('posix_kill')) {
			@posix_kill(-$pid, 15);
			@posix_kill($pid, 15);
		}
		foreach (['in', 'out', 'pid'] as $f) {
			@unlink($dir . '/' . $f);
		}
		@rmdir($dir);
	}

	private function waiting(): bool {
		return is_dir($this->dir()) && $this->alive();
	}

	private function alive(): bool {
		$pid = (int)@file_get_contents($this->dir() . '/pid');
		return $pid > 1 && function_exists('posix_kill') && @posix_kill($pid, 0);
	}

	private function dir(): string {
		return rtrim($this->config->getCliHome(), '/') . '/' . self::DIR;
	}

	private function env(): array {
		return [
			'PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin',
			'HOME' => $this->config->getCliHome(),
			'BROWSER' => '/bin/false',   // no browser on a server: the link is shown in the settings instead
		];
	}

	/** @return array{code: int, stdout: string} */
	private function run(array $argv, int $timeout): array {
		$p = @proc_open($argv, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, sys_get_temp_dir(), $this->env());
		if (!is_resource($p)) {
			return ['code' => -1, 'stdout' => ''];
		}
		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);
		$out = '';
		$deadline = time() + $timeout;
		while (true) {
			$out .= (string)stream_get_contents($pipes[1]);
			stream_get_contents($pipes[2]);
			$st = proc_get_status($p);
			if (!$st['running'] || time() > $deadline) {
				break;
			}
			usleep(100000);
		}
		$out .= (string)stream_get_contents($pipes[1]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		if (proc_get_status($p)['running']) {
			proc_terminate($p);
		}
		$code = proc_close($p);
		return ['code' => $code, 'stdout' => $out];
	}
}
