<?php
/**
 * The effort set in the admin settings reaches the command line tool, and the tool's
 * "OAuth session expired" is taken as a sign-in failure, not as an answer (2026-11-07).
 */
declare(strict_types=1);
require __DIR__ . '/boot.php';

use OCA\AIHub\Engine\CliEngine;
use OCA\AIHub\Engine\TurnResult;

// A stand-in for the tool that answers with its own arguments.
$bin = AH_TMP . '/fake-claude-args';
file_put_contents($bin, "#!/bin/sh\ncat >/dev/null\necho \"\$*\"\n");
chmod($bin, 0700);
$make = fn (array $o, string $b) => build(CliEngine::class, [
	'config' => config($o + ['getCliPath' => $b, 'getRequestTimeout' => 30]),
	'tempManager' => stub(OCP\ITempManager::class, ['getTemporaryFolder' => static fn () => AH_TMP]),
	'logger' => new Psr\Log\NullLogger(),
	'provider' => 'claude',
]);

$r = attempt('effort set', fn () => $make(['getEffort' => 'xhigh'], $bin)->run([], 'q', 'sys', false, ['search' => false]));
if ($r !== null) {
	check('an effort set in the settings goes to the tool as --effort', str_contains($r->output, '--effort xhigh'), $r->output);
}
$r = attempt('effort not set', fn () => $make(['getEffort' => ''], $bin)->run([], 'q', 'sys', false, ['search' => false]));
if ($r !== null) {
	check('no effort set: no --effort at all', !str_contains($r->output, '--effort'), $r->output);
}

// A stand-in for a tool whose sign-in has run out (what Claude Code printed on 2026-11-07).
$expired = AH_TMP . '/fake-claude-expired';
file_put_contents($expired, "#!/bin/sh\ncat >/dev/null\necho 'Failed to authenticate: OAuth session expired and could not be refreshed'\nexit 1\n");
chmod($expired, 0700);
$r = attempt('expired sign-in', fn () => $make([], $expired)->run([], 'q', 'sys', false, ['search' => false]));
if ($r !== null) {
	check('an expired sign-in is a sign-in failure, not an answer', $r->kind === TurnResult::KIND_AUTH_ERROR, $r->kind . ' ' . $r->output);
}
finish();
