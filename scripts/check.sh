#!/usr/bin/env bash
# Runs every gate this module is held to, in order, stopping at the first
# failure. CLAUDE.md says what each means and when each lane is used.
# Works inside or outside ddev. scripts/check.sh --help lists the lanes.

set -uo pipefail

usage() {
  cat <<'EOF'
Usage: scripts/check.sh [--fast] [--help]

Lanes:
  (default)  The full lane: every test class through core's
             run-tests.sh, classes in parallel, each with its own database
             prefix and site directory: unit, kernel and functional
             classes first, then the browser classes; then phpcs,
             phpstan and cspell over the whole module. Required before
             handing work back.
  --fast     The fast lane: unit and kernel tests through phpunit, one
             process per class, SURFACE_CHECK_CONCURRENCY at once,
             previous defects first, with the result cache; then phpcs,
             phpstan and cspell on the files changed since HEAD (tracked
             and untracked). phpstan covers the whole module when no PHP
             file changed or a .neon or phpstan-*.php file did. For
             iterating; it does not replace the full lane.

Each lane prints the wall time of every gate and ends with a PASS line
only when no test errored or failed and every gate passed.

Variables:
  SURFACE_CHECK_FAST=1         Same as --fast.
  SURFACE_CHECK_CONCURRENCY=N  Unit, kernel and functional classes
                                    run at once in the full lane, and
                                    unit and kernel classes in the fast
                                    lane (default 8).
  SURFACE_CHECK_BROWSER_CONCURRENCY=N
                                    Browser classes run at once in the
                                    full lane, after the rest (default 4).
EOF
}

FAST="${SURFACE_CHECK_FAST:-0}"
CONCURRENCY="${SURFACE_CHECK_CONCURRENCY:-8}"
BROWSER_CONCURRENCY="${SURFACE_CHECK_BROWSER_CONCURRENCY:-4}"
for arg in "$@"; do
  case "$arg" in
    --fast) FAST=1 ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $arg" >&2; usage >&2; exit 2 ;;
  esac
done
[ "$FAST" = "0" ] || FAST=1
for value in "$CONCURRENCY" "$BROWSER_CONCURRENCY"; do
  case "$value" in
    ''|*[!0-9]*|0) echo "SURFACE_CHECK_CONCURRENCY and SURFACE_CHECK_BROWSER_CONCURRENCY are positive integers, not '$value'" >&2; exit 2 ;;
  esac
done

MODULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB="$(cd "$MODULE/../../.." && pwd)"
ROOT="$(dirname "$WEB")"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

# Wall time per gate, printed as each gate ends and again at the end.
TIMES=""
GATE=""
GATE_START=0
fmt_time() { printf '%dm%02ds' $(($1 / 60)) $(($1 % 60)); }
gate_start() { GATE="$1"; GATE_START=$SECONDS; echo "== $1 =="; }
gate_end() {
  local spent=$((SECONDS - GATE_START))
  TIMES="$TIMES${TIMES:+, }$GATE $(fmt_time "$spent")"
  echo "$GATE: wall time $(fmt_time "$spent")"
}
fail() {
  echo
  echo "FAILED: $1"
  [ -z "$TIMES" ] || echo "Wall time of the gates that passed: $TIMES"
  exit 1
}

# Runs the script on stdin inside the web container: only the container can
# reach the test database and the browser. With Mutagen, the container sees
# a file saved on the host a moment later, so the sync is flushed first; a
# project without Mutagen refuses the flush, which is harmless.
in_container() {
  if [ "${IS_DDEV_PROJECT:-}" = "true" ]; then
    bash -s
  else
    (cd "$ROOT" && { ddev mutagen sync >/dev/null 2>&1 || true; } && ddev exec bash -s)
  fi
}

# The files changed since HEAD, tracked or not, that still exist; module
# relative, one per line.
changed_files() {
  { git -C "$MODULE" diff --name-only HEAD; git -C "$MODULE" ls-files --others --exclude-standard; } \
    | sort -u | while IFS= read -r file; do
      [ -f "$MODULE/$file" ] && printf '%s\n' "$file"
    done
}

