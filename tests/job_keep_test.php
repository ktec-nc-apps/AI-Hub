<?php
/**
 * A question still being answered is kept as long as it can run; and, with no shared
 * cache, sweeping old questions does not take remembered conversations with it.
 */
declare(strict_types=1);
require __DIR__ . '/boot.php';

$cf = cacheFactory(true);
$hub = hub(['config' => config(['getRequestTimeout' => 600]), 'cacheFactory' => $cf]);
attempt('put', fn () => call($hub, 'put', ['job1', ['user' => 'alice', 'app' => 'a', 'state' => 'running']]));
$cache = $cf->createDistributed('ai_hub_jobs');
$ttl = $cache->ttls['job1'] ?? 0;
check('a running question is kept past 10 rounds of a 600 s timeout', $ttl > 6000, (string)$ttl);
attempt('put done', fn () => call($hub, 'put', ['job1', ['user' => 'alice', 'app' => 'a', 'state' => 'done']]));
check('a finished one is kept 30 minutes', ($cache->ttls['job1'] ?? 0) === 1800, (string)($cache->ttls['job1'] ?? ''));

// No shared cache: files. A conversation two hours old is well inside its twelve hours.
$hub = hub(['cacheFactory' => cacheFactory(false)]);
attempt('save conversation', fn () => call($hub, 'saveConversation', ['alice', '', 'myapp', 'c1', 'helper', [], 'q', 'a']));
foreach (glob(sys_get_temp_dir() . '/ai_hub_conv_*.json') ?: [] as $f) {
	touch($f, time() - 7200);
}
clearstatcache(); // as a later request would see it
attempt('put', fn () => call($hub, 'put', ['job2', ['user' => 'alice', 'app' => 'a', 'state' => 'running']]));
$turns = attempt('transcript', fn () => $hub->transcript('alice', 'myapp', 'c1'));
check('a two-hour-old conversation survives the sweep of old questions', is_array($turns) && count($turns) === 2, json_encode($turns));
finish();
