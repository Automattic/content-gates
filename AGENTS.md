# AGENTS.md

Operational guide for working in this repo. Read this first.

## Purpose and specification

`content-gates` is a WordPress plugin for cache-aware content gating on WPVIP. Integrate with WPVIP's cache personalization API (and potentially edge workers). External providers own entitlement decisions and are outside this plugin's scope, but this plugin must provide a way to integrate with them.

Product decisions, discovery evidence, and specification lives in `spec/`.

## Working style

- Use simplified technical English. State the main point first.
- Before a task, state its finish line and the evidence needed to prove completion.
- Keep changes focused. Explain product and design reasons; record assumptions and blockers.
- Update specifications and public API documentation with behavior changes.

## Layout, setup, and environment

Support floor (assumed until the spec says otherwise): WordPress 6.6+,
PHP 8.1+, Node 20+ (`.nvmrc` pins 24). wp-env runs PHP 8.3 and latest
WordPress core.

Tooling, all pinned in `composer.json` / `package.json`:

- PHPCS with `WordPress-Extra`, `WordPress-Docs`, and `WordPress-VIP-Go`
  (`phpcs.xml.dist`). Prefixes: `ContentGates`, `content_gates`,
  `CONTENT_GATES`. Text domain: `content-gates`.
- PHPStan level 8 with the WordPress extension and test stubs
  (`phpstan.neon.dist`).
- PHPUnit 9.6 + Yoast polyfills, run against the WordPress test library
  that wp-env mounts at `WP_TESTS_DIR` (`phpunit.xml.dist`).
- `@wordpress/scripts` for ESLint (flat config, WordPress preset) and
  Playwright with `@wordpress/e2e-test-utils-playwright`
  (`playwright.config.ts`).

```bash
npm run lint          # lint:php + analyze:php + lint:js
npm run lint:php      # composer lint   (PHPCS)
npm run analyze:php   # composer analyze (PHPStan)
npm run lint:js       # wp-scripts lint-js
composer lint:fix     # PHPCBF
```

There is no build step. The only client-side code is `assets/editor.js`, a
plain script (no JSX, no bundler) that uses the `wp.*` globals and is
enqueued as-is by `inc/editor/class-editor-assets.php`. Packaging is TODO.

Class autoloading follows WordPress file naming:
`ContentGates\Providers\Static_Provider` lives at
`inc/providers/class-static-provider.php` (see `autoload()` in
`content-gates.php`). Tests use the core `test-*.php` convention.

Keep development and test data separate. Do not run database-resetting suites against a shared environment concurrently. Local edge simulation should follow WPVIP cache behavior. Document and warn about any variances.

## Coding standards

