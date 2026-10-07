<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Controller;

use OCA\AIHub\Service\HubService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * A remembered conversation, for the pages of the apps that talk to AI-Hub: read it
 * back after a reload, save it to the person's Files, have it summed up and saved,
 * or drop it. Only ever the person's own, of the login they are in (HubService).
 *
 *   GET  /apps/ai_hub/conversation?app=…&conversation=…   {turns: [{role, text}]}
 *   POST /apps/ai_hub/conversation/save      app, conversation   {name, path, fileId, url} | {error}
 *   POST /apps/ai_hub/conversation/summary   app, conversation   {id} | {error}
 *   GET  /apps/ai_hub/conversation/result/{id}                  {state, text, file}
 *   POST /apps/ai_hub/conversation/forget    app, conversation
 */
class ConversationController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private HubService $hub,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string {
		$user = $this->userSession->getUser();
		return $user === null ? '' : $user->getUID();
	}

	#[NoAdminRequired]
	public function show(string $app = '', string $conversation = ''): JSONResponse {
		return new JSONResponse(['turns' => $this->hub->transcript($this->uid(), $app, $conversation)]);
	}

	#[NoAdminRequired]
	public function save(string $app = '', string $conversation = ''): JSONResponse {
		$out = $this->hub->saveTranscript($this->uid(), $app, $conversation);
		return new JSONResponse($out, isset($out['error']) ? ($out['error'] === 'empty' ? Http::STATUS_NOT_FOUND : Http::STATUS_INTERNAL_SERVER_ERROR) : Http::STATUS_OK);
	}

	#[NoAdminRequired]
	public function summarize(string $app = '', string $conversation = ''): JSONResponse {
		$out = $this->hub->summarize($this->uid(), $app, $conversation);
		$status = Http::STATUS_OK;
		if (isset($out['error'])) {
			$status = match ($out['error']) {
				'empty' => Http::STATUS_NOT_FOUND,
				'user-not-allowed', 'app-not-allowed' => Http::STATUS_FORBIDDEN,
				default => Http::STATUS_SERVICE_UNAVAILABLE,
			};
		}
		return new JSONResponse($out, $status);
	}

	#[NoAdminRequired]
	public function result(string $id): JSONResponse {
		return new JSONResponse($this->hub->result($this->uid(), $id));
	}

	#[NoAdminRequired]
	public function forget(string $app = '', string $conversation = ''): JSONResponse {
		$this->hub->forgetConversation($this->uid(), $app, $conversation);
		return new JSONResponse(['ok' => true]);
	}
}
