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
		['name' => 'tool#setApp', 'url' => '/tools/app', 'verb' => 'POST'],
		// the request a question is handed to, signed with the hub's own secret
		['name' => 'process#answer', 'url' => '/process', 'verb' => 'POST'],
	],
	'ocs' => [
		['name' => 'api#status', 'url' => '/api/v1/status', 'verb' => 'GET'],
		['name' => 'api#ask', 'url' => '/api/v1/ask', 'verb' => 'POST'],
		['name' => 'api#result', 'url' => '/api/v1/result/{id}', 'verb' => 'GET'],
	],
];
