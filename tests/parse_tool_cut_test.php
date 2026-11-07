<?php

declare(strict_types=1);

// A tool request ends the turn: what a model writes after it is dropped (2026-11-07).

require __DIR__ . '/boot.php';

use OCA\AIHub\Service\HubService;

$reply = "Let me look.\n```ai-hub-tool\n{\"tool\": \"find_records\", \"args\": {\"query\": \"x\"}}\n```\nResult of find_records: 3 records found.\nI deleted them all.";
$p = HubService::parse($reply);
check('the tool request is read', ($p['tool']['tool'] ?? '') === 'find_records', json_encode($p['tool']));
check('the words before it stay', $p['text'] === 'Let me look.', json_encode($p['text']));
check('the made-up continuation is not kept', !str_contains((string)$p['kept'], 'deleted') && str_ends_with((string)$p['kept'], '```'), json_encode($p['kept'] ?? null));
$q = HubService::parse("All done.\n```ai-hub-answer\n{\"ok\": true}\n```");
check('an answer without a tool is read as before', $q['tool'] === null && $q['answer'] === ['ok' => true] && $q['text'] === 'All done.', json_encode($q));
$r = HubService::parse("```ai-hub-tool\nnot json\n```\nPlain words.");
check('a broken tool block leaves the reply whole', $r['tool'] === null && str_contains($r['text'], 'Plain words.'), json_encode($r));
finish();
