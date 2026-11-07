<?php
/**
 * What every test here starts from: Nextcloud's own classes, read from disk (no
 * database, no config.php, no network, no AI provider), AI-Hub's classes, and a few helpers.
 *
 *   NC_ROOT     the Nextcloud to borrow classes from (default /var/www/nextcloud)
 *   AIHUB_LIB   the lib/ folder under test (default this app's own); pointing it at a
 *               copy of an older lib/ is how a test is shown to fail there
 *
 * Each test prints PASS/FAIL lines and ends with "ALL PASS" or "N FAILED".
 *   php tests/<name>_test.php
 *   AIHUB_LIB=/path/to/old/lib php tests/<name>_test.php
 */
declare(strict_types=1);

// Files the hub falls back on go to a folder of the test's own, never the shared /tmp.
// sys_temp_dir can only be set on the command line, so the test runs itself again with it.
if (getenv('AH_TMP') === false) {
	$tmp = (getenv('TMPDIR') ?: '/tmp') . '/aihub_test_' . getmypid();
	@mkdir($tmp, 0700, true);
	putenv('AH_TMP=' . $tmp);
	passthru(escapeshellarg(PHP_BINARY) . ' -d sys_temp_dir=' . escapeshellarg($tmp) . ' ' . escapeshellarg($_SERVER['SCRIPT_FILENAME']), $code);
	foreach (glob($tmp . '/{,.}*', GLOB_BRACE) ?: [] as $f) {
		if (is_file($f)) {
			@unlink($f);
		}
	}
	@rmdir($tmp);
	exit($code);
}
$tmp = getenv('AH_TMP');
if (sys_get_temp_dir() !== $tmp) {
	fwrite(STDERR, "the test's own temporary folder could not be set\n");
	exit(2);
}
define('AH_TMP', $tmp);

$nc = getenv('NC_ROOT') ?: '/var/www/nextcloud';
require $nc . '/3rdparty/autoload.php';
require $nc . '/lib/composer/autoload.php';
define('AH_LIB', rtrim(getenv('AIHUB_LIB') ?: __DIR__ . '/../lib', '/'));
spl_autoload_register(static function (string $class): void {
	$prefix = 'OCA\\AIHub\\';
	if (str_starts_with($class, $prefix)) {
		$file = AH_LIB . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
		if (is_file($file)) {
			require $file;
		}
	}
});
set_error_handler(static fn (int $no) => $no === E_USER_DEPRECATED || $no === E_DEPRECATED, E_USER_DEPRECATED | E_DEPRECATED);

$GLOBALS['ah_fail'] = 0;

function check(string $name, bool $ok, string $got = ''): void {
	echo ($ok ? 'PASS' : 'FAIL') . '  ' . $name . ($ok || $got === '' ? '' : '  (' . $got . ')') . "\n";
	if (!$ok) {
		$GLOBALS['ah_fail']++;
	}
}

function finish(): never {
	$n = $GLOBALS['ah_fail'];
	echo $n === 0 ? "\nALL PASS\n" : "\n$n FAILED\n";
	exit($n === 0 ? 0 : 1);
}

/** Run $fn; a crash in it is a failure of the test named, not the end of the run. */
function attempt(string $name, callable $fn): mixed {
	try {
		return $fn();
	} catch (\Throwable $e) {
		check($name, false, get_class($e) . ': ' . $e->getMessage());
		return null;
	}
}

/** An object of $class built without its constructor, with whichever of $props the class has. */
function build(string $class, array $props = []): object {
	$rc = new ReflectionClass($class);
	$obj = $rc->newInstanceWithoutConstructor();
	foreach ($props as $name => $value) {
		poke($obj, $name, $value, false);
	}
	return $obj;
}

/** Set a property that may be private to a parent class. */
function poke(object $obj, string $name, mixed $value, bool $must = true): void {
	for ($c = new ReflectionClass($obj); $c !== false; $c = $c->getParentClass()) {
		if ($c->hasProperty($name)) {
			$c->getProperty($name)->setValue($obj, $value);
			return;
		}
	}
	if ($must) {
		throw new LogicException('no property ' . $name);
	}
}

