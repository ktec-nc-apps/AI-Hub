<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Service;

use OCA\AIHub\AppInfo\Application;
use OCP\IAppConfig;
use OCP\Security\ISecureRandom;

/**
 * Every setting of the app, stored in the Nextcloud app configuration.
 *
 * API keys and the hub secret are written with the "sensitive" flag so they are
 * encrypted at rest and never leave the server in a config dump.
 */
class ConfigService {

	public const PROVIDERS = ['claude', 'gemini', 'openai'];
	public const MODES = ['api', 'cli'];

	/** Stands in for a stored API key so the real one never reaches the browser. */
	private const SECRET_PLACEHOLDER = '********';

	/** Fields the declarative admin form knows about: id => [key, kind, default]. */
	private const FIELDS = [
		'provider' => ['provider', 'string', 'claude'],
		'mode' => ['mode', 'string', 'api'],
		'claude_api_key' => ['claude_api_key', 'secret', ''],
		'gemini_api_key' => ['gemini_api_key', 'secret', ''],
		'openai_api_key' => ['openai_api_key', 'secret', ''],
		'openai_base_url' => ['openai_base_url', 'string', 'https://openrouter.ai/api/v1'],
		'allowlist_enabled' => ['allowlist_enabled', 'bool', false],
		'allowed_users' => ['allowed_users', 'string', ''],
		'allowed_apps' => ['allowed_apps', 'string', ''],
		'cli_enabled' => ['cli_enabled', 'bool', false],
		'claude_cli_path' => ['claude_cli_path', 'string', 'claude'],
		'gemini_cli_path' => ['gemini_cli_path', 'string', 'gemini'],
		'cli_home' => ['cli_home', 'string', ''],
		'cli_user_tools' => ['cli_user_tools', 'string', ''],
		'rate_per_minute' => ['rate_per_minute', 'int', 10],
		'max_parallel_user' => ['max_parallel_user', 'int', 2],
		'max_parallel_total' => ['max_parallel_total', 'int', 4],
	];

	/** Model ids are chosen in the tools panel, never typed into the form. */
	private const MODEL_KEYS = [
		'claude' => 'claude_model',
		'gemini' => 'gemini_model',
		'openai' => 'openai_model',
	];

	private const MODEL_DEFAULTS = [
		'claude' => 'claude-sonnet-5-5',
		'gemini' => 'gemini-2.5-pro',
		'openai' => '',
	];

	public function __construct(
		private IAppConfig $appConfig,
		private ISecureRandom $random,
	) {
	}

	// -- generic helpers -----------------------------------------------------

	public function getString(string $key, string $default = ''): string {
		return $this->appConfig->getValueString(Application::APP_ID, $key, $default);
	}

	public function setString(string $key, string $value, bool $sensitive = false): void {
		$this->appConfig->setValueString(Application::APP_ID, $key, $value, false, $sensitive);
	}

	public function getBool(string $key, bool $default = false): bool {
		return $this->appConfig->getValueBool(Application::APP_ID, $key, $default);
	}

	public function getInt(string $key, int $default): int {
		return $this->appConfig->getValueInt(Application::APP_ID, $key, $default);
	}

	// -- declarative form access --------------------------------------------

	/**
	 * @return mixed The value for a form field id, or '' for unknown fields.
	 */
	public function getFormValue(string $field): mixed {
		if (!isset(self::FIELDS[$field])) {
			return '';
		}
		[$key, $kind, $default] = self::FIELDS[$field];
		if ($kind === 'bool') {
			return $this->getBool($key, (bool)$default);
		}
		if ($kind === 'int') {
			return $this->getInt($key, (int)$default);
		}
		if ($kind === 'secret') {
			// Never hand a stored key back to the browser; report only whether one is set.
			return $this->getString($key) === '' ? '' : self::SECRET_PLACEHOLDER;
		}
		return $this->getString($key, (string)$default);
	}