CHANGED=""
if [ "$FAST" = "1" ]; then
  echo "FAST LANE: unit and kernel tests, then phpcs, phpstan and cspell on the files changed since HEAD."
  echo "The full lane (scripts/check.sh without --fast) is required before handing back."
  echo
fi

# 1. The suite.
gate_start phpunit
if [ "$FAST" = "1" ]; then
  # Unit and kernel tests, one phpunit process per class, CONCURRENCY at
  # once: one process for all of them takes 15 minutes, as long as three
  # full lanes, since every kernel test installs its modules. Each class
  # keeps its own result cache in the container's /tmp, so the caches
  # survive between runs, never land in core or in the module, and no two
  # processes write one file. Within a class the tests that failed last
  # time run first (--order-by=defects); across classes, a class that had
  # a defect last time first, then the slowest last time, so no long class
  # starts last. A class with no cache yet counts as the slowest.
  echo "Unit and kernel test classes, $CONCURRENCY at a time; a class's output is printed only when it has a problem."
  { echo "SHARDS=$CONCURRENCY"; cat <<'SH'; } | in_container > "$WORK/phpunit.txt" 2>&1
cd /var/www/html/web || exit 1
CACHE=/tmp/surface-phpunit-cache
OUT="$(mktemp -d)"
find modules/custom/surface/tests/src/Unit modules/custom/surface/tests/src/Kernel -name '*Test.php' | php -r '
  $rows = [];
  while (($file = fgets(STDIN)) !== FALSE) {
    $file = trim($file);
    $cache = json_decode((string) @file_get_contents($argv[1] . "/" . strtr($file, "/", "_") . "/test-results"), TRUE);
    $rows[$file] = isset($cache["times"]) ? (empty($cache["defects"]) ? 0 : 1E9) + array_sum($cache["times"]) : INF;
  }
  arsort($rows);
  echo implode("\n", array_keys($rows)), "\n";
' "$CACHE" > "$OUT/queue"
export SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL="${SIMPLETEST_BASE_URL:-http://web}"
xargs -P "$SHARDS" -I {} sh -c 'key="$(printf %s "$1" | tr / _)"; ../vendor/bin/phpunit -c core --order-by=defects --cache-result --cache-directory "$2/$key" "$1" > "$3/$key.txt" 2>&1' _ {} "$CACHE" "$OUT" < "$OUT/queue"
while IFS= read -r file; do
  echo "-- $file"
  cat "$OUT/$(printf %s "$file" | tr / _).txt"
done < "$OUT/queue"
rm -rf "$OUT"
SH
  # A passing run is no error and no failure at all, in every class. A
  # class's output is printed when it has a problem; phpunit's own lines
  # are summed into one.
  cat > "$WORK/classes.php" <<'PHP'
<?php
[, $output, $concurrency] = $argv;
$parts = preg_split('/^-- (\S+)$/m', file_get_contents($output), -1, PREG_SPLIT_DELIM_CAPTURE);
$before = trim(array_shift($parts));
$sum = ['Tests' => 0, 'Assertions' => 0];
$problems = 0;
for ($i = 0; $i < count($parts); $i += 2) {
  [$class, $text] = [$parts[$i], $parts[$i + 1]];
  $line = preg_match_all('/^(?:Tests:|OK) .*$/m', $text, $lines) ? end($lines[0]) : '';
  if ($line === '' || preg_match('/(Errors|Failures): [0-9]/', $line) || preg_match('/^(FAILURES|ERRORS)!/m', $text)) {
    $problems++;
    echo "== $class: ", $line === '' ? 'phpunit did not finish; no result line in its output' : $line, "\n", trim($text), "\n\n";
  }
  if (preg_match('/^OK \((\d+) tests?, (\d+) assertions?\)/', $line, $ok)) {
    $counts = ['Tests' => $ok[1], 'Assertions' => $ok[2]];
  }
  else {
    preg_match_all('/(\w+): (\d+)/', $line, $pairs, PREG_SET_ORDER);
    $counts = array_column($pairs, 2, 1);
  }
  foreach ($counts as $key => $count) {
    $sum[$key] = ($sum[$key] ?? 0) + (int) $count;
  }
}
if (!$parts) {
  echo $before, "\n";
}
$pairs = [];
foreach ($sum as $key => $count) {
  $pairs[] = "$key: $count";
}
printf("%s. Classes: %d, %s at a time.\n", implode(', ', $pairs), count($parts) / 2, $concurrency);
exit($problems || !$parts ? 1 : 0);
PHP
  SUMMARY="$(php "$WORK/classes.php" "$WORK/phpunit.txt" "$CONCURRENCY")"
  RESULT=$?
  printf '%s\n' "$SUMMARY"
  SUMMARY="$(printf '%s\n' "$SUMMARY" | tail -1)"
  [ "$RESULT" = "0" ] || fail "phpunit: an error, a failure or a class without a result is a regression ($SUMMARY)"
  echo "phpunit: $SUMMARY"
