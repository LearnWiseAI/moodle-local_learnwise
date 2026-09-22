#!/usr/bin/env bash
#
# Runs the Moodle coding-style checks locally, mirroring the "Moodle Code
# Checker" step of .github/workflows/moodle-ci.yml:
#
#     moodle-plugin-ci phpcs --max-warnings 0
#
# That step runs in every one of the 14 CI matrix jobs, so a single style
# violation fails the whole matrix. Catching it here takes seconds instead.
#
# Unlike moodle-plugin-ci this needs no Moodle install: it pulls just the
# moodlehq/moodle-cs standard (the same one, at the same version constraint
# moodle-plugin-ci ^4 resolves) into .tools/phpcs on first run.
#
# Usage:
#   scripts/moodle-phpcs.sh          Check PHP files changed against main.
#   scripts/moodle-phpcs.sh --all    Check every PHP file in the plugin.
#   scripts/moodle-phpcs.sh <path>…  Check the given paths.
#
# Exit status is non-zero if phpcs reports any error OR any warning, because
# CI runs with --max-warnings 0.

set -euo pipefail

# The constraint moodle-plugin-ci ^4 declares for the coding standard. Keep
# this in step with moodle-plugin-ci so local results match CI.
MOODLE_CS_CONSTRAINT='^3.7.0'

# Directories that CI's phpcs does not police: the vendored OAuth2 library
# (see CLAUDE.md - do not modify), bundled JS, and local tooling.
EXCLUDED_PATHS=(
    'classes/local/OAuth2'
    'vendorjs'
    '.tools'
    '.claude'
)

rootdir="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
toolsdir="$rootdir/.tools/phpcs"
phpcs="$toolsdir/vendor/bin/phpcs"

cd "$rootdir"

if ! command -v php >/dev/null 2>&1 || ! command -v composer >/dev/null 2>&1; then
    echo "moodle-phpcs: php and composer are required; skipping style check." >&2
    echo "moodle-phpcs: CI will still run it, so install them to catch issues here." >&2
    exit 0
fi

# The highest PHP version in the CI matrix. Sniffs that key off the running
# PHP version (deprecated-function checks, mainly) can report things here that
# CI never sees, so say so rather than let it look like a real failure.
CI_MAX_PHP='8.4'
localphp="$(php -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')"
if [ "$(printf '%s\n%s\n' "$CI_MAX_PHP" "$localphp" | sort -V | tail -1)" != "$CI_MAX_PHP" ]; then
    echo "moodle-phpcs: note - local PHP $localphp is newer than CI's highest ($CI_MAX_PHP);" >&2
    echo "moodle-phpcs: version-sensitive warnings here may not appear in CI." >&2
fi

# Bootstrap the standard on first run.
if [ ! -x "$phpcs" ]; then
    echo "moodle-phpcs: installing moodlehq/moodle-cs $MOODLE_CS_CONSTRAINT into .tools/phpcs …" >&2
    mkdir -p "$toolsdir"
    (
        cd "$toolsdir"
        composer config --no-plugins allow-plugins.dealerdirect/phpcodesniffer-composer-installer true
        composer require -q --no-interaction "moodlehq/moodle-cs:$MOODLE_CS_CONSTRAINT"
    ) >&2
fi

# Work out what to check.
declare -a targets=()
case "${1:-}" in
    --all)
        targets=('.')
        ;;
    '')
        # Files changed relative to where this branch left main. Falls back to
        # the working-tree diff when no main ref is available.
        base=''
        for ref in origin/main main; do
            if git rev-parse --verify --quiet "$ref" >/dev/null; then
                base="$(git merge-base HEAD "$ref" 2>/dev/null || true)"
                [ -n "$base" ] && break
            fi
        done
        if [ -n "$base" ]; then
            mapfile -t targets < <(git diff --name-only --diff-filter=ACMR "$base" -- '*.php')
        else
            mapfile -t targets < <(git diff --name-only --diff-filter=ACMR HEAD -- '*.php')
        fi
        # Drop files deleted since, and anything under an excluded path.
        declare -a kept=()
        for f in "${targets[@]:-}"; do
            [ -n "$f" ] && [ -f "$f" ] || continue
            skip=''
            for ex in "${EXCLUDED_PATHS[@]}"; do
                case "$f" in "$ex"/*) skip=1 ;; esac
            done
            [ -n "$skip" ] || kept+=("$f")
        done
        targets=("${kept[@]:-}")
        ;;
    *)
        targets=("$@")
        ;;
esac

if [ "${#targets[@]}" -eq 0 ] || [ -z "${targets[0]:-}" ]; then
    echo "moodle-phpcs: no changed PHP files to check."
    exit 0
fi

ignore="$(IFS=,; printf '%s' "${EXCLUDED_PATHS[*]/%//*}")"

set +e
"$phpcs" \
    --standard=moodle \
    --extensions=php \
    --warning-severity=1 \
    --ignore="$ignore" \
    "${targets[@]}"
status=$?
set -e

if [ "$status" -ne 0 ]; then
    cat >&2 <<'MSG'

moodle-phpcs: style violations above would fail "Moodle Code Checker" in every
CI matrix job (it runs with --max-warnings 0, so warnings fail too).

Many are auto-fixable:
    .tools/phpcs/vendor/bin/phpcbf --standard=moodle <file>
MSG
    exit 1
fi

echo "moodle-phpcs: clean (${#targets[@]} file(s) checked)."