	public function setFormValue(string $field, mixed $value): void {
		if (!isset(self::FIELDS[$field])) {
			return;
		}
		// NcSelect emits the whole option object for SELECT fields; unwrap it so we
		// never store the literal string "Array".
		if (is_array($value) && array_key_exists('value', $value)) {
			$value = $value['value'];
		}
		[$key, $kind] = self::FIELDS[$field];

		if ($kind === 'bool') {
			$this->appConfig->setValueBool(Application::APP_ID, $key, (bool)$value);
			return;
		}
		if ($kind === 'int') {
			// 0 means no limit; anything that is not a whole number leaves the value alone
			if (is_numeric($value) && (int)$value >= 0) {
				$this->appConfig->setValueInt(Application::APP_ID, $key, min(100000, (int)$value));
			}
			return;
		}
		$string = is_scalar($value) ? trim((string)$value) : '';
		if ($kind === 'secret') {
			// Nextcloud replaces a stored secret with "dummySecret" before the form
			// reaches the browser; getting it back means the field was not touched.
			if ($string === self::SECRET_PLACEHOLDER || $string === 'dummySecret') {
				return;
			}
			$this->setString($key, $string, true);
			return;
		}
		if ($field === 'provider' && !in_array($string, self::PROVIDERS, true)) {
			return;
		}
		if ($field === 'mode' && !in_array($string, self::MODES, true)) {
			return;
		}
		$this->setString($key, $string);
	}

	// -- engine settings -----------------------------------------------------

	public function getProvider(): string {
		$provider = $this->getString('provider', 'claude');
		return in_array($provider, self::PROVIDERS, true) ? $provider : 'claude';
	}

	/** OpenAI-compatible endpoints are always used through their HTTP API. */
	public function getMode(): string {
		if ($this->getProvider() === 'openai') {
			return 'api';
		}
		$mode = $this->getString('mode', 'api');
		if ($mode === 'cli' && !$this->getBool('cli_enabled')) {
			return 'api';
		}
		return in_array($mode, self::MODES, true) ? $mode : 'api';
	}

	public function getApiKey(?string $provider = null): string {
		return $this->getString(($provider ?? $this->getProvider()) . '_api_key');
	}

	public function getModel(?string $provider = null): string {
		$provider = $provider ?? $this->getProvider();
		$key = self::MODEL_KEYS[$provider] ?? null;
		return $key === null ? '' : $this->getString($key, self::MODEL_DEFAULTS[$provider]);
	}

	public function setModel(string $model, ?string $provider = null): void {
		$provider = $provider ?? $this->getProvider();
		if (isset(self::MODEL_KEYS[$provider])) {
			$this->setString(self::MODEL_KEYS[$provider], $model);
		}
	}

	public function getOpenAiBaseUrl(): string {
		return rtrim($this->getString('openai_base_url', 'https://openrouter.ai/api/v1'), '/');
	}

	public function getCliPath(?string $provider = null): string {
		$provider = $provider ?? $this->getProvider();
		return $provider === 'gemini'
			? $this->getString('gemini_cli_path', 'gemini')
			: $this->getString('claude_cli_path', 'claude');
	}

	public function isCliEnabled(): bool {
		return $this->getBool('cli_enabled');
	}

	public function getCliHome(): string {
		return $this->getString('cli_home');
	}

	/**
	 * Which tools of the command line tool Nextcloud's own Task Processing (the
	 * Assistant) and the connection test may reach. A scenario asked by an app
	 * gets web search or nothing, whatever this says.
	 *
	 * Empty — the default — means none at all: the model can only answer.
	 */
	public function getUserTools(): string {
		return $this->getString('cli_user_tools');
	}

	// -- limits (0 = no limit) ----------------------------------------------

	public function getRatePerMinute(): int {
		return max(0, $this->getInt('rate_per_minute', 10));
	}

	public function getMaxParallelPerUser(): int {
		return max(0, $this->getInt('max_parallel_user', 2));
	}

	public function getMaxParallelTotal(): int {
		return max(0, $this->getInt('max_parallel_total', 4));
	}

	// -- behaviour -----------------------------------------------------------