else
  # Every class in its own phpunit process, each with its own database
  # prefix and site directory, which functional and browser tests need:
  # unit, kernel and functional classes CONCURRENCY at a time, then the
  # browser classes BROWSER_CONCURRENCY at a time. Eight was the fastest
  # measured (CLAUDE.md); ddev's php-fpm serves eight requests at once. The
  # add-on's Chrome takes 12 sessions, but six tabs at once crash ("tab
  # crashed") on a Docker VM of 8 GB, so the browser classes run apart,
  # four at once.
  # SIMPLETEST_BASE_URL and MINK_DRIVER_ARGS_WEBDRIVER come from the
  # container's environment, which the ddev-selenium-standalone-chrome
  # add-on sets: the site at http://web, a name the browser container can
  # reach too. --suppress-deprecations keeps deprecations out of the
  # result, as with plain phpunit: without it run-tests.sh adds
  # --fail-on-deprecation to every class. The runner's own records go to a
  # throwaway SQLite file, kept between the two runs so test ids, and so
  # log names, do not repeat; each class's JUnit log goes to run-tests.sh's
  # working directory, emptied of old logs first, and is what the gate reads.
  in_container <<SH 2>&1 | tee "$WORK/phpunit.txt"
cd /var/www/html/web || exit 1
[ -n "\${MINK_DRIVER_ARGS_WEBDRIVER:-}" ] || { echo "MINK_DRIVER_ARGS_WEBDRIVER is not set: install the ddev-selenium-standalone-chrome add-on (CLAUDE.md)"; exit 1; }
mkdir -p sites/default/files/simpletest && rm -f sites/default/files/simpletest/phpunit-*.xml
RUNNER="\$(mktemp -d)"
run() {
  php core/scripts/run-tests.sh --non-html --suppress-deprecations \\
    --sqlite "\$RUNNER/runner.sqlite" --dburl mysql://db:db@db/db \\
    --url "\${SIMPLETEST_BASE_URL:-http://web}" --directory modules/custom/surface "\$@"
}
run --concurrency $CONCURRENCY --types PHPUnit-Unit,PHPUnit-Kernel,PHPUnit-Functional
FIRST=\$?
run --concurrency $BROWSER_CONCURRENCY --types PHPUnit-FunctionalJavascript --keep-results-table
SECOND=\$?
rm -rf "\$RUNNER"
exit \$(( FIRST > SECOND ? FIRST : SECOND ))
SH
  STATUS="${PIPESTATUS[0]}"

  # The gate. run-tests.sh's line per class counts passed tests, not
  # assertions, so the counts come from each class's JUnit log, checked
  # against the classes run-tests.sh said it would run. A class with no log
  # did not run, or died before PHPUnit could write one.
  cat > "$WORK/results.php" <<'PHP'
