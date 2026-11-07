<?php
/**
 * The counters of questions running at once last as long as a question can run: one
 * question may call the model up to ten times, each up to the request timeout. They once
 * lapsed after one timeout, and the next question's place was then given back for it.
 */
declare(strict_types=1);
require __DIR__ . '/boot.php';

use OCA\AIHub\Service\LimitService;

$cache = memcache();
$cf = stub(OCP\ICacheFactory::class, ['createDistributed' => static fn () => $cache, 'isAvailable' => static fn () => true]);
$limits = build(LimitService::class, ['config' => config(['getRequestTimeout' => 180, 'getMaxParallelPerUser' => 2, 'getMaxParallelTotal' => 4]), 'cacheFactory' => $cf]);
$slots = attempt('take', fn () => $limits->take('alice'));
$longest = 10 * 180;
check('per-user counter outlives the longest question (10 x 180 s)', ($cache->ttls['run_user_alice'] ?? 0) > $longest, (string)($cache->ttls['run_user_alice'] ?? 'unset'));
check('total counter outlives the longest question', ($cache->ttls['run_total'] ?? 0) > $longest, (string)($cache->ttls['run_total'] ?? 'unset'));
finish();
