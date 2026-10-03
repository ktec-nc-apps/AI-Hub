<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Service;

use OCP\ICacheFactory;
use OCP\IMemcache;

/**
 * How much may be asked: questions per user per minute, answers in progress per
 * user, and answers in progress for everyone together (0 = no limit). Counted in
 * the shared memory cache; without one that can count, nothing is limited.
 */
class LimitService {
	public function __construct(
		private ConfigService $config,
		private ICacheFactory $cacheFactory,
	) {
	}

	/** @return list<string>|null The counters to release afterwards, or null when a limit is reached. */
	public function take(string $userId): ?array {
		$cache = $this->cacheFactory->createDistributed('ai_hub');
		if (!$cache instanceof IMemcache) {
			return [];
		}
		$perMinute = $this->config->getRatePerMinute();
		if ($perMinute > 0) {
			$key = 'rate_' . $userId . '_' . intdiv(time(), 60);
			$n = $cache->inc($key);
			if ($n === 1) {
				$cache->set($key, 1, 120);
			}
			if ($n !== false && $n > $perMinute) {
				return null;
			}
		}
		// A crashed answer must not hold its place forever: the counters expire after the longest wait.
		$ttl = $this->config->getRequestTimeout() + 120;
		$taken = [];
		foreach (['run_user_' . $userId => $this->config->getMaxParallelPerUser(), 'run_total' => $this->config->getMaxParallelTotal()] as $key => $max) {
			if ($max === 0) {
				continue;
			}
			$n = $cache->inc($key);
			if ($n === false) {
				continue;
			}
			if ($n === 1) {
				$cache->set($key, 1, $ttl);
			}
			$taken[] = $key;
			if ($n > $max) {
				$this->release($taken);
				return null;
			}
		}
		return $taken;
	}

	/** @param list<string> $keys */
	public function release(array $keys): void {
		if ($keys === []) {
			return;
		}
		$cache = $this->cacheFactory->createDistributed('ai_hub');
		foreach ($keys as $key) {
			if ($cache instanceof IMemcache && $cache->dec($key) === false) {
				$cache->remove($key);
			}
		}
	}
}