/** Call a method that may be private. */
function call(object|string $obj, string $method, array $args = []): mixed {
	$m = new ReflectionMethod($obj, $method);
	return $m->invokeArgs(is_object($obj) ? $obj : null, $args);
}

/**
 * An object of $class (an interface: every method; a class: built without its constructor)
 * whose methods named in $over do what the closures there do. An interface's other methods
 * return empty defaults; a class's keep their own code.
 */
function stub(string $class, array $over = []): object {
	static $n = 0;
	$n++;
	$r = new ReflectionClass($class);
	$cls = 'AhStub' . $n;
	$code = ($r->isInterface() ? "class $cls implements \\$class" : "class $cls extends \\$class") . ' { public array $__o = []; ';
	foreach ($r->getMethods() as $m) {
		$name = $m->getName();
		if ($m->isConstructor() || $m->isStatic() || $m->isPrivate() || $m->isFinal()) {
			continue;
		}
		if (!$r->isInterface() && !$m->isAbstract() && !array_key_exists($name, $over)) {
			continue;
		}
		$params = [];
		foreach ($m->getParameters() as $p) {
			$s = $p->hasType() ? stubType($p->getType()) . ' ' : '';
			$s .= ($p->isPassedByReference() ? '&' : '') . ($p->isVariadic() ? '...' : '') . '$' . $p->getName();
			if ($p->isDefaultValueAvailable()) {
				$s .= ' = ' . var_export($p->getDefaultValue(), true);
			} elseif ($p->isOptional() && !$p->isVariadic()) {
				$s .= ' = null';
			}
			$params[] = $s;
		}
		$rt = $m->hasReturnType() ? (string)$m->getReturnType() : '';
		$call = "(\$this->__o['$name'])(...func_get_args())";
		if ($rt === 'void') {
			$body = "if (isset(\$this->__o['$name'])) { $call; } return;";
		} else {
			$body = "if (isset(\$this->__o['$name'])) { return $call; }";
			$body .= match (true) {
				$rt === 'bool' => ' return false;',
				$rt === 'string' => " return '';",
				$rt === 'int' => ' return 0;',
				$rt === 'array' => ' return [];',
				$rt !== '' && $rt[0] !== '?' && !str_contains($rt, 'null') && $rt !== 'mixed' => " throw new \\LogicException('stub: $name');",
				default => ' return null;',
			};
		}
		$ret = $m->hasReturnType() ? ': ' . stubType($m->getReturnType()) : '';
		$vis = $m->isProtected() ? 'protected' : 'public';
		$code .= "$vis function $name(" . implode(', ', $params) . ")$ret { $body } ";
	}
	$code .= '}';
	eval($code);
	$o = $r->isInterface() ? new $cls() : (new ReflectionClass($cls))->newInstanceWithoutConstructor();
	$o->__o = $over;
	return $o;
}

function stubType(ReflectionType $t): string {
	if ($t instanceof ReflectionNamedType) {
		$name = $t->getName();
		$q = ($t->allowsNull() && $name !== 'mixed' && $name !== 'null') ? '?' : '';
		return $q . ($t->isBuiltin() || $name === 'static' || $name === 'self' ? $name : '\\' . $name);
	}
	if ($t instanceof ReflectionUnionType) {
		return implode('|', array_map(static fn ($x) => $x instanceof ReflectionNamedType
			? ($x->isBuiltin() ? $x->getName() : '\\' . $x->getName())
			: '(' . stubType($x) . ')', $t->getTypes()));
	}
	return (string)$t;
}

/**
 * The hub's settings, as a test wants them: the defaults of an API connection to Claude
 * with a key, no allowlist, no limits -- changed by $o (method name => value or closure).
 */
function config(array $o = []): OCA\AIHub\Service\ConfigService {
	$values = $o + [
		'getProvider' => 'claude',
		'getMode' => 'api',
		'getApiKey' => 'test-key',
		'getModel' => 'claude-test',
		'getEffort' => '',
		'getAppEngine' => ['provider' => 'claude', 'mode' => 'api', 'model' => 'claude-test', 'own' => false],
		'getAppPrompt' => '',
		'isAllowlistEnabled' => false,
		'getAllowedUsers' => [],
		'getAllowedApps' => [],
		'getRatePerMinute' => 0,
		'getMaxParallelPerUser' => 0,
		'getMaxParallelTotal' => 0,
		'getRequestTimeout' => 180,
		'getCliPath' => '',
		'getCliHome' => '',
		'getUserTools' => '',
		'noteAppSeen' => null,
	];
	$over = [];
	foreach ($values as $name => $v) {
		$over[$name] = $v instanceof Closure ? $v : static fn () => $v;
	}
	return stub(OCA\AIHub\Service\ConfigService::class, $over);
}

