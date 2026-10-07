<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Engine;

/** Google Gemini through the Generative Language API (chat only, no tools). */
class GeminiApiEngine extends AbstractHttpEngine {

	private const BASE = 'https://generativelanguage.googleapis.com/v1beta';

	private const KNOWN_MODELS = [
		'gemini-2.5-pro',
		'gemini-2.5-flash',
		'gemini-2.0-flash',
		'gemini-1.5-pro',
		'gemini-1.5-flash',
	];

	public function getName(): string {
		return 'gemini-api';
	}

	public function run(array $history, string $message, string $systemPrompt, bool $elevated = false, array $options = []): TurnResult {
		$key = $this->config->getApiKey('gemini');
		if ($key === '') {
			return TurnResult::authError('No Gemini API key is configured.');
		}

		$contents = [];
		foreach ($history as $turn) {
			$contents[] = [
				'role' => $turn['role'] === 'assistant' ? 'model' : 'user',
				'parts' => self::parts($turn['text'], $turn['images'] ?? []),
			];
		}
		$contents[] = ['role' => 'user', 'parts' => self::parts($message, $options['images'] ?? [])];

		// The key goes in a header, never in the address: an error from the HTTP
		// client repeats the address, and it was posted to the room (review T1).
		$url = self::BASE . '/models/' . rawurlencode($this->model('gemini')) . ':generateContent';

		$body = [
			'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
			'contents' => $contents,
		];
		if (!empty($options['search'])) {
			// Google's own search, on Google's side: the internet, never this server's network.
			$body['tools'] = [['google_search' => new \stdClass()]];
		}
		$result = $this->request('POST', $url, ['content-type' => 'application/json', 'x-goog-api-key' => $key], $body);

		if ($this->isAuthFailure($result['status'])) {
			return TurnResult::authError($this->errorMessage($result));
		}
		if ($result['status'] < 200 || $result['status'] > 299) {
			return TurnResult::error($this->errorMessage($result));
		}

		$text = '';
		foreach ($result['body']['candidates'][0]['content']['parts'] ?? [] as $part) {
			$text .= $part['text'] ?? '';
		}
		$text = trim($text);
		return $text === '' ? TurnResult::error('The model returned an empty response.') : TurnResult::ok($text);
	}

	/**
	 * A turn's parts: the images as inline data first, then the text (left out when
	 * there is none and an image stands on its own).
	 *
	 * @param list<array{type: string, data: string}> $images
	 * @return list<array<string, mixed>>
	 */
	private static function parts(string $text, array $images): array {
		$parts = [];
		foreach ($images as $image) {
			$parts[] = ['inline_data' => ['mime_type' => $image['type'], 'data' => $image['data']]];
		}
		if ($images === [] || trim($text) !== '') {
			$parts[] = ['text' => $text];
		}
		return $parts;
	}

	public function listModels(): array {
		$key = $this->config->getApiKey('gemini');
		if ($key === '') {
			return self::KNOWN_MODELS;
		}
		$result = $this->request('GET', self::BASE . '/models?pageSize=200', ['x-goog-api-key' => $key]);
		if ($result['status'] < 200 || $result['status'] > 299) {
			return self::KNOWN_MODELS;
		}
		$models = [];
		foreach ($result['body']['models'] ?? [] as $model) {
			$methods = $model['supportedGenerationMethods'] ?? [];
			if (!in_array('generateContent', $methods, true)) {
				continue;
			}
			$name = (string)($model['name'] ?? '');
			if (str_starts_with($name, 'models/')) {
				$models[] = substr($name, strlen('models/'));
			}
		}
		return $models === [] ? self::KNOWN_MODELS : $models;
	}
}
