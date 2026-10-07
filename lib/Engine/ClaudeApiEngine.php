<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Engine;

/** Anthropic Claude through the Messages API (chat; web search on Anthropic's side when asked for). */
class ClaudeApiEngine extends AbstractHttpEngine {

	private const BASE = 'https://api.anthropic.com/v1';
	private const VERSION = '2023-06-01';

	/** Shown when no key is set yet, so the model dropdown is never empty. */
	private const KNOWN_MODELS = [
		'claude-fable-5-1',
		'claude-opus-5-5',
		'claude-sonnet-5-5',
		'claude-fable-5',
		'claude-opus-5',
		'claude-sonnet-5',
		'claude-haiku-4-5',
	];

	public function getName(): string {
		return 'claude-api';
	}

	public function run(array $history, string $message, string $systemPrompt, bool $elevated = false, array $options = []): TurnResult {
		$key = $this->config->getApiKey('claude');
		if ($key === '') {
			return TurnResult::authError('No Claude API key is configured.');
		}

		$messages = [];
		foreach ($history as $turn) {
			$messages[] = [
				'role' => $turn['role'] === 'assistant' ? 'assistant' : 'user',
				'content' => self::content($turn['text'], $turn['images'] ?? []),
			];
		}
		$messages[] = ['role' => 'user', 'content' => self::content($message, $options['images'] ?? [])];

		$body = [
			'model' => $this->model('claude'),
			// Room for a long answer (a document, a shaped JSON reply): at 4096 the
			// answer was cut off and a shaped one then failed its schema.
			'max_tokens' => 16384,
			'system' => $systemPrompt,
			'messages' => $messages,
		];
		if (!empty($options['search'])) {
			// Anthropic's own web search: it runs on their side, so it reaches the
			// internet and never the network this server stands in.
			$body['tools'] = [['type' => 'web_search_20250305', 'name' => 'web_search', 'max_uses' => 5]];
		}
		$result = $this->request('POST', self::BASE . '/messages', $this->headers($key), $body);

		if ($this->isAuthFailure($result['status'])) {
			return TurnResult::authError($this->errorMessage($result));
		}
		if ($result['status'] < 200 || $result['status'] > 299) {
			return TurnResult::error($this->errorMessage($result));
		}

		$text = '';
		foreach ($result['body']['content'] ?? [] as $block) {
			if (($block['type'] ?? '') === 'text') {
				$text .= $block['text'] ?? '';
			}
		}
		$text = trim($text);
		if (($result['body']['stop_reason'] ?? '') === 'max_tokens') {
			$this->logger->warning('AI-Hub: the Claude answer was cut off at the output limit', ['model' => $this->model('claude')]);
		}
		return $text === '' ? TurnResult::error('The model returned an empty response.') : TurnResult::ok($text);
	}

	/**
	 * A turn's content: the text alone, or with images the image blocks first and then
	 * the text (left out when there is none -- an image may be sent on its own).
	 *
	 * @param list<array{type: string, data: string}> $images
	 * @return string|list<array<string, mixed>>
	 */
	private static function content(string $text, array $images): string|array {
		if ($images === []) {
			return $text;
		}
		$blocks = [];
		foreach ($images as $image) {
			$blocks[] = ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => $image['type'], 'data' => $image['data']]];
		}
		if (trim($text) !== '') {
			$blocks[] = ['type' => 'text', 'text' => $text];
		}
		return $blocks;
	}

	public function listModels(): array {
		$key = $this->config->getApiKey('claude');
		if ($key === '') {
			return self::KNOWN_MODELS;
		}
		$result = $this->request('GET', self::BASE . '/models?limit=100', $this->headers($key));
		if ($result['status'] < 200 || $result['status'] > 299) {
			return self::KNOWN_MODELS;
		}
		$models = [];
		foreach ($result['body']['data'] ?? [] as $model) {
			if (isset($model['id']) && is_string($model['id'])) {
				$models[] = $model['id'];
			}
		}
		return $models === [] ? self::KNOWN_MODELS : $models;
	}

	/** @return array<string, string> */
	private function headers(string $key): array {
		return [
			'x-api-key' => $key,
			'anthropic-version' => self::VERSION,
			'content-type' => 'application/json',
		];
	}
}
