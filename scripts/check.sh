#!/usr/bin/env bash
# Runs every gate this module is held to, in order, stopping at the first
# failure. CLAUDE.md says what each means. Works inside or outside ddev.

set -uo pipefail

MODULE="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB="$(cd "$MODULE/../../.." && pwd)"
ROOT="$(dirname "$WEB")"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

fail() { echo; echo "FAILED: $1"; exit 1; }

# 1. The suite. Only the container can reach the database, so detect which
# side of it we are on and wrap accordingly.
echo "== phpunit =="
RUN='cd /var/www/html/web && SIMPLETEST_DB=mysql://db:db@db/db SIMPLETEST_BASE_URL=http://localhost ../vendor/bin/phpunit -c core modules/custom/data_surface'
if [ "${IS_DDEV_PROJECT:-}" = "true" ]; then
  bash -c "$RUN" 2>&1 | tee "$WORK/phpunit.txt"
else
  (cd "$ROOT" && ddev exec bash -c "$RUN") 2>&1 | tee "$WORK/phpunit.txt"
fi

# One failure is known and no test may error, and that one is this
# checkout's rather than the module's: the FunctionalJavascript test wants
# a webdriver on port 4444 and ddev has none. CLAUDE.md has the detail.
KNOWN='DataSurfaceRefinementTest::testRefinementChainRebuildsTheSurface'
SUMMARY="$(grep -E '^(Tests:|OK) ' "$WORK/phpunit.txt" | tail -1)"
[ -n "$SUMMARY" ] || fail "phpunit did not finish; no result line in its output"
ERRORS="$(printf '%s' "$SUMMARY" | sed -n 's/.*Errors: \([0-9][0-9]*\).*/\1/p')"
[ -z "$ERRORS" ] || fail "phpunit: $ERRORS errors; an error is a regression ($SUMMARY)"
UNEXPECTED="$(grep -E '^[0-9]+\) Drupal.Tests.data_surface' "$WORK/phpunit.txt" \
  | sed -E 's/^[0-9]+\) //' | sort -u | grep -vE "$KNOWN")"
[ -z "$UNEXPECTED" ] || fail "phpunit, and these are not known failures:
$UNEXPECTED"
grep -q 'DriverException: Could not open connection' "$WORK/phpunit.txt" \
  || fail "phpunit: the webdriver failure is gone, so the baseline has moved.
Update the known list in this script and the baseline in CLAUDE.md."
echo "phpunit: $SUMMARY"

# 2. Coding standards. The stored installed_paths point inside the container.
echo "== phpcs =="
(cd "$MODULE" && "$ROOT/vendor/bin/phpcs" --runtime-set installed_paths \
  "$ROOT/vendor/drupal/coder/coder_sniffer,$ROOT/vendor/slevomat/coding-standard") \
  || fail "phpcs"

# 3. Static analysis, at the level the module's own config sets.
echo "== phpstan =="
(cd "$ROOT" && vendor/bin/phpstan analyse -c "$MODULE/phpstan.neon" \
  --no-progress --memory-limit=1G) || fail "phpstan"

# 4. Spelling: core's dictionary plus this module's own project words.
echo "== cspell =="
cat > "$WORK/merge.php" <<'PHP'
<?php
[, $core, $module, $out] = $argv;
$config = json_decode(file_get_contents($core . '/.cspell.json'), TRUE);
$config['globRoot'] = $module;
$config['ignorePaths'] = ['.git/**', 'node_modules/**', '.cspell-project-words.txt'];
foreach ($config['dictionaryDefinitions'] as &$dictionary) {
  $dictionary['path'] = $core . '/' . ltrim($dictionary['path'], './');
}
$words = array_filter(array_map('trim', file($module . '/.cspell-project-words.txt')));
$config['words'] = array_values(array_merge($config['words'] ?? [], $words));
file_put_contents($out, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
PHP
php "$WORK/merge.php" "$WEB/core" "$MODULE" "$WORK/cspell.json" || fail "cspell config merge"
(cd "$MODULE" && npx --yes cspell@8 --config "$WORK/cspell.json" --no-progress --no-summary "**") \
  || fail "cspell"

echo
echo "PASS: phpunit, phpcs, phpstan, cspell. PASS means no errors at all and"
echo "only the one known failure — the webdriver one, which is this"
echo "checkout's. Anything else is a real regression."
