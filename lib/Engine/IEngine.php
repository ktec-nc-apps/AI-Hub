<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Engine;

/**
 * A pluggable AI backend.
 *
 * Implementations talk to one provider and know nothing about the hub: they get the
 * history, the new message and a system prompt, and return the answer.
 */
interface IEngine {

	/** Short identifier used in logs and in the connection test, e.g. "claude-api". */
	public function getName(): string;

	/**
	 * @param list<array{role: string, text: string, images?: list<array{type: string, data: string}>}> $history Oldest first,
	 *                       excluding $message. A turn may carry images (base64, checked by the hub) -- only within
	 *                       one question, when a tool round puts the question with its images into the history.
	 * @param bool $elevated Whether the person asking is a Nextcloud administrator
	 *                       and the admin tier is switched on. Only the command
	 *                       line engine can act on it; the HTTP engines have no
	 *                       tools to give either way.
	 * @param array{search?: bool, images?: list<array{type: string, data: string}>} $options For another app asking through
	 *                       AssistantService: 'search' lets the model search the web, on the provider's side where it
	 *                       has such a tool (Claude, Gemini), and nothing else. Every engine answers from $history:
	 *                       none keeps a conversation of its own. 'images' are the images sent with $message
	 *                       (list of ['type' => 'image/png'|'image/jpeg'|'image/gif'|'image/webp', 'data' => base64]),
	 *                       already checked by the hub; an engine that cannot pass images on refuses the turn.
	 */
	public function run(array $history, string $message, string $systemPrompt, bool $elevated = false, array $options = []): TurnResult;

	/** List the model ids this engine can currently use. Empty when unavailable. */
	public function listModels(): array;

	/** Use this model instead of the one set for the provider (an app's own choice). */
	public function useModel(string $model): void;
}