- Follow [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for PHP, JavaScript, CSS, HTML, documentation, and accessibility.
- Require zero lint errors and warnings, clean formatting, and passing configured type/static checks. Do not weaken rules or expand exclusions to hide failures.
- Validate inputs, escape outputs, and enforce caller authorization. Keep secrets and reader credentials out of source control and logs.
- Document public hooks and APIs, including errors, versioning, and compatibility. Keep provider, rendering, and edge responsibilities separate.

## Testing and coverage

- Test the changed behavior and add a meaningful regression test for each bug fix. Start with the smallest useful test, then run relevant integration and end-to-end checks.
- Cover successful, denied, invalid, and expired membership; PHP/REST callers; editor assignment; template checks; metering; crawler responses; and cache transitions.
- Assert response content, not only visual output. Test page, REST, and feed protection, plus every other leak surface listed in the PRD.
- Verify cookie-setting timing, cache hits/misses, purges, and membership changes. Require live VIP evidence for platform claims.
- TODO: Configure line and branch coverage reporting, exclusions, and CI thresholds. Until agreed, require tests for each acceptance criterion and critical decision branch; identify uncovered paths. Do not claim a coverage percentage without measurement.

## Quality and diagnostics

- Required result: zero restricted bytes in page, REST, and feed responses for non-entitled requests under the agreed access policy. Record the PRD's accepted risk exceptions explicitly.
- Cache hit rate must remain within 5 percentage points of the baseline on the same URLs with a comparable workload. Save the workload, versions, and results.
- Check crawler structured data with Google's Rich Results Test. Record all leak-surface findings and permitted limitations.
- Before completion, run applicable build, lint, analysis, and test checks. Report failures and skipped checks; missing tooling is not a pass.
- `npm run doctor` is the environment health check. TODO: Add safe debug logging and reproducible benchmark commands. Record confirmed traps with their regression tests.
- Sign commits; ensure local Git configuration does not disable signing. Never bypass checks merely to obtain a passing result.

## Repo layout

- `content-gates.php`: plugin entrypoint, constants, autoloader
- `inc/`: server PHP (`class-plugin.php` wires components to hooks)
- `docs/`: docs for human users, including the public hook reference
- `spec/`: product specification
- `bin/`: repo scripts (`doctor.mjs`)
- `assets/`: `editor.js`, the block editor integration (plain script)
- `tests/`: ALL tests, fixtures, and test tooling
  - `tests/unit/`: PHPUnit tests (`bootstrap.php`, `test-*.php`)
  - `tests/e2e/`: Playwright specs and `global-setup.ts`
  - `tests/e2e/fixtures/mu-plugins/`: mapped into BOTH wp-env sites as
    `wp-content/mu-plugins`. Holds the test-only entitlement provider
    (`content-gates-test-provider.php`), which grants every membership
    request. Never deploy it.
  - `tests/debugging/`: debugging/analysis tools, including
    `edge-sim.mjs`, the local WPVIP edge cache simulator (see its README)
- `.github/workflows/ci.yml`: static checks, PHPUnit, Playwright
- `artifacts/`: gitignored Playwright output and auth storage state

## Setup (from a clean checkout)

```bash
composer install          # PHP tooling (PHPCS/WPCS, PHPUnit 9 + polyfills)
npm install               # JS tooling (@wordpress/scripts, wp-env, Playwright)
```

## Environment

Two SEPARATE wp-env configs (dev and tests), each a single wp-env
instance (`testsEnvironment: false`; wp-env's embedded tests site is
deprecated and would collide on :8889).

```bash
npm run env start         # DEV env   (.wp-env.json)        http://localhost:8888
npm run env:tests start   # TESTS env (.wp-env.tests.json)  http://localhost:8889
```

`npm run env:tests` is `wp-env --config=.wp-env.tests.json`; any wp-env
subcommand works after it (`stop`, `run cli wp ...`, `logs`, `destroy`).

`autoPort` is on, so when a port is busy wp-env picks a free one and prints
the URL it chose. Force ports with `WP_ENV_PORT`. Each config has its own
work dir under `~/.wp-env` (`wp-env-content-gates-<hash>` and
`wp-env-content-gates-tests-<hash>`), so the two environments are fully
independent — separate databases included. Personal overrides go in
`.wp-env.override.json` / `.wp-env.tests.override.json` (both gitignored).

## Testing

```bash
npm run test:php            # PHPUnit in the wp-env tests container
npm run test:e2e            # Playwright E2E against the wp-env tests site
```

**Iterate at the cheapest layer that can catch the change.** The ladder,
fast → slow (only the last two need wp-env):

1. `npm run lint:php` and `npm run analyze:php`: PHPCS and PHPStan on the
   host. Catches standards, types, and undefined symbols in well under a
   second.
2. `npm run lint:js`: ESLint over `bin/`, `tests/e2e/`, and
   `playwright.config.ts`.
3. `npm run test:php`: PHPUnit inside the tests-env `cli` container with the
   WordPress test library. Covers hooks, providers, rendering, REST.
4. `npm run test:e2e`: Playwright against the tests site. Covers response
   bytes, cookies, cache behavior, admin UI. Starts the tests env if the
   base URL does not answer.

Single-test loops: Don't rerun a whole suite while iterating on one
failure:

```bash
npm run test:php -- --filter Test_Class_Name  # single PHPUnit class/method
npm run test:e2e -- collaboration-intent-log  # single e2e spec by filename
```

Never run `test:php` while an e2e run is in flight against the same env:
PHPUnit wipes the tests-env database, killing every in-flight spec
(auth and plugin activation vanish mid-run). Serialize the suites.

Beware: if ANOTHER project's wp-env holds `:8889`, Playwright's
webServer check sees the port alive and silently reuses that foreign
site (wp-env credentials are identical everywhere, so auth even
succeeds). Always pass `WP_BASE_URL` in that case (`npm run doctor`
detects this arrangement and prints the right URL).

## Diagnostics

When something misbehaves, reach for these before adding `printf` debugging.
They exist so a failure is observable without re-instrumenting:

- **`npm run doctor`**: read-only environment preflight. Checks toolchain,
  Docker, dependencies, both wp-env instances, who owns :8888/:8889, and
  prints the `WP_BASE_URL` to use.
- **`npm run env:tests logs --no-watch`**: PHP and Apache logs for the tests
  site. `WP_DEBUG_LOG` is on, so PHP notices land in
  `wp-content/debug.log` inside the container
  (`npm run env:tests run cli tail wp-content/debug.log`).
- **Playwright traces**: every failure keeps a trace, video, and
  `error-context.md` under `artifacts/test-results/`. Open with
  `npx playwright show-trace <trace.zip>`.
- **`npm run edge-sim -- --origin http://localhost:8889`**: a local edge
  cache simulator in front of a wp-env site (default port 8891). Responses
  carry `X-Edge-Sim: HIT|MISS|PASS` and `X-Edge-Sim-Key`; `GET
  /__edge-sim/stats` lists stored variants per URL and their consulted
  sets. Read `tests/debugging/README.md` for its variances from WPVIP
  before treating a result as platform evidence. Stop it before
  `npm run test:php`.

## Gotchas

Each of these has bitten. Don't rediscover them.

- **Segments accumulate in the tests database.** Nothing can delete a
  `cg_segment`, and each e2e run creates about twenty. Every one is
  registered as a cache group on each request and listed in the editor
  dropdown. A PHPUnit run (`npm run test:php`) wipes the database and is
  the only reset.
- **PHPUnit leaves the tests DB on a nonexistent theme.** The WordPress test
  library's install step resets the database and sets `stylesheet` to
  `default`, so every page on :8889 returns 500 for logged-in users until
  a real theme is active. `tests/e2e/global-setup.ts` pins
  `twentytwentyfive` for this reason. Do not remove that line.
- **PHPUnit also leaves the tests site with the plugin inactive and plain
  permalinks.** The same install step deactivates every plugin and resets
  `permalink_structure`, which breaks `/wp-json/`, `/feed/`, and
  `<permalink>embed/` routing. `tests/e2e/global-setup.ts` fixes all
  three: it pins the theme, activates `content-gates`, and submits the
  Permalinks form to set `/%postname%/`, then probes `/wp-json/` to prove
  the rewrite rules are live. Keep all of that in place.
- **`wp-env start` without `testsEnvironment: false` starts two sites.** The
  default config would also claim :8889 and race the real tests config.
  Both configs set the flag.
- **`npm run env:tests run ...` needs `--env-cwd`.** The container's cwd is
  the WordPress root, not the plugin. `test:php` already passes
  `--env-cwd=wp-content/plugins/content-gates`.
- **ESLint is flat config.** `@wordpress/scripts` 36 ships
  `eslint.config.cjs`; a legacy `.eslintrc` is ignored. Scripts in `bin/`
  need `/* eslint-disable no-console */` to print.
- **`composer.lock` must resolve on PHP 8.1, not the host's PHP.**
  `composer.json` pins `config.platform.php` to `8.1.0` so a newer host
  cannot lock a package that CI (8.3) or the wp-env container (8.3) cannot
  install. Never remove the pin to make a local `composer update` pass.
- **`WP_TESTS_DIR` is only set inside wp-env.** Running `vendor/bin/phpunit`
  on the host fails fast with a message pointing to `npm run test:php`.
