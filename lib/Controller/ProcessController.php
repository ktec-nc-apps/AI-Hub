<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Controller;

use OCA\AIHub\Service\AsyncService;
use OCA\AIHub\Service\HubService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ICacheFactory;
use OCP\IMemcache;
use OCP\IRequest;

/**
 * Answers one question, in a request of its own. Only reachable with a
 * signature made from the hub's secret, which never leaves the server.
 */
class ProcessController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private AsyncService $async,
		private HubService $hub,
		private ITimeFactory $timeFactory,
		private ICacheFactory $cacheFactory,
	) {
		parent::__construct($appName, $request);
	}

	#[PublicPage]
	#[NoCSRFRequired]
	#[BruteForceProtection(action: 'aihubProcess')]
	public function answer(): JSONResponse {
		$body = file_get_contents('php://input');
		if (!is_string($body) || $body === '') {
			return new JSONResponse(['status' => 'empty'], Http::STATUS_BAD_REQUEST);
		}
		if (!$this->async->verify($body, $this->request->getHeader('X-AIHub-Signature'))) {
			$response = new JSONResponse(['status' => 'invalid signature'], Http::STATUS_UNAUTHORIZED);
			$response->throttle(['action' => 'aihubProcess']);
			return $response;
		}
		$payload = json_decode($body, true);
		if (!is_array($payload)) {
			return new JSONResponse(['status' => 'malformed'], Http::STATUS_BAD_REQUEST);
		}
		$age = $this->timeFactory->getTime() - (int)($payload['time'] ?? 0);
		if ($age > AsyncService::MAX_AGE || $age < -AsyncService::MAX_AGE) {
			return new JSONResponse(['status' => 'stale'], Http::STATUS_BAD_REQUEST);
		}
		// Each signed request is answered once: a copy sent again inside the time
		// window is refused. Without a shared cache that can do this, the window alone applies.
		$nonce = (string)($payload['nonce'] ?? '');
		$cache = $this->cacheFactory->createDistributed('ai_hub');
		if ($cache instanceof IMemcache) {
			if ($nonce === '' || !$cache->add('nonce_' . hash('sha256', $nonce), 1, 2 * AsyncService::MAX_AGE)) {
				return new JSONResponse(['status' => 'replayed'], Http::STATUS_CONFLICT);
			}
		}
		// The caller stops waiting after a couple of seconds; keep going anyway.
		ignore_user_abort(true);
		@set_time_limit(0);
		$this->hub->answer($payload);
		return new JSONResponse(['status' => 'done']);
	}
}
