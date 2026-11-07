<?php
/**
 * The last 40 turns handed to the model begin with the person's: cut at 40 a conversation
 * that alternates and ends with a question began with an answer.
 */
declare(strict_types=1);
require __DIR__ . '/boot.php';

use OCA\AIHub\Service\HubService;

function alternating(int $n): array {
	$out = [];
	for ($i = 0; $i < $n; $i++) {
		$out[] = ['role' => $i % 2 === 0 ? 'user' : 'assistant', 'text' => 't' . $i];
	}
	return $out;
}

// History sent by the caller: 41 turns, user first and last.
$turns = attempt('cleanTurns', fn () => call(HubService::class, 'cleanTurns', [alternating(41)]));
if ($turns !== null) {
	check('caller history of 41 turns: begins with the person', ($turns[0]['role'] ?? '') === 'user', $turns[0]['role'] ?? 'none');
	check('...and still ends with the question', end($turns)['text'] === 't40');
}

// A remembered conversation of 20 exchanges, then one more question.
$engine = fakeEngine();
$hub = hub(['engines' => engines($engine)]);
attempt('save', fn () => call($hub, 'saveConversation', ['alice', '', 'myapp', 'c1', 'helper', alternating(38), 't38', 't39']));
$spec = ['system' => '', 'tools' => [], 'answer' => null, 'search' => false, 'language' => null, 'allow' => null, 'ocs' => false, 'transcript' => null];
$out = attempt('converse', fn () => call($hub, 'converse', ['alice', 'myapp', $spec, [
	'messages' => [['role' => 'user', 'text' => 'next']], 'context' => '', 'search' => false,
	'conversation' => 'c1', 'login' => '', 'scenario' => 'helper', 'images' => [],
]]));
if ($out !== null) {
	$history = $engine->calls[0]['history'] ?? [];
	check('remembered conversation past 20 exchanges: the model gets history beginning with the person', ($history[0]['role'] ?? '') === 'user', $history[0]['role'] ?? 'none');
	check('...and the question is the new one', ($engine->calls[0]['message'] ?? '') === 'next');
}
finish();
