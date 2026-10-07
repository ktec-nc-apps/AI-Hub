<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Engine;

use OCA\AIHub\Service\ConfigService;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/**
 * Drives a Claude or Gemini command line tool installed on the server, for
 * admins who pay for a subscription instead of API tokens.
 *
 * Off by default and never reachable by end users directly: the prompt is passed
 * as a single argv element (no shell is involved) and the tools of the CLI are
 * switched off, so a chat message cannot turn into a command on the server.
 */
class CliEngine implements IEngine {

	/** Only used when the installed tool cannot be read (see scanClaudeModels). */
	private const CLAUDE_MODELS = [
		'claude-fable-5-1',
		'claude-opus-5-5',
		'claude-sonnet-5-5',
		'claude-haiku-4-5',
	];

	/** Families in the order they are listed. */
	private const CLAUDE_FAMILIES = ['fable', 'opus', 'sonnet', 'haiku'];

	/** Short names the tool resolves to the newest model of a family itself. */
	public const CLAUDE_ALIASES = ['fable', 'opus', 'sonnet', 'haiku'];

	private const GEMINI_MODELS = [
		'gemini-2.5-pro',
		'gemini-2.5-flash',
		'gemini-2.0-flash',
	];

	/** An app's own choice of model, when it has one. */
	private ?string $model = null;

	public function __construct(
		private ConfigService $config,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
		private string $provider,
	) {
	}

	public function useModel(string $model): void {
		$this->model = $model !== '' ? $model : null;
	}

	public function getName(): string {
		return $this->provider . '-cli';
	}

	/** $elevated is part of the engine interface and changes nothing here: no run gets more tools than the settings allow. */
	public function run(array $history, string $message, string $systemPrompt, bool $elevated = false, array $options = []): TurnResult {
		$binary = $this->config->getCliPath($this->provider);
		if ($binary === '') {
			return TurnResult::error('No command line tool is configured.');
		}

		// Images that go with this question: those of the question itself and, in a tool
		// round, those of the question that is now in the history. Only the Claude tool
		// takes them (as image blocks of a stream-json message); Gemini's tool reads an
		// image only through a file tool, and every tool is switched off here.
		$images = [];
		foreach ($history as $turn) {
			foreach ($turn['images'] ?? [] as $image) {
				$images[] = $image;
			}
		}
		foreach ($options['images'] ?? [] as $image) {
			$images[] = $image;
		}
		if ($images !== [] && $this->provider !== 'claude') {
			return TurnResult::error('no-images');
		}

		// Every question carries the conversation so far: the hub keeps the transcript,
		// and the tool keeps nothing of its own (see --no-session-persistence below).
		$prompt = $this->buildPrompt($history, $message);
		$model = $this->model ?? $this->config->getModel($this->provider);
		// The tools the command line may use. For a scenario asked by an app, exactly
		// what the hub asked for -- web search or nothing -- whatever the settings say
		// (only the search: fetching a page would run on this server and could reach
		// the network it stands in). For Nextcloud's own Task Processing, which passes
		// no options, the administrator's list for users. Nothing ever runs with more,
		// whoever is asking: a chat message cannot become a command on this server.
		$tools = array_key_exists('search', $options)
			? (($options['search'] && $this->provider === 'claude') ? 'WebSearch' : '')
			: $this->config->getUserTools();

		if ($this->provider === 'gemini') {
			// The prompt goes in on stdin, which the tool reads as its prompt when it
			// is not a terminal: as an argument it ran into the kernel's limit once a
			// document's context came along. Nothing was switched off before: Gemini's
			// own rules let read_file, glob and grep_search run unasked, so an ordinary
			// user could have it read the credentials in its home (review T2). An admin
			// policy that denies every tool outranks those rules.
			$argv = [$binary, '-m', $model, '--admin-policy', __DIR__ . '/policy/no-tools.toml'];
			$stdin = $systemPrompt . "\n\n" . $prompt;
		} else {
			$stdin = $prompt;
			// The prompt goes in on stdin, not as an argument: the whole conversation in
			// one argument ran past the kernel's 128 KiB limit after ten exchanges or so,
			// and every user of the machine could read it in the process list (review T6).
			// With images, stdin is one stream-json user message instead -- the image
			// blocks and then the prompt -- which the tool takes only with stream-json
			// output; the answer is then read from its "result" event (see streamResult).
			// Nothing of the run is kept on disk: the tool would otherwise write every
			// question and answer into a session file under its home (.claude/projects),
			// one more for each question, and nothing ever reads them back -- the
			// conversation so far is in the prompt (2026-10-06).
			$format = ['--output-format', 'text', '--no-session-persistence'];
			if ($images !== []) {
				$content = [];
				foreach ($images as $image) {
					$content[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['type'], 'data' => $image['data']]];
				}
				if (trim($prompt) !== '') {
					$content[] = ['type' => 'text', 'text' => $prompt];
				}
				$stdin = json_encode(['type' => 'user', 'message' => ['role' => 'user', 'content' => $content]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE) . "\n";
				// Not kept on disk either (the images too would go into the session file).
				$format = ['--input-format', 'stream-json', '--output-format', 'stream-json', '--verbose', '--no-session-persistence'];
			}
			$argv = [
				$binary,
				'-p',
				'--model', $model,
				...$format,
				// An empty list really does disable every tool: the model then has
				// no way to touch the server, whatever the message asks for.
				'--tools', $tools,
				'--append-system-prompt', $systemPrompt,
			];
			if (array_key_exists('search', $options)) {
				// No MCP servers either. The account the tool is signed in with can bring
				// connectors of its own -- Gmail, Calendar, Drive and Docs were offered to
				// the model (2026-10-03) -- and another app's question is to reach nothing
				// but the web search.
				$argv[] = '--strict-mcp-config';
			}
		}

		$run = $this->exec($argv, $this->config->getRequestTimeout(), $stdin);
		$combined = $run['stdout'] . "\n" . $run['stderr'];

		if ($run['timedOut']) {
			$result = TurnResult::error('The command line tool did not answer in time.');
		} elseif ($this->looksLikeAuthFailure($combined) && $run['code'] !== 0) {
			$result = TurnResult::authError(trim(mb_substr($combined, 0, 300)));
		} elseif ($images !== [] && ($event = $this->streamResult($run['stdout'])) !== null) {
			// The stream-json run: its "result" event holds the answer, or the error.
			$text = trim((string)($event['result'] ?? ''));
			if ($text !== '' && preg_match('//u', $text) !== 1) {
				$text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
			}
			if (!empty($event['is_error']) || ($event['subtype'] ?? 'success') !== 'success') {
				$detail = $text !== '' ? $text : trim($run['stderr']);
				$result = $this->looksLikeAuthFailure($detail)
					? TurnResult::authError(mb_substr($detail, 0, 300))
					: TurnResult::error($detail !== '' ? mb_substr($detail, 0, 500) : 'The command line tool returned nothing.');
			} else {
				$result = $text !== '' ? TurnResult::ok($text) : TurnResult::error('The command line tool returned nothing.');
			}
		} elseif ($images !== []) {
			$stderr = trim($run['stderr']);
			$result = TurnResult::error($stderr !== '' ? mb_substr($stderr, 0, 500) : 'The command line tool returned nothing.');
		} else {
			// A command line tool can emit a stray non-UTF-8 byte; drop it here so the
			// answer both stores cleanly and survives json_encode on its way to the app.
			$output = trim($run['stdout']);
			if ($output !== '' && preg_match('//u', $output) !== 1) {
				$output = mb_convert_encoding($output, 'UTF-8', 'UTF-8');
			}
			if ($output !== '') {
				$result = TurnResult::ok($output);
			} else {
				$stderr = trim($run['stderr']);
				$result = TurnResult::error($stderr !== '' ? mb_substr($stderr, 0, 500) : 'The command line tool returned nothing.');
			}
		}

		return $result;
	}

