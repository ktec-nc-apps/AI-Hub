<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Controller;

use OCA\AIHub\Service\HubService;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\DataResponse;
use OCP\AppFramework\OCSController;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * The hub over HTTP, for the browser side of an app or for a script:
 *
 *   GET  /ocs/v2.php/apps/ai_hub/api/v1/status
 *   POST /ocs/v2.php/apps/ai_hub/api/v1/ask      app, scenario, messages[], context?
 *   GET  /ocs/v2.php/apps/ai_hub/api/v1/result/{id}
 *
 * with the user's session (and request token) or an app password, and the
 * header OCS-APIRequest: true.
 */
class ApiController extends OCSController {
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
	public function status(): DataResponse {
		return new DataResponse($this->hub->status());
	}

	/**
	 * @param list<array{role: string, text: string}> $messages
	 */
	#[NoAdminRequired]
	public function ask(string $app, string $scenario, array $messages = [], string $context = '', ?bool $search = null): DataResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new DataResponse(['error' => 'not-logged-in'], Http::STATUS_UNAUTHORIZED);
		}
		$options = ['context' => $context];
		if ($search !== null) {
			$options['search'] = $search;
		}
		$out = $this->hub->ask($uid, $app, $scenario, $messages, $options);
		if (isset($out['error'])) {
			$status = match ($out['error']) {
				'user-not-allowed', 'app-not-allowed' => Http::STATUS_FORBIDDEN,
				'no-scenario', 'empty' => Http::STATUS_BAD_REQUEST,
				'busy' => Http::STATUS_TOO_MANY_REQUESTS,
				default => Http::STATUS_SERVICE_UNAVAILABLE,
			};
			return new DataResponse($out, $status);
		}
		return new DataResponse($out);
	}

	#[NoAdminRequired]
	public function result(string $id): DataResponse {
		$uid = $this->uid();
		if ($uid === '') {
			return new DataResponse(['state' => 'unknown'], Http::STATUS_UNAUTHORIZED);
		}
		return new DataResponse($this->hub->result($uid, $id));
	}
}
