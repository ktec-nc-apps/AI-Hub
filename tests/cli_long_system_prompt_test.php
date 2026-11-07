<?php
/**
 * A long system prompt (a document as context) on the command-line Claude: as one
 * argument it ran past Linux's 128 KiB limit and the tool never started.
 */
declare(strict_types=1);
require __DIR__ . '/boot.php';

use OCA\AIHub\Engine\CliEngine;

// A stand-in for the tool: reports how much it got on stdin and whether the marker
// of the system prompt was in its arguments or on stdin.
$bin = AH_TMP . '/fake-claude';
file_put_contents($bin, "#!/bin/sh\nin=\$(cat)\nargs=\"\$*\"\ncase \"\$args\" in *SYSMARK*) a=args;; *) a=noargs;; esac\ncase \"\$in\" in *SYSMARK*) s=stdin;; *) s=nostdin;; esac\necho \"\$a \$s\"\n");
chmod($bin, 0700);

$engine = build(CliEngine::class, [
	'config' => config(['getCliPath' => $bin, 'getRequestTimeout' => 30]),
	'tempManager' => stub(OCP\ITempManager::class, ['getTemporaryFolder' => static fn () => AH_TMP]),
	'logger' => new Psr\Log\NullLogger(),
	'provider' => 'claude',
]);

$long = 'SYSMARK ' . str_repeat('文書の中身です。', 20000); // about 480 KB
$r = attempt('long system prompt', fn () => $engine->run([], 'question', $long, false, ['search' => false]));
if ($r !== null) {
	check('a 480 KB system prompt: the tool starts and answers', $r->isOk(), $r->detail);
	check('...and the system prompt reaches it on stdin', trim($r->output) === 'noargs stdin', trim($r->output));
}

$r = attempt('short system prompt', fn () => $engine->run([], 'question', 'SYSMARK be brief', false, ['search' => false]));
if ($r !== null) {
	check('a short system prompt still goes as the argument', trim($r->output) === 'args nostdin', trim($r->output) . ' ' . $r->detail);
}
finish();
