<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

return [
	'routes' => [
		// the administrator's model list and connection test
		['name' => 'tool#models', 'url' => '/tools/models', 'verb' => 'GET'],
		['name' => 'tool#test', 'url' => '/tools/test', 'verb' => 'POST'],
		['name' => 'tool#setModel', 'url' => '/tools/model', 'verb' => 'POST'],
		// the apps that connect, each with its own choice of AI and model
		['name' => 'tool#apps', 'url' => '/tools/apps', 'verb' => 'GET'],
		// the apps that declare AI-Hub support (appinfo/ai-hub.json) and are not listed yet
		['name' => 'tool#compatible', 'url' => '/tools/compatible', 'verb' => 'GET'],
		['name' => 'tool#setApp', 'url' => '/tools/app', 'verb' => 'POST'],
		['name' => 'tool#addApp', 'url' => '/tools/app/add', 'verb' => 'POST'],
		['name' => 'tool#deleteApp', 'url' => '/tools/app/delete', 'verb' => 'POST'],
		// the request a question is handed to, signed with the hub's own secret
		['name' => 'process#answer', 'url' => '/process', 'verb' => 'POST'],
		// a remembered conversation, for the apps' pages: read back, saved, summed up, dropped
		['name' => 'conversation#show', 'url' => '/conversation', 'verb' => 'GET'],
		['name' => 'conversation#save', 'url' => '/conversation/save', 'verb' => 'POST'],
		['name' => 'conversation#summarize', 'url' => '/conversation/summary', 'verb' => 'POST'],
		['name' => 'conversation#result', 'url' => '/conversation/result/{id}', 'verb' => 'GET'],
		['name' => 'conversation#forget', 'url' => '/conversation/forget', 'verb' => 'POST'],
	],
	'ocs' => [
		['name' => 'api#status', 'url' => '/api/v1/status', 'verb' => 'GET'],
		['name' => 'api#ask', 'url' => '/api/v1/ask', 'verb' => 'POST'],
		['name' => 'api#result', 'url' => '/api/v1/result/{id}', 'verb' => 'GET'],
		['name' => 'api#forget', 'url' => '/api/v1/forget', 'verb' => 'POST'],
	],
];
