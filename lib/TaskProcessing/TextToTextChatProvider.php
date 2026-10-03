<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\TaskProcessing;

use OCA\AIHub\AppInfo\Application;
use OCP\TaskProcessing\TaskTypes\TextToTextChat;

/** Nextcloud's "chat" task: a system prompt, a history and the new message. */
class TextToTextChatProvider extends TextToTextProvider {
	public function getId(): string {
		return Application::APP_ID . '-chat';
	}

	public function getTaskTypeId(): string {
		return TextToTextChat::ID;
	}

	public function process(?string $userId, array $input, callable $reportProgress): array {
		$message = is_string($input['input'] ?? null) ? $input['input'] : '';
		if (trim($message) === '') {
			throw new \RuntimeException('Nothing to answer.');
		}
		$status = $this->hub->status();
		if (!$status['ready']) {
			throw new \RuntimeException('AI-Hub is not ready: ' . $status['reason']);
		}
		$system = is_string($input['system_prompt'] ?? null) && trim($input['system_prompt']) !== ''
			? $input['system_prompt']
			: 'You are a helpful assistant inside Nextcloud. Answer in the language the person writes in.';
		// The history is a list of texts, the person's and the assistant's in turn,
		// the person's first.
		$history = [];
		$role = 'user';
		foreach ((array)($input['history'] ?? []) as $text) {
			if (is_string($text) && $text !== '') {
				$history[] = ['role' => $role, 'text' => $text];
			}
			$role = $role === 'user' ? 'assistant' : 'user';
		}
		$result = $this->engines->get()->run($history, $message, $system);
		if (!$result->isOk()) {
			throw new \RuntimeException($result->detail !== '' ? $result->detail : 'The model gave no answer.');
		}
		return ['output' => $result->output];
	}
}