/**
 * A shared memory cache in this process that remembers the time-to-live each key was
 * last set with (its ->ttls), as Redis would keep it.
 */
function memcache(): OCP\IMemcache {
	$cache = new class('') extends OC\Memcache\ArrayCache {
		public array $ttls = [];
		public function set($key, $value, $ttl = 0) {
			$this->ttls[$key] = $ttl;
			return parent::set($key, $value, $ttl);
		}
		public function add($key, $value, $ttl = 0) {
			$this->ttls[$key] ??= $ttl;
			return parent::add($key, $value, $ttl);
		}
	};
	return $cache;
}

/** A cache factory handing out one cache per prefix; none at all when $available is false. */
function cacheFactory(bool $available = true): OCP\ICacheFactory {
	$caches = [];
	$get = static function (string $prefix = '') use (&$caches, $available) {
		if (!$available) {
			return new OC\Memcache\NullCache($prefix);
		}
		if (!isset($caches[$prefix])) {
			$caches[$prefix] = memcache();
		}
		return $caches[$prefix];
	};
	return stub(OCP\ICacheFactory::class, [
		'isAvailable' => static fn () => $available,
		'createDistributed' => $get,
		'createLocal' => $get,
		'createLocking' => $get,
	]);
}

/** An engine that answers $reply and keeps what it was handed in its $calls. */
function fakeEngine(string $reply = 'fine'): OCA\AIHub\Engine\IEngine {
	$engine = new class implements OCA\AIHub\Engine\IEngine {
		public array $calls = [];
		public string $reply = '';
		public function getName(): string {
			return 'fake';
		}
		public function run(array $history, string $message, string $systemPrompt, bool $elevated = false, array $options = []): OCA\AIHub\Engine\TurnResult {
			$this->calls[] = ['history' => $history, 'message' => $message, 'system' => $systemPrompt];
			return OCA\AIHub\Engine\TurnResult::ok($this->reply);
		}
		public function listModels(): array {
			return [];
		}
		public function useModel(string $model): void {
		}
	};
	$engine->reply = $reply;
	return $engine;
}

/** The engine factory that hands out $engine for every app. */
function engines(OCA\AIHub\Engine\IEngine $engine): OCA\AIHub\Engine\EngineFactory {
	return stub(OCA\AIHub\Engine\EngineFactory::class, [
		'forApp' => static fn () => $engine,
		'get' => static fn () => $engine,
	]);
}

/** The hub, made of the parts given and harmless defaults for the rest. */
function hub(array $parts = []): OCA\AIHub\Service\HubService {
	$config = $parts['config'] ?? config();
	$cf = $parts['cacheFactory'] ?? cacheFactory();
	$parts += [
		'config' => $config,
		'engines' => engines(fakeEngine()),
		'async' => stub(OCA\AIHub\Service\AsyncService::class, ['handOff' => static fn () => true]),
		'limits' => build(OCA\AIHub\Service\LimitService::class, ['config' => $config, 'cacheFactory' => $cf]),
		'cacheFactory' => $cf,
		'random' => stub(OCP\Security\ISecureRandom::class, ['generate' => static fn (int $len) => substr(str_repeat(bin2hex(random_bytes(32)), 2), 0, $len)]),
		'logger' => new Psr\Log\NullLogger(),
		'session' => stub(OCP\ISession::class),
		'tokens' => stub(OCP\Authentication\Token\IProvider::class),
		'serverConfig' => stub(OCP\IConfig::class, ['getSystemValueString' => static fn ($k, $d = '') => $d, 'getUserValue' => static fn ($u, $a, $k, $d = '') => $d]),
		'transcripts' => build(OCA\AIHub\Service\TranscriptService::class),
	];
	return build(OCA\AIHub\Service\HubService::class, $parts);
}
