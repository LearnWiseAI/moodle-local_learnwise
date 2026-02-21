# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A Moodle local plugin (`local_learnwise`) that integrates the LearnWise AI assistant into Moodle. It is **not** a standalone PHP project — it must live inside a Moodle installation at `<moodle_root>/local/learnwise/`.

There is no `composer.json` for this plugin. Moodle itself is the dependency.

## Development environment setup

### 1. Clone Moodle and symlink the plugin

```bash
git clone --branch MOODLE_500_STABLE --depth 1 https://github.com/moodle/moodle.git ~/moodle
ln -s /path/to/moodle-local_learnwise ~/moodle/local/learnwise
```

### 2. Fix PHP Intelephense red lines in VS Code

Add to `.vscode/settings.json` in the plugin folder:

```json
{
  "intelephense.environment.includePaths": ["/path/to/moodle"]
}
```

### 3. PHPStan static analysis

PHPStan must be run from the **Moodle root** (not the plugin directory), with the plugin checked out at `local/learnwise`:

```bash
cd ~/moodle
composer require --dev micaherne/phpstan-moodle
cp config-dist.php config.php
vendor/bin/phpstan analyse local/learnwise -c local/learnwise/phpstan.neon
```

PHPStan is configured at level 5. The vendored OAuth2 library (`classes/local/OAuth2/`) is excluded from analysis.

### 4. CI (moodle-plugin-ci)

The full CI suite (lint, phpcs, phpunit, behat) uses `moodle-plugin-ci`:

```bash
composer create-project -n --no-dev --prefer-dist moodlehq/moodle-plugin-ci ci ^4
moodle-plugin-ci install --plugin ./plugin --db-host=127.0.0.1
moodle-plugin-ci phplint
moodle-plugin-ci phpcs --max-warnings 0
moodle-plugin-ci phpunit --fail-on-warning
moodle-plugin-ci behat --profile chrome
```

## Architecture

### Plugin entry points

- **`auth.php`** — OAuth2 authorization endpoint (shows the user permission consent form)
- **`token.php`** — OAuth2 token endpoint
- **`setup.php`** — Admin setup page
- **`settings.php`** — Moodle admin settings (Site Administration > Server > LearnWise Integration)
- **`lib.php`** — Two Moodle callbacks: injects the floating widget into every page (`local_learnwise_before_standard_top_of_body_html`), and handles AJAX form submissions for the setup page (`local_learnwise_output_fragment_process_setup`)
- **`api/file.php`** — Proxies Moodle file downloads through OAuth2 authentication

### OAuth2 server

The plugin embeds a full OAuth2/OpenID Connect server (`classes/local/OAuth2/` — vendored, do not modify). The plugin-specific wiring:

- **`classes/server.php`** — Extends the vendored `OAuth2\Server`, initialises grant types and storage
- **`classes/storage.php`** — Implements all OAuth2 storage interfaces (`AccessTokenInterface`, `AuthorizationCodeInterface`, `ClientCredentialsInterface`, `RefreshTokenInterface`, `ScopeInterface`) backed by Moodle's `$DB`. Tables are prefixed `local_learnwise_*`.

### Web services / external API

All custom external functions extend `local_learnwise\external\baseapi` (`classes/external/baseapi.php`), which extends Moodle's `external_api`. Key behaviours provided by `baseapi`:

- Automatically converts Unix timestamps to ISO 8601 via `timestampvalue` wrapper
- Rewrites `pluginfile.php` URLs to the plugin's own `api/file.php` proxy in all responses
- Supports "single operation" mode (return one item vs. array) via `set_id()` / `is_singleoperation()`
- Subclasses must implement `single_structure()` and optionally override `get_unixtimestamp_fields()`

The "Learnwise Service" web service (`db/services.php`) bundles Moodle core functions plus the custom `local_learnwise_assign_get_submission` function. It is restricted-user-only, download-enabled.

### Page injection (floating widget + AI assessment)

`hook_callbacks::before_standard_top_of_body_html_generation()` runs on every page load. It renders:
- **`assistantwidget.mustache`** — floating chat button (when `showassistantwidget` + `assistantid` are configured)
- **`aiassessmentassistant.mustache`** — AI grading panel (only on `page-mod-assign-grader` when `aiassessment` is enabled)
- An inline `<script>` that resizes the LTI iframe on `mod/lti/view.php`

Hook registration is in `db/hooks.php`. The legacy Moodle 3.x path goes through `lib.php`; Moodle 4.4+ uses the hook system directly.

### Setup page (AJAX-driven)

The setup page (`setup.php` + `classes/output/setup.php` + `templates/setup.mustache`) is driven by AMD module `amd/src/setup.js`. Form submissions go through AJAX using Moodle's fragment API, handled by `local_learnwise_output_fragment_process_setup` in `lib.php`, which processes `form\assistantinput` (LTI setup) or `form\webservicesetup` (web service token setup).

### Key classes

| Class | Purpose |
|---|---|
| `local_learnwise\constants` | Config constants; extends `util` |
| `local_learnwise\util` | Helper methods (`get_remotehosturl`, `get_ltitoolurl`, etc.) |
| `local_learnwise\server` | OAuth2 server instantiation |
| `local_learnwise\storage` | Moodle DB-backed OAuth2 storage |
| `local_learnwise\hook_callbacks` | Page injection logic |
| `local_learnwise\task\cleanup_tokens` | Scheduled task — purges expired tokens |
| `local_learnwise\privacy\provider` | GDPR compliance |

## Moodle conventions to follow

- Every PHP file must begin with `defined('MOODLE_INTERNAL') || die();` (except entry points that bootstrap Moodle first)
- Plugin component name is `local_learnwise` — used in capability names, config keys (`get_config('local_learnwise')`), and table prefixes
- Version bumps go in `version.php` (`$plugin->version` format: `YYYYMMDDXX`)
- Database schema changes require a new `db/upgrade.php` step and a version bump
- String keys live in `lang/en/local_learnwise.php`