	public function isAllowlistEnabled(): bool {
		return $this->getBool('allowlist_enabled');
	}

	/** @return list<string> */
	public function getAllowedUsers(): array {
		$raw = $this->getString('allowed_users');
		$users = array_filter(array_map('trim', explode(',', $raw)), static fn (string $u): bool => $u !== '');
		return array_values($users);
	}

	/** @return list<string> App ids that may ask; empty = every app that registers a scenario. */
	public function getAllowedApps(): array {
		$raw = strtolower($this->getString('allowed_apps'));
		$apps = array_filter(array_map('trim', explode(',', $raw)), static fn (string $a): bool => $a !== '');
		return array_values(array_unique($apps));
	}

	// -- per app: which engine and model answer it ---------------------------------
	// Each app that connects can be given its own AI and model; what is left empty
	// follows the server-wide choice above. Nextcloud's own Task Processing asks as
	// the app "taskprocessing".

	/** @return array<string, array{provider: string, mode: string, model: string}> app => what is set (empty = default) */
	public function getAppEngines(): array {
		$raw = json_decode($this->getString('app_engines', '{}'), true);
		$out = [];
		foreach (is_array($raw) ? $raw : [] as $app => $row) {
			if (is_string($app) && is_array($row)) {
				$out[$app] = [
					'provider' => in_array($row['provider'] ?? '', self::PROVIDERS, true) ? $row['provider'] : '',
					'mode' => in_array($row['mode'] ?? '', self::MODES, true) ? $row['mode'] : '',
					'model' => is_string($row['model'] ?? null) ? mb_substr($row['model'], 0, 200) : '',
				];
			}
		}
		return $out;
	}