<?php
[, $output, $logs, $concurrency, $browser] = $argv;
$text = file_get_contents($output);
preg_match_all('/^Tests to be run:\n(.*?)\n\n/ms', $text, $lists);
preg_match_all('/^  - (\S+)$/m', implode("\n", $lists[1]), $matches);
$expected = $matches[1];
$totals = ['tests' => 0, 'assertions' => 0, 'errors' => 0, 'failures' => 0, 'skipped' => 0];
$seen = [];
$problems = [];
foreach (glob($logs . '/phpunit-*.xml') as $file) {
  $xml = @simplexml_load_file($file);
  if ($xml === FALSE) {
    $problems[] = 'UNREADABLE ' . $file;
    continue;
  }
  foreach ($xml->testsuite as $suite) {
    $seen[(string) $suite['name']] = TRUE;
    foreach (array_keys($totals) as $key) {
      $totals[$key] += (int) $suite[$key];
    }
    foreach ($suite->xpath('.//testcase[failure or error]') as $case) {
      $defect = $case->failure ?: $case->error;
      // The message opens with the test's name, printed already.
      $lines = array_slice(explode("\n", trim((string) $defect)), 1, 12);
      $problems[] = ($case->failure ? 'FAILURE ' : 'ERROR ') . $case['class'] . '::' . $case['name'] . "\n    " . implode("\n    ", $lines);
    }
  }
}
foreach (array_diff($expected, array_keys($seen)) as $class) {
  $problems[] = 'NO RESULT ' . $class . ': it did not run, or its process died before PHPUnit wrote a result';
}
foreach ($problems as $problem) {
  echo $problem, "\n";
}
printf("Tests: %d, Assertions: %d, Errors: %d, Failures: %d, Skipped: %d. Classes: %d of %d, %s at a time, browser classes %s.\n",
  $totals['tests'], $totals['assertions'], $totals['errors'], $totals['failures'], $totals['skipped'],
  count(array_intersect($expected, array_keys($seen))), count($expected), $concurrency, $browser);
exit($problems || !$expected ? 1 : 0);
PHP
  SUMMARY="$(php "$WORK/results.php" "$WORK/phpunit.txt" "$WEB/sites/default/files/simpletest" "$CONCURRENCY" "$BROWSER_CONCURRENCY")"
  RESULT=$?
  echo
  printf '%s\n' "$SUMMARY"
  SUMMARY="$(printf '%s\n' "$SUMMARY" | tail -1)"
  [ "$RESULT" = "0" ] || fail "phpunit: an error, a failure or a class without a result is a regression ($SUMMARY)"
  # run-tests.sh exits 1 for a failure, 2 for an error, 3 when a class's
  # process ended abnormally. An error outside any test (exit 2 with every
  # result clean) is still an error. Exit 1 with every result clean is a
  # PHPUnit warning or a risky test, which this gate has never failed on;
  # its classes are named.
  [ "$STATUS" -le 1 ] || fail "phpunit: run-tests.sh exited $STATUS: a class errored outside its tests or its process ended abnormally ($SUMMARY)"
  if [ "$STATUS" = "1" ]; then
    echo "NOTE: run-tests.sh exited 1 with no error and no failure in any result: a PHPUnit warning or a risky test. Classes with a nonzero exit:"
    grep -E 'exit code [0-9]' "$WORK/phpunit.txt" || true
  fi
  echo "phpunit: $SUMMARY"
fi
gate_end

if [ "$FAST" = "1" ]; then
  CHANGED="$(changed_files)"
  echo
  if [ -n "$CHANGED" ]; then
    echo "Changed since HEAD:"
    printf '%s\n' "$CHANGED" | sed 's/^/  /'
  else
    echo "Changed since HEAD: nothing."
  fi
fi

# 2. Coding standards. The stored installed_paths point inside the container.
# The module outgrew PHP's default memory limit, as phpstan did. The fast
# lane checks only the changed files phpcs.xml.dist would check.
gate_start phpcs
PHPCS_FILES=""
if [ "$FAST" = "1" ]; then
  PHPCS_FILES="$(printf '%s\n' "$CHANGED" | grep -E '^(src|modules|tests|scripts)/|^surface\.(api\.php|module|info\.yml|services\.yml)$' \
    | grep -E '\.(php|module|inc|install|info|yml|test|theme)$' | grep -v '/node_modules/')"
fi
if [ "$FAST" = "1" ] && [ -z "$PHPCS_FILES" ]; then
  echo "phpcs: no changed file in its scope; skipped."
