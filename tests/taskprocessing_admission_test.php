<?php
/**
 * Nextcloud's own Task Processing (the Assistant) asks on the same terms as the apps:
 * the allowlist and the limits. It once ran past all of them. The allowed apps do not
 * apply to it: a list made for the apps shut the Assistant out (owner, 2026-11-06).
 */
declare(strict_types=1);
require __DIR__ . '/boot.php';

use OCA\AIHub\TaskProcessing\TextToTextChatProvider;
use OCA\AIHub\TaskProcessing\TextToTextProvider;

function provider(string $class, array $configValues, ?object &$engine = null): object {
	$config = config($configValues);
	$engine = fakeEngine();
	$engines = engines($engine);
	$hub = hub(['config' => $config, 'engines' => $engines]);
	return build($class, ['config' => $config, 'engines' => $engines, 'hub' => $hub]);
}

/** '' when the task ran, else the message it was refused with. */
function runTask(object $p, ?string $uid): string {
	try {
		$p->process($uid, ['input' => 'hello', 'history' => []], static fn () => true);
		return '';
	} catch (\RuntimeException $e) {
		return $e->getMessage() === '' ? 'refused' : $e->getMessage();
	}
}

foreach ([TextToTextProvider::class, TextToTextChatProvider::class] as $class) {
	$short = substr($class, strrpos($class, '\\') + 1);

	$p = provider($class, ['isAllowlistEnabled' => true, 'getAllowedUsers' => ['alice']], $engine);
	$got = runTask($p, 'mallory');
	check("$short: a user not on the allowlist is refused", $got !== '' && $engine->calls === [], $got === '' ? 'answered' : $got);
	check("$short: a user on the allowlist is answered", runTask($p, 'alice') === '');

	$p = provider($class, ['getAllowedApps' => ['editbase']], $engine);
	check("$short: allowed apps that leave out taskprocessing do not shut the Assistant out", runTask($p, 'alice') === '');
	$p = provider($class, ['isAllowlistEnabled' => true, 'getAllowedUsers' => ['alice'], 'getAllowedApps' => ['editbase']]);
	check("$short: with allowed apps set, a user not on the allowlist is still refused", runTask($p, 'mallory') !== '');
	$p = provider($class, ['getAllowedApps' => ['editbase', 'taskprocessing']]);
	check("$short: with taskprocessing among the allowed apps, answered", runTask($p, 'alice') === '');

	$p = provider($class, ['getRatePerMinute' => 1]);
	runTask($p, 'alice');
	$got = runTask($p, 'alice');
	check("$short: the second question in a minute past a limit of one is refused", $got !== '', 'answered');

	// The slot taken for a question is given back when it is done.
	$p = provider($class, ['getMaxParallelPerUser' => 1]);
	$a = runTask($p, 'alice');
	$b = runTask($p, 'alice');
	check("$short: one at a time: two questions one after the other both answered", $a === '' && $b === '', $a . '|' . $b);
}
finish();