	/** Empty strings mean "the server-wide choice". */
	public function setAppEngine(string $app, string $provider, string $mode, string $model): void {
		$all = $this->getAppEngines();
		$row = [
			'provider' => in_array($provider, self::PROVIDERS, true) ? $provider : '',
			'mode' => in_array($mode, self::MODES, true) ? $mode : '',
			'model' => mb_substr(trim($model), 0, 200),
		];
		if ($row === ['provider' => '', 'mode' => '', 'model' => '']) {
			unset($all[$app]);
		} else {
			$all[$app] = $row;
		}
		$this->setString('app_engines', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
	}

	/** The longest additional prompt an administrator may give one app. */
	public const APP_PROMPT_MAX = 4000;

	/** @return array<string, string> app => the administrator's additional prompt for it */
	public function getAppPrompts(): array {
		$raw = json_decode($this->getString('app_prompts', '{}'), true);
		$out = [];
		foreach (is_array($raw) ? $raw : [] as $app => $text) {
			if (is_string($app) && is_string($text) && trim($text) !== '') {
				$out[$app] = mb_substr($text, 0, self::APP_PROMPT_MAX);
			}
		}
		return $out;
	}

	/** The administrator's additional prompt for one app ('' = none). */
	public function getAppPrompt(string $app): string {
		return $this->getAppPrompts()[$app] ?? '';
	}

	/** Set (or with '' remove) the administrator's additional prompt for one app. */
	public function setAppPrompt(string $app, string $text): void {
		$all = $this->getAppPrompts();
		$text = trim(str_replace("\r\n", "\n", $text));
		if ($text === '') {
			unset($all[$app]);
		} else {
			$all[$app] = mb_substr($text, 0, self::APP_PROMPT_MAX);
		}
		$this->setString('app_prompts', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
	}

	/**
	 * The engine that answers this app, with every blank filled from the
	 * server-wide settings: provider, mode (an OpenAI-compatible endpoint is always
	 * 'api', and 'cli' only while the command line option is enabled) and model.
	 *
	 * @return array{provider: string, mode: string, model: string, own: bool} own: whether anything is set for this app itself
	 */
	public function getAppEngine(string $app): array {
		$row = $this->getAppEngines()[$app] ?? ['provider' => '', 'mode' => '', 'model' => ''];
		$provider = $row['provider'] !== '' ? $row['provider'] : $this->getProvider();
		$mode = $row['mode'] !== '' ? $row['mode'] : $this->getString('mode', 'api');
		if ($provider === 'openai' || ($mode === 'cli' && !$this->getBool('cli_enabled')) || !in_array($mode, self::MODES, true)) {
			$mode = 'api';
		}
		$model = $row['model'] !== '' ? $row['model'] : $this->getModel($provider);
		return ['provider' => $provider, 'mode' => $mode, 'model' => $model, 'own' => $row !== ['provider' => '', 'mode' => '', 'model' => '']];
	}

	/**
	 * The apps that have connected (registered a scenario), for the settings page.
	 *
	 * @return array<string, array{scenarios: list<string>, seen: int}>
	 */
	public function getAppsSeen(): array {
		$raw = json_decode($this->getString('apps_seen', '{}'), true);
		$out = [];
		foreach (is_array($raw) ? $raw : [] as $app => $row) {
			if (is_string($app) && is_array($row)) {
				$out[$app] = [
					'scenarios' => array_values(array_filter((array)($row['scenarios'] ?? []), 'is_string')),
					'seen' => (int)($row['seen'] ?? 0),
				];
			}
		}
		return $out;
	}

	/** Remember that an app connected. Written only when something changed, or at most once an hour. */
	public function noteAppSeen(string $app, array $scenarios): void {
		$all = $this->getAppsSeen();
		$was = $all[$app] ?? null;
		sort($scenarios);
		if ($was !== null && $was['scenarios'] === $scenarios && $was['seen'] > time() - 3600) {
			return;
		}
		$all[$app] = ['scenarios' => $scenarios, 'seen' => time()];
		$this->setString('apps_seen', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
	}

	// -- apps the administrator added by hand -------------------------------------
	// Ids the admin put in, so an app can be listed and given its own engine before
	// it has ever connected. Stored as a JSON array of app ids.

	/** @return list<string> App ids the administrator added. */
	public function getManagedApps(): array {
		$raw = json_decode($this->getString('managed_apps', '[]'), true);
		$out = [];
		foreach (is_array($raw) ? $raw : [] as $app) {
			if (is_string($app) && preg_match('/^[a-z][a-z0-9_]{1,63}$/', $app)) {
				$out[] = $app;
			}
		}
		return array_values(array_unique($out));
	}

	/** Add an app id to the managed list. A no-op when it is already there. */
	public function addManagedApp(string $app): void {
		$all = $this->getManagedApps();
		if (in_array($app, $all, true)) {
			return;
		}
		$all[] = $app;
		$this->setString('managed_apps', json_encode(array_values($all), JSON_UNESCAPED_SLASHES) ?: '[]');
	}

	/**
	 * Forget an app completely: drop it from the managed list, from its per-app
	 * engine override and from the record that it ever connected.
	 */
	public function forgetApp(string $app): void {
		$managed = array_values(array_filter($this->getManagedApps(), static fn (string $a): bool => $a !== $app));
		$this->setString('managed_apps', json_encode($managed, JSON_UNESCAPED_SLASHES) ?: '[]');

		$engines = $this->getAppEngines();
		if (isset($engines[$app])) {
			unset($engines[$app]);
			$this->setString('app_engines', json_encode($engines, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
		}

		$seen = $this->getAppsSeen();
		if (isset($seen[$app])) {
			unset($seen[$app]);
			$this->setString('apps_seen', json_encode($seen, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
		}
	}

	public function getRequestTimeout(): int {
		return max(10, min(600, $this->getInt('request_timeout', 180)));
	}

	// -- the hub secret ------------------------------------------------------

	/** The hub's own secret, for the request it hands work to. Created on demand. */
	public function getBotSecret(): string {
		$secret = $this->getString('bot_secret');
		if (strlen($secret) < 40) {
			$secret = $this->random->generate(64, ISecureRandom::CHAR_ALPHANUMERIC);
			$this->setString('bot_secret', $secret, true);
		}
		return $secret;
	}
}
