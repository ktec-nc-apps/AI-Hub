<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 KTEC
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AIHub\Service;

use OCP\App\IAppManager;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IConfig;
use OCP\IURLGenerator;
use OCP\L10N\IFactory;

/**
 * A conversation with the AI, or the model's summary of one, written to the person's
 * Files as Markdown (the owner, 2026-10-06): AI-Hub/<app>/<date> <time> <the start of
 * the first question>.md, in the person's own language and time zone. A name already
 * taken gets " (2)", " (3)" and so on.
 */
class TranscriptService {
	/** The folder in the person's Files that the saved conversations go under. */
	public const FOLDER = 'AI-Hub';
	/** How much of the first question names the file. */
	private const NAME_CHARS = 30;
	/** How much of it heads the document. */
	private const TITLE_CHARS = 80;

	public function __construct(
		private IRootFolder $root,
		private IAppManager $apps,
		private IConfig $config,
		private IFactory $l10n,
		private IURLGenerator $urls,
	) {
	}

	/**
	 * The person's first question, as it reads: without the mark of the images that went
	 * with it, on one line.
	 *
	 * @param list<array{role: string, text: string}> $turns
	 */
	public static function firstQuestion(array $turns): string {
		foreach ($turns as $turn) {
			if ($turn['role'] !== 'user') {
				continue;
			}
			$text = trim((string)preg_replace('/^\[\d+ images?\]\s*/', '', $turn['text']));
			$text = trim((string)preg_replace('/\s+/u', ' ', $text));
			if ($text !== '') {
				return $text;
			}
		}
		return '';
	}

	/**
	 * @param list<array{role: string, text: string}> $turns
	 * @return array{name: string, path: string, fileId: int, url: string}
	 */
	public function saveConversation(string $userId, string $app, array $turns, string $lang): array {
		$l = $this->l10n->get('ai_hub', $lang);
		$first = self::firstQuestion($turns);
		$lines = ['# ' . $l->t('Conversation: %s', [$this->head($first, self::TITLE_CHARS, $l->t('Conversation'))]), ''];
		$lines = array_merge($lines, $this->about($userId, $app, $l), ['']);
		foreach ($turns as $turn) {
			$lines[] = '**' . ($turn['role'] === 'assistant' ? $l->t('AI') : $l->t('You')) . '**';
			$lines[] = '';
			$text = $turn['text'];
			if ($turn['role'] === 'user' && preg_match('/^\[(\d+) images?\]\s*/', $text, $m) === 1) {
				// the images themselves are not kept; their number is
				$text = $l->n('[%n image]', '[%n images]', (int)$m[1]) . (trim(substr($text, strlen($m[0]))) !== '' ? ' ' . trim(substr($text, strlen($m[0]))) : '');
			}
			$lines[] = trim($text);
			$lines[] = '';
		}
		return $this->write($userId, $app, $this->fileBase($userId, $first, $l), implode("\n", $lines));
	}

	/** @return array{name: string, path: string, fileId: int, url: string} */
	public function saveSummary(string $userId, string $app, string $first, string $summary, string $lang): array {
		$l = $this->l10n->get('ai_hub', $lang);
		$lines = ['# ' . $l->t('Summary: %s', [$this->head($first, self::TITLE_CHARS, $l->t('Conversation'))]), ''];
		$lines = array_merge($lines, $this->about($userId, $app, $l), ['', trim($summary), '']);
		return $this->write($userId, $app, $l->t('%s (summary)', [$this->fileBase($userId, $first, $l)]), implode("\n", $lines));
	}

	/** The lines under the title: when, and from which app. */
	/** @return list<string> */
	private function about(string $userId, string $app, \OCP\IL10N $l): array {
		return [
			'- ' . $l->t('Date') . ': ' . $this->now($userId)->format('Y-m-d H:i'),
			'- ' . $l->t('App') . ': ' . $this->appName($app),
		];
	}

	/** "2026-10-06 1945 How do I put the paper in land" -- the name before the extension. */
	private function fileBase(string $userId, string $first, \OCP\IL10N $l): string {
		$head = rtrim(mb_substr(trim($first), 0, self::NAME_CHARS));
		return $this->now($userId)->format('Y-m-d Hi') . ' ' . ($head !== '' ? $head : $l->t('Conversation'));
	}

	private function head(string $text, int $chars, string $empty): string {
		$text = trim($text);
		if ($text === '') {
			return $empty;
		}
		return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars)) . '…' : $text;
	}

	/**
	 * Write the file into AI-Hub/<app>/ in the person's Files, made if it is not there.
	 *
	 * @return array{name: string, path: string, fileId: int, url: string}
	 */
	private function write(string $userId, string $app, string $base, string $content): array {
		$home = $this->root->getUserFolder($userId);
		$folder = $this->folder($this->folder($home, self::FOLDER), self::safe($this->appName($app), $app));
		$base = self::safe($base, 'conversation');
		$name = $base . '.md';
		for ($n = 2; $folder->nodeExists($name); $n += 1) {
			$name = $base . ' (' . $n . ').md';
		}
		$file = $folder->newFile($name, $content);
		return [
			'name' => $name,
			'path' => self::FOLDER . '/' . $folder->getName() . '/' . $name,
			'fileId' => (int)$file->getId(),
			'url' => $this->urls->linkToRoute('files.view.showFile', ['fileid' => (int)$file->getId()]),
		];
	}

	private function folder(Folder $parent, string $name): Folder {
		if ($parent->nodeExists($name)) {
			$node = $parent->get($name);
			if ($node instanceof Folder) {
				return $node;
			}
			throw new \RuntimeException($name . ' is there and is not a folder');
		}
		return $parent->newFolder($name);
	}

	/** The app's own name ("EditBase"), or its id when it has none. */
	private function appName(string $app): string {
		try {
			$info = $this->apps->getAppInfo($app);
			$name = is_array($info) ? ($info['name'] ?? '') : '';
			if (is_array($name)) {
				$name = (string)(reset($name) ?: '');
			}
			return is_string($name) && trim($name) !== '' ? trim($name) : $app;
		} catch (\Throwable $e) {
			return $app;
		}
	}

	/** Now, in the person's time zone (the server's when they have not set one). */
	private function now(string $userId): \DateTimeImmutable {
		$zone = $userId !== '' ? $this->config->getUserValue($userId, 'core', 'timezone', '') : '';
		if ($zone === '') {
			$zone = $this->config->getSystemValueString('default_timezone', date_default_timezone_get());
		}
		try {
			return new \DateTimeImmutable('now', new \DateTimeZone($zone));
		} catch (\Throwable $e) {
			return new \DateTimeImmutable('now');
		}
	}

	/**
	 * A file or folder name from words: what a name cannot hold (/ \ : * ? " < > | and
	 * control characters) becomes "_", runs of space become one, and it may not end in
	 * a dot or a space. Nothing left gives $empty.
	 */
	public static function safe(string $name, string $empty): string {
		$name = (string)preg_replace('/[\/\\\\:*?"<>|\x00-\x1F\x7F]/u', '_', $name);
		$name = trim((string)preg_replace('/\s+/u', ' ', $name));
		$name = rtrim($name, '. ');
		if ($name === '' || $name === '.' || $name === '..') {
			return $empty;
		}
		return $name;
	}
}