	/**
	 * The last "result" event of a stream-json run: the answer ('result') and whether
	 * the run failed ('is_error', 'subtype'). Null when the run printed none.
	 *
	 * @return array<string, mixed>|null
	 */
	private function streamResult(string $stdout): ?array {
		$found = null;
		foreach (preg_split('/\r?\n/', $stdout) ?: [] as $line) {
			$line = trim($line);
			if ($line === '' || $line[0] !== '{') {
				continue;
			}
			$event = json_decode($line, true);
			if (is_array($event) && ($event['type'] ?? '') === 'result') {
				$found = $event;
			}
		}
		return $found;
	}

	public function listModels(): array {
		if ($this->provider === 'gemini') {
			return self::GEMINI_MODELS;
		}
		$binary = $this->config->getCliPath($this->provider);
		$real = $binary === '' ? false : realpath($binary);
		if ($real === false) {
			$found = trim((string)shell_exec('command -v ' . escapeshellarg($binary) . ' 2>/dev/null'));
			$real = $found === '' ? false : realpath($found);
		}
		if ($real === false || !is_file($real)) {
			return self::CLAUDE_MODELS;
		}
		// The tool knows its own models; reading them from the installed build keeps
		// the list right after every update, where a list written here goes stale.
		$stamp = $real . ':' . filesize($real) . ':' . filemtime($real);
		$cached = json_decode($this->config->getString('cli_models_cache'), true);
		if (is_array($cached) && ($cached['stamp'] ?? '') === $stamp && is_array($cached['models'] ?? null) && $cached['models'] !== []) {
			return $cached['models'];
		}
		$models = $this->scanClaudeModels($real);
		if ($models === []) {
			return self::CLAUDE_MODELS;
		}
		$this->config->setString('cli_models_cache', (string)json_encode(['stamp' => $stamp, 'models' => $models]));
		return $models;
	}

