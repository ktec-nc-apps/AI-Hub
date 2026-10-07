<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Service;

use OCA\AIHub\AppInfo\Application;
use OCP\App\AppPathNotFoundException;
use OCP\App\IAppManager;

/**
 * Finds the apps that declare AI-Hub support.
 *
 * An app advertises it by shipping a small file, appinfo/ai-hub.json:
 *
 *   {"ai-hub": 1, "scenarios": ["assistant"]}
 *
 * "ai-hub" is the protocol version it was written for (required); "scenarios"
 * lists the scenario names it registers (optional). It is a file of its own, not
 * an element of info.xml, because the App Store's schema rejects unknown
 * elements there.
 *
 * Disabled apps count too: the whole point is to let the administrator pick an
 * app from a list before it has ever run. The scan reads a few small files per
 * request and keeps nothing.
 */
class MarkerService {
	/** Where an app puts its declaration, relative to the app folder. */
	public const MARKER = 'appinfo/ai-hub.json';

	public function __construct(
		private IAppManager $appManager,
	) {
	}

	/**
	 * Every installed app (enabled or not) that ships the marker, except AI-Hub itself.
	 *
	 * @return array<string, array{name: string, enabled: bool, scenarios: list<string>, version: int}> app id => what it declares, sorted by id
	 */
	public function compatibleApps(): array {
		$out = [];
		foreach ($this->appManager->getAllAppsInAppsFolders() as $id) {
			if ($id === Application::APP_ID || !preg_match('/^[a-z][a-z0-9_]{1,63}$/', $id)) {
				continue;
			}
			try {
				$path = $this->appManager->getAppPath($id);
			} catch (AppPathNotFoundException) {
				continue;
			}
			$marker = self::readMarker($path . '/' . self::MARKER);
			if ($marker === null) {
				continue;
			}
			$info = $this->appManager->getAppInfo($id) ?? [];
			$name = $info['name'] ?? '';
			$out[$id] = [
				'name' => is_string($name) && $name !== '' ? $name : $id,
				'enabled' => $this->appManager->isEnabledForAnyone($id),
				'scenarios' => $marker['scenarios'],
				'version' => $marker['version'],
			];
		}
		ksort($out);
		return $out;
	}

	/**
	 * Parse one marker file. Null when it is missing or not a declaration we
	 * understand (no object, no numeric "ai-hub" key).
	 *
	 * @return array{version: int, scenarios: list<string>}|null
	 */
	private static function readMarker(string $file): ?array {
		if (!is_file($file) || !is_readable($file)) {
			return null;
		}
		$raw = @file_get_contents($file, false, null, 0, 65536);
		if ($raw === false) {
			return null;
		}
		$data = json_decode($raw, true);
		if (!is_array($data) || !isset($data['ai-hub']) || !is_numeric($data['ai-hub'])) {
			return null;
		}
		$scenarios = [];
		foreach (is_array($data['scenarios'] ?? null) ? $data['scenarios'] : [] as $scenario) {
			if (is_string($scenario) && preg_match('/^[a-z][a-z0-9_-]{0,63}$/i', $scenario)) {
				$scenarios[] = $scenario;
			}
		}
		return ['version' => (int)$data['ai-hub'], 'scenarios' => array_values(array_unique($scenarios))];
	}
}
