<?php

declare(strict_types=1);

// The per-app permissions and the record of what the tools did (owner, 2026-11-07).

require __DIR__ . '/boot.php';

use OCA\AIHub\Service\HubService;

$all = ['read' => true, 'search' => true, 'create' => true, 'update' => true, 'delete' => true, 'admin' => false];
$noDelete = ['delete' => false] + $all;
check('a read tool runs when reading is allowed', HubService::kindAllowed('read', $all));
check('a delete tool does not run when deleting is not allowed', !HubService::kindAllowed('delete', $noDelete));
check('an update tool still runs then', HubService::kindAllowed('update', $noDelete));
check('a tool that does not say what it does needs every kind of change', !HubService::kindAllowed('write', $noDelete) && HubService::kindAllowed('write', $all));
check('the administrators\' operations are off by default', !HubService::kindAllowed('admin', $all));

$a = HubService::action('delete_records', ['label' => 'Delete records', 'kind' => 'delete'], '{"deleted":2,"_report":"Deleted 2: A, B"}', null);
check('a tool\'s own report is the record', $a['ok'] && $a['report'] === 'Deleted 2: A, B' && $a['kind'] === 'delete', json_encode($a));
$b = HubService::action('update_records', ['label' => 'Change records', 'kind' => 'update'], ['error' => 'locked'], null);
check('an error in the result is a failure', !$b['ok'] && $b['report'] === 'locked', json_encode($b));
$c = HubService::action('create_records', ['kind' => 'create'], null, 'boom');
check('a tool that threw is a failure with its message', !$c['ok'] && $c['report'] === 'boom', json_encode($c));
finish();