else
  # PHPCS_FILES is one module-relative path per line; splitting it is meant.
  # shellcheck disable=SC2086
  (cd "$MODULE" && "$ROOT/vendor/bin/phpcs" -d memory_limit=1G --runtime-set installed_paths \
    "$ROOT/vendor/drupal/coder/coder_sniffer,$ROOT/vendor/slevomat/coding-standard" $PHPCS_FILES) \
    || fail "phpcs"
fi
gate_end

# 3. Static analysis, at the level the module's own config sets. The fast
# lane analyzes the changed PHP files alone, unless none changed or the
# configuration did.
gate_start phpstan
PHPSTAN_FILES=""
if [ "$FAST" = "1" ] && ! printf '%s\n' "$CHANGED" | grep -qE '\.neon$|(^|/)phpstan-[^/]*\.php$'; then
  PHPSTAN_FILES="$(printf '%s\n' "$CHANGED" | grep -E '\.(php|module|inc|install)$' \
    | grep -v -E '/node_modules/|^tests/fixtures/phpstan/' | sed "s#^#$MODULE/#")"
fi
[ "$FAST" = "0" ] || [ -n "$PHPSTAN_FILES" ] || echo "phpstan: no changed PHP file, or its configuration changed; analyzing the whole module."
# shellcheck disable=SC2086
(cd "$ROOT" && vendor/bin/phpstan analyse -c "$MODULE/phpstan.neon" \
  --no-progress --memory-limit=1G $PHPSTAN_FILES) || fail "phpstan"
gate_end

# 4. Spelling: core's dictionary plus this module's own project words.
gate_start cspell
cat > "$WORK/merge.php" <<'PHP'
<?php
[, $core, $module, $out] = $argv;
$config = json_decode(file_get_contents($core . '/.cspell.json'), TRUE);
$config['globRoot'] = $module;
// surface_react's built app and its lock file are generated, and its
// Node dependencies are not this module's to spell. The dated reviews are
// other readers' words, quoted as they wrote them (tool names, French).
// The contract baselines are generated from code cspell already reads,
// and an allowance in one names the path it accepted as it was, in the
// old spelling when the change was a rename. The OpenAPI 3.1 schema is the
// OpenAPI Initiative's file, copied unchanged.
$config['ignorePaths'] = ['.git/**', '**/node_modules/**', '.cspell-project-words.txt', 'modules/surface_react/dist/**', '**/package-lock.json', 'docs/reviews-*/**', 'tests/fixtures/contracts/**', 'tests/fixtures/openapi/oas-*.json'];
foreach ($config['dictionaryDefinitions'] as &$dictionary) {
  $dictionary['path'] = $core . '/' . ltrim($dictionary['path'], './');
}
$words = array_filter(array_map('trim', file($module . '/.cspell-project-words.txt')));
$config['words'] = array_values(array_merge($config['words'] ?? [], $words));
file_put_contents($out, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
PHP
php "$WORK/merge.php" "$WEB/core" "$MODULE" "$WORK/cspell.json" || fail "cspell config merge"
if [ "$FAST" = "1" ] && [ -z "$CHANGED" ]; then
  echo "cspell: no changed file; skipped."
elif [ "$FAST" = "1" ]; then
  # The ignorePaths above still apply to files named on the command line.
  # shellcheck disable=SC2086
  (cd "$MODULE" && npx --yes cspell@8 --config "$WORK/cspell.json" --no-progress --no-summary \
    --no-must-find-files $CHANGED) || fail "cspell"
else
  (cd "$MODULE" && npx --yes cspell@8 --config "$WORK/cspell.json" --no-progress --no-summary "**") \
    || fail "cspell"
fi
gate_end

echo
echo "Wall time: $TIMES, total $(fmt_time "$SECONDS")."
if [ "$FAST" = "1" ]; then
  echo "PASS (fast lane): unit and kernel tests, and phpcs, phpstan, cspell on the changed files, with no error and no failure."
  echo "The full lane (scripts/check.sh without --fast) is required before handing back."
else
  echo "PASS: phpunit, phpcs, phpstan, cspell, with no error and no failure."
fi
