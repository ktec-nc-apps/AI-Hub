<?php
/**
 * Summing up a conversation holds to the app's own rule on who may ask: it runs on the
 * app's AI connection. It once checked only the hub's allowlist.
 */
declare(strict_types=1);
require __DIR__ . '/boot.php';

$allowed = ['alice' => true];
$hub = hub();
$hub->registerScenario('myapp', 'helper', ['system' => 'x', 'allow' => static fn (string $uid) => $allowed[$uid] ?? false]);
foreach (['alice', 'bob'] as $uid) {
	call($hub, 'saveConversation', [$uid, '', 'myapp', 'c1', 'helper', [], 'question', 'answer']);
}
$a = attempt('alice', fn () => $hub->summarize('alice', 'myapp', 'c1'));
check('a person the app allows gets a summary started', isset($a['id']), json_encode($a));
$b = attempt('bob', fn () => $hub->summarize('bob', 'myapp', 'c1'));
check('a person the app no longer allows is refused', ($b['error'] ?? '') === 'user-not-allowed', json_encode($b));

$hub2 = hub();
call($hub2, 'saveConversation', ['alice', '', 'gone', 'c1', 'helper', [], 'question', 'answer']);
$c = attempt('gone', fn () => $hub2->summarize('alice', 'gone', 'c1'));
check('the conversation of an app no longer registered is not summed up', isset($c['error']), json_encode($c));
finish();
