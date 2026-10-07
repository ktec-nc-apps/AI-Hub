<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Service;

use OCP\Http\Client\IClientService;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

/**
 * Gets the slow part of answering out of the request that asked for it.
 *
 * A model can take a minute. The question is handed to a second, self-addressed
 * request, signed with the hub's own secret, and this request stops waiting for
 * it after a moment; that request keeps running and writes the answer down, where
 * the app that asked fetches it. If the server cannot reach itself at all, the
 * caller answers in its own request.
 */
class AsyncService {
	/** How long the asking request waits before walking away. */
	private const HANDOFF_TIMEOUT = 2;
	/** A signed hand-off older than this is refused. */
	public const MAX_AGE = 300;

	public function __construct(
		private IClientService $clientService,
		private IURLGenerator $urlGenerator,
		private ConfigService $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @param array<string, mixed> $payload Must carry 'time' and 'nonce'.
	 * @return bool Whether the handler got the question.
	 */
	public function handOff(array $payload): bool {
		$body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($body === false) {
			return false;
		}
		try {
			$this->clientService->newClient()->post(
				$this->urlGenerator->getAbsoluteURL('/index.php/apps/ai_hub/process'),
				[
					'headers' => [
						'Content-Type' => 'application/json',
						'X-AIHub-Signature' => $this->sign($body),
					],
					'body' => $body,
					// A question with images can run to megabytes: give the body time to
					// arrive whole (a second more for every 4 MB), or the handler never runs.
					'timeout' => self::HANDOFF_TIMEOUT + intdiv(strlen($body), 4 << 20),
					'connect_timeout' => 10,
					'nextcloud' => ['allow_local_address' => true],
				],
			);
			return true;
		} catch (\Throwable $e) {
			if ($this->handedOff($e)) {
				return true;
			}
			$this->logger->warning('AI-Hub: could not hand a question off over HTTP, answering in the same request: ' . $e->getMessage());
			return false;
		}
	}

	public function sign(string $body): string {
		return hash_hmac('sha256', $body, $this->config->getBotSecret());
	}

	public function verify(string $body, string $signature): bool {
		return $signature !== '' && hash_equals($this->sign($body), strtolower($signature));
	}

	/**
	 * A READ time-out and a proxy's 504 mean the handler has the question and is
	 * busy answering; a CONNECT time-out, a refused connection or a failed name
	 * lookup mean it does not.
	 */
	private function handedOff(\Throwable $e): bool {
		for ($x = $e; $x !== null; $x = $x->getPrevious()) {
			if (method_exists($x, 'getResponse') && $x->getResponse() !== null) {
				return $x->getResponse()->getStatusCode() === 504;
			}
		}
		return str_contains(strtolower($e->getMessage()), 'operation timed out after');
	}
}