	/**
	 * The current models the installed Claude Code knows: for each family, every
	 * version of its newest generation, newest first (older generations and dated
	 * snapshots are left out; they can still be chosen by their full name).
	 *
	 * @return list<string>
	 */
	private function scanClaudeModels(string $file): array {
		$fh = @fopen($file, 'rb');
		if ($fh === false) {
			return [];
		}
		$seen = [];
		$tail = '';
		while (!feof($fh)) {
			$chunk = $tail . (string)fread($fh, 8 << 20);
			if (preg_match_all('/"claude-(fable|opus|sonnet|haiku)-(\d{1,2})(?:-(\d{1,2}))?"/', $chunk, $m, PREG_SET_ORDER) > 0) {
				foreach ($m as $hit) {
					$seen[$hit[1]][$hit[0]] = [(int)$hit[2], isset($hit[3]) && $hit[3] !== '' ? (int)$hit[3] : 0];
				}
			}
			$tail = substr($chunk, -64);
		}
		fclose($fh);
		$models = [];
		foreach (self::CLAUDE_FAMILIES as $family) {
			if (!isset($seen[$family])) {
				continue;
			}
			$versions = $seen[$family];
			uasort($versions, fn ($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
			$newest = reset($versions)[0];
			foreach ($versions as $quoted => [$major]) {
				if ($major === $newest) {
					$models[] = trim($quoted, '"');
				}
			}
		}
		return $models;
	}

	/**
	 * Ask the model for one word, to learn whether the tool accepts the name and
	 * which model it really is (a short name like "sonnet" resolves to a full one).
	 *
	 * @return array{ok: bool, resolved: string, detail: string}
	 */
	public function probeModel(string $model): array {
		$binary = $this->config->getCliPath($this->provider);
		if ($binary === '' || $this->provider !== 'claude') {
			return ['ok' => true, 'resolved' => $model, 'detail' => ''];
		}
		$run = $this->exec([$binary, '-p', '--model', $model, '--output-format', 'json', '--no-session-persistence', '--tools', ''], 120, 'Reply with OK');
		$out = json_decode(trim($run['stdout']), true);
		if (!is_array($out)) {
			$detail = trim($run['stdout'] . ' ' . $run['stderr']);
			return ['ok' => false, 'resolved' => '', 'detail' => $run['timedOut'] ? 'timeout' : mb_substr($detail, 0, 300)];
		}
		$used = array_keys(is_array($out['modelUsage'] ?? null) ? $out['modelUsage'] : []);
		$ok = empty($out['is_error']) && $used !== [];
		$detail = $ok ? '' : mb_substr(trim($run['stderr'] . ' ' . (string)($out['result'] ?? '')), 0, 300);
		return ['ok' => $ok, 'resolved' => (string)($used[0] ?? ''), 'detail' => $detail];
	}

	/**
	 * Check that the configured binary exists and runs. 'reason' tells the admin page
	 * which sentence to show, in the admin's language ('no_path', 'exit_code' with 'code');
	 * 'detail' is otherwise the tool's own --version output.
	 */
	public function checkBinary(): array {
		$binary = $this->config->getCliPath($this->provider);
		if ($binary === '') {
			return ['ok' => false, 'detail' => '', 'reason' => 'no_path'];
		}
		$run = $this->exec([$binary, '--version'], 30);
		$output = trim($run['stdout'] . ' ' . $run['stderr']);
		return $output === ''
			? ['ok' => $run['code'] === 0, 'detail' => '', 'reason' => 'exit_code', 'code' => $run['code']]
			: ['ok' => $run['code'] === 0, 'detail' => mb_substr($output, 0, 200)];
	}

	/**
	 * Update the command line tool with its own update command (Claude Code only).
	 *
	 * @return array{ok: bool, before: string, after: string, detail: string}
	 */
	public function update(): array {
		$binary = $this->config->getCliPath($this->provider);
		if ($binary === '' || $this->provider !== 'claude') {
			return ['ok' => false, 'before' => '', 'after' => '', 'detail' => 'unsupported'];
		}
		$version = function () use ($binary): string {
			$run = $this->exec([$binary, '--version'], 30);
			return preg_match('/\d+\.\d+\.\d+/', $run['stdout'], $m) === 1 ? $m[0] : '';
		};
		$before = $version();
		$run = $this->exec([$binary, 'update'], 600);
		$after = $version();
		return [
			'ok' => $run['code'] === 0 && !$run['timedOut'],
			'before' => $before,
			'after' => $after,
			'detail' => mb_substr(trim($run['stdout'] . "\n" . $run['stderr']), -500),
		];
	}

	/** @param list<array{role: string, text: string}> $history */
	private function buildPrompt(array $history, string $message): string {
		if ($history === []) {
			return $message;
		}
		$lines = ['Conversation so far:'];
		foreach ($history as $turn) {
			$who = $turn['role'] === 'assistant' ? 'Assistant' : 'User';
			$lines[] = $who . ': ' . $turn['text'];
		}
		$lines[] = '';
		$lines[] = 'User: ' . $message;
		return implode("\n", $lines);
	}

	private function looksLikeAuthFailure(string $text): bool {
		return (bool)preg_match(
			'/invalid authentication|authentication_error|please run\s*\/?login|oauth token|api key|not authenticated|unauthorized/i',
			$text,
		);
	}

	/**
	 * Run a command without a shell and with a hard timeout.
	 *
	 * @param list<string> $argv
	 * @return array{code: int, stdout: string, stderr: string, timedOut: bool}
	 */
	private function exec(array $argv, int $timeout, ?string $stdin = null): array {
		$env = ['PATH' => getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin'];
		$home = $this->config->getCliHome();
		if ($home !== '') {
			$env['HOME'] = $home;
		}
		if ($this->provider === 'claude') {
			// No memory folder either: even with --no-session-persistence the tool made
			// .claude/projects/<working folder>/memory under its home for every run, and
			// every run has a working folder of its own (2026-10-06).
			$env['CLAUDE_CODE_DISABLE_AUTO_MEMORY'] = '1';
		}
		// Every run works in an empty folder made for that message: the home holds
		// the tool's login, and the folder a tool works in is the folder it can read
		// (review T2).
		$cwd = $this->tempManager->getTemporaryFolder() ?: sys_get_temp_dir();

		// In a session of its own, so a timeout can end the tool and everything it started (review T13).
		$setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid' : (is_executable('/bin/setsid') ? '/bin/setsid' : '');
		if ($setsid !== '' && function_exists('posix_kill')) {
			array_unshift($argv, $setsid);
		} else {
			$setsid = '';
		}

		$descriptors = [0 => $stdin === null ? ['file', '/dev/null', 'r'] : ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
		$process = @proc_open($argv, $descriptors, $pipes, $cwd, $env);
		if (!is_resource($process)) {
			$this->logger->error('AI-Hub: could not start ' . $argv[0]);
			return ['code' => -1, 'stdout' => '', 'stderr' => 'Could not start ' . $argv[0], 'timedOut' => false];
		}
		// stdin is written as the tool takes it, between reads of its output: a large
		// one (images run to megabytes) written in one go could fill the pipe while the
		// tool waits for us to read what it printed, and neither would move again.
		$pending = $stdin ?? '';
		if ($stdin !== null) {
			stream_set_blocking($pipes[0], false);
		}

		stream_set_blocking($pipes[1], false);
		stream_set_blocking($pipes[2], false);

		$stdout = '';
		$stderr = '';
		$deadline = time() + $timeout;
		$timedOut = false;
		// Before PHP 8.3, proc_close() answers -1 once proc_get_status() has seen the
		// process end: the exit code is the one seen here (review T8).
		$exit = null;

		while (true) {
			$wrote = 0;
			if ($stdin !== null && is_resource($pipes[0])) {
				while ($pending !== '') {
					$n = @fwrite($pipes[0], substr($pending, 0, 65536));
					if ($n === false || $n === 0) {
						break;
					}
					$pending = (string)substr($pending, $n);
					$wrote += $n;
				}
				if ($pending === '' || $n === false) {
					fclose($pipes[0]);
				}
			}
			$stdout .= (string)stream_get_contents($pipes[1]);
			$stderr .= (string)stream_get_contents($pipes[2]);

			$status = proc_get_status($process);
			if (!$status['running']) {
				$exit = (int)$status['exitcode'];
				break;
			}
			if (time() >= $deadline) {
				$timedOut = true;
				if ($setsid !== '') {
					posix_kill(-(int)$status['pid'], 9);
				}
				proc_terminate($process, 9);
				break;
			}
			// Waiting on the tool; only a brief pause while stdin is still going in.
			usleep($wrote > 0 || ($stdin !== null && is_resource($pipes[0])) ? 5000 : 100000);
		}
		if ($stdin !== null && is_resource($pipes[0])) {
			fclose($pipes[0]);
		}

		$stdout .= (string)stream_get_contents($pipes[1]);
		$stderr .= (string)stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$code = proc_close($process);
		if ($exit !== null && $exit >= 0) {
			$code = $exit;
		}

		return ['code' => $code, 'stdout' => $stdout, 'stderr' => $stderr, 'timedOut' => $timedOut];
	}
}
