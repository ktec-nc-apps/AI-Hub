<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Controller;

use OCA\AIHub\Engine\CliEngine;
use OCA\AIHub\Engine\EngineFactory;
use OCA\AIHub\Service\ConfigService;
use OCA\AIHub\Service\HubService;
use OCA\AIHub\Service\Redact;
use OCA\AIHub\Settings\AdminTools;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\AuthorizedAdminSetting;
use OCP\App\IAppManager;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IL10N;
use OCP\IRequest;

/**
 * Backs the model picker, the connection test and the list of connecting apps
 * in the admin settings. Everything here can be asked for the server-wide
 * settings or for one app, which may have its own AI and model.
 */
class ToolController extends Controller {
	/** The name Nextcloud's own Task Processing asks under. */
	public const TASK_PROCESSING = 'taskprocessing';
	/** The Base series is listed from the start, greyed out until it is installed (the owner, 2026-10-03). */
	public const BASE_APPS = ['editbase' => 'EditBase', 'regibase' => 'RegiBase', 'formulabase' => 'FormulaBase', 'netbase' => 'NetBase'];

	public function __construct(
		string $appName,
		IRequest $request,
		private ConfigService $config,
		private EngineFactory $engineFactory,
		private HubService $hub,
		private IAppManager $appManager,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	/**
	 * Which models an engine can use: the server-wide one, an app's own, or a
	 * given provider × mode (while the admin is choosing for an app).
	 */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function models(string $app = '', string $provider = '', string $mode = ''): JSONResponse {
		[$provider, $mode, $model] = $this->resolve($app, $provider, $mode);

		$models = [];
		$note = '';
		try {
			$models = $this->engineFactory->build($provider, $mode)->listModels();
		} catch (\Throwable $e) {
			$note = $e->getMessage();
		}

		if ($models === []) {
			$note = $note !== '' ? $note : $this->l->t('No model list could be retrieved. Check the API key and the base URL.');
		} elseif ($provider !== 'openai' && $this->config->getApiKey($provider) === '' && $mode === 'api') {
			$note = $this->l->t('No API key is set yet, so this is a list of well-known models rather than the ones your key can use.');
		}

		return new JSONResponse([
			'current' => ['provider' => $provider, 'mode' => $mode, 'model' => $model],
			'models' => array_values($models),
			'engines' => $app === '' ? $this->probeEngines() : [],
			'note' => $note,
		]);
	}

	/** Really call the engine once: the server-wide one, or the one an app gets. */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function test(string $app = ''): JSONResponse {
		[$provider, $mode, $model] = $this->resolve($app, '', '');

		if ($model === '') {
			return new JSONResponse([
				'ok' => false,
				'detail' => $this->l->t('Pick a model first.'),
			]);
		}

		try {
			$engine = $app === '' ? $this->engineFactory->get() : $this->engineFactory->forApp($app);
			$result = $engine->run(
				[],
				'Reply with the single word: ok',
				'You are a connection test. Answer with one short word.',
			);
		} catch (\Throwable $e) {
			return new JSONResponse(['ok' => false, 'detail' => Redact::text($e->getMessage(), Redact::keysOf($this->config))]);
		}

		return new JSONResponse([
			'ok' => $result->isOk(),
			'engine' => $provider . ' / ' . $mode,
			'model' => $model,
			'reply' => mb_substr($result->output, 0, 200),
			'detail' => mb_substr(Redact::text($result->detail, Redact::keysOf($this->config)), 0, 500),
		]);
	}

	/** Store the model chosen in the dropdown: server-wide, or for one app. */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function setModel(string $model, string $app = ''): JSONResponse {
		$model = trim($model);
		if ($model === '' || mb_strlen($model) > 200) {
			return new JSONResponse(['ok' => false, 'detail' => $this->l->t('That is not a valid model name.')]);
		}
		if ($app !== '') {
			if (!self::validApp($app)) {
				return new JSONResponse(['ok' => false, 'detail' => $this->l->t('That is not a valid app id.')], Http::STATUS_BAD_REQUEST);
			}
			$row = $this->config->getAppEngines()[$app] ?? ['provider' => '', 'mode' => ''];
			$this->config->setAppEngine($app, $row['provider'], $row['mode'], $model);
		} else {
			$this->config->setModel($model);
		}
		return new JSONResponse(['ok' => true, 'model' => $model]);
	}

	/**
	 * The apps that connect to the hub, each with what is set for it and what it
	 * really gets. Nextcloud's own Task Processing is always listed.
	 */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function apps(): JSONResponse {
		return new JSONResponse(['apps' => $this->listApps()]);
	}

	/** Give one app its own AI and model (empty strings = the server-wide choice). */
	#[AuthorizedAdminSetting(settings: AdminTools::class)]
	public function setApp(string $app, string $provider = '', string $mode = '', string $model = ''): JSONResponse {
		if (!self::validApp($app)) {
			return new JSONResponse(['ok' => false, 'detail' => $this->l->t('That is not a valid app id.')], Http::STATUS_BAD_REQUEST);
		}
		if (mb_strlen($model) > 200) {
			return new JSONResponse(['ok' => false, 'detail' => $this->l->t('That is not a valid model name.')], Http::STATUS_BAD_REQUEST);
		}
		$this->config->setAppEngine($app, $provider, $mode, $model);
		foreach ($this->listApps() as $row) {
			if ($row['id'] === $app) {
				return new JSONResponse(['ok' => true, 'app' => $row]);
			}
		}
		return new JSONResponse(['ok' => true]);
	}

	private static function validApp(string $app): bool {
		return (bool)preg_match('/^[a-z][a-z0-9_]{1,63}$/', $app);
	}

	/**
	 * @return array{0: string, 1: string, 2: string} provider, mode, model
	 */
	private function resolve(string $app, string $provider, string $mode): array {
		if ($app !== '' && self::validApp($app)) {
			$e = $this->config->getAppEngine($app);
			$base = [$e['provider'], $e['mode'], $e['model']];
		} else {
			$base = [$this->config->getProvider(), $this->config->getMode(), $this->config->getModel()];
		}
		if (in_array($provider, ConfigService::PROVIDERS, true)) {
			$base[0] = $provider;
			$base[2] = $this->config->getModel($provider);
		}
		if (in_array($mode, ConfigService::MODES, true)) {
			$base[1] = $mode;
		}
		if ($base[0] === 'openai' || ($base[1] === 'cli' && !$this->config->isCliEnabled())) {
			$base[1] = 'api';
		}
		return $base;
	}

	/** @return list<array<string, mixed>> */
	private function listApps(): array {
		$seen = $this->config->getAppsSeen();
		$own = $this->config->getAppEngines();
		// The Base series first, then Nextcloud's own Task Processing, then whatever else has connected.
		$ids = array_keys(self::BASE_APPS);
		$ids[] = self::TASK_PROCESSING;
		$rest = array_values(array_diff(array_unique(array_merge(array_keys($seen), array_keys($own))), $ids));
		sort($rest);
		$out = [];
		foreach (array_merge($ids, $rest) as $id) {
			$status = $this->hub->status($id);
			$out[] = [
				'id' => $id,
				'label' => $this->labelOf($id),
				'installed' => $id === self::TASK_PROCESSING || $this->appManager->isEnabledForUser($id),
				'connected' => $id === self::TASK_PROCESSING || isset($seen[$id]),
				'scenarios' => $seen[$id]['scenarios'] ?? [],
				'seen' => $seen[$id]['seen'] ?? 0,
				'own' => $own[$id] ?? ['provider' => '', 'mode' => '', 'model' => ''],
				'gets' => ['provider' => $status['provider'], 'mode' => $status['mode'], 'model' => $status['model']],
				'ready' => $status['ready'],
				'reason' => $status['reason'],
			];
		}
		return $out;
	}

	/** The name shown for an app: the Base series by name, others by what their info.xml says. */
	private function labelOf(string $id): string {
		if ($id === self::TASK_PROCESSING) {
			return $this->l->t('Nextcloud Task Processing (Assistant and the standard API)');
		}
		if (isset(self::BASE_APPS[$id])) {
			return self::BASE_APPS[$id];
		}
		try {
			$name = $this->appManager->getAppInfo($id)['name'] ?? '';
			return is_string($name) && $name !== '' ? $name : $id;
		} catch (\Throwable) {
			return $id;
		}
	}

	/**
	 * A quick, cheap look at every engine, so the admin can see what is ready to
	 * use without switching the live setting back and forth.
	 *
	 * @return list<array{id: string, label: string, ready: bool, detail: string}>
	 */
	private function probeEngines(): array {
		$engines = [];

		foreach (['claude' => 'Claude', 'gemini' => 'Gemini'] as $provider => $label) {
			$hasKey = $this->config->getApiKey($provider) !== '';
			$engines[] = [
				'id' => $provider . '/api',
				'label' => $label . ' — ' . $this->l->t('API key'),
				'ready' => $hasKey,
				'detail' => $hasKey ? $this->l->t('API key is set.') : $this->l->t('No API key.'),
			];
		}

		$baseUrl = $this->config->getOpenAiBaseUrl();
		$engines[] = [
			'id' => 'openai/api',
			'label' => $this->l->t('OpenAI-compatible'),
			'ready' => $baseUrl !== '',
			'detail' => $baseUrl,
		];

		if ($this->config->isCliEnabled()) {
			foreach (['claude' => 'Claude', 'gemini' => 'Gemini'] as $provider => $label) {
				$engine = $this->engineFactory->build($provider, 'cli');
				$check = $engine instanceof CliEngine
					? $engine->checkBinary()
					: ['ok' => false, 'detail' => ''];
				$engines[] = [
					'id' => $provider . '/cli',
					'label' => $label . ' — ' . $this->l->t('command line tool'),
					'ready' => (bool)$check['ok'],
					'detail' => match ($check['reason'] ?? '') {
						'no_path' => $this->l->t('No path configured.'),
						'exit_code' => $this->l->t('The command line tool ended with exit code %s.', [(string)($check['code'] ?? '')]),
						default => (string)$check['detail'],
					},
				];
			}
		}

		return $engines;
	}
}
