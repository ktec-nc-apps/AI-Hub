<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Engine;

use OCA\AIHub\Service\ConfigService;
use OCP\Http\Client\IClientService;
use OCP\ITempManager;
use Psr\Log\LoggerInterface;

/** Builds an engine: provider × mode, with the model to use. */
class EngineFactory {

	public function __construct(
		private ConfigService $config,
		private IClientService $clientService,
		private ITempManager $tempManager,
		private LoggerInterface $logger,
	) {
	}

	/** The engine for the server-wide settings. */
	public function get(): IEngine {
		return $this->build($this->config->getProvider(), $this->config->getMode());
	}

	/** The engine for one app: its own choice of AI and model, or the server-wide one. */
	public function forApp(string $app): IEngine {
		$e = $this->config->getAppEngine($app);
		return $this->build($e['provider'], $e['mode'], $e['model']);
	}

	/** @param string|null $model The model to use; null = the one set for the provider. */
	public function build(string $provider, string $mode, ?string $model = null): IEngine {
		if ($provider !== 'openai' && $mode === 'cli') {
			$engine = new CliEngine($this->config, $this->tempManager, $this->logger, $provider);
		} else {
			$engine = match ($provider) {
				'gemini' => new GeminiApiEngine($this->config, $this->clientService, $this->logger),
				'openai' => new OpenAiCompatEngine($this->config, $this->clientService, $this->logger),
				default => new ClaudeApiEngine($this->config, $this->clientService, $this->logger),
			};
		}
		if ($model !== null && $model !== '') {
			$engine->useModel($model);
		}
		return $engine;
	}
}
