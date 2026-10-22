# AGENTS.md

Operational guide for working in this repo. Read this first.

## Purpose and specification

`content-gates` is a WordPress plugin for cache-aware content gating on WPVIP. Integrate with WPVIP's cache personalization API and Edge Workers. External providers own entitlement decisions and are outside this plugin's scope, but this plugin must provide a way to integrate with them.

Product decisions, discovery evidence, and specification lives in `spec/`.

## Working style

- Use simplified technical English. State the main point first.
- Before a task, state its finish line and the evidence needed to prove completion.
- Keep changes focused. Explain product and design reasons; record assumptions and blockers.
- Update specifications and public API documentation with behavior changes.

## Layout, setup, and environment

Tooling, all pinned in `composer.json` / `package.json`:

- PHPCS with `WordPress-Extra`, `WordPress-Docs`, and `WordPress-VIP-Go`
  (`phpcs.xml.dist`). Prefixes: `Automattic\VIP\ContentGates`,
  `content_gates`, `CONTENT_GATES`. Text domain: `content-gates`.
- PHPStan level 8 with the WordPress extension and test stubs
  (`phpstan.neon.dist`).
- PHPUnit 9.6 + Yoast polyfills, run against the WordPress test library
  that wp-env mounts at `WP_TESTS_DIR` (`phpunit.xml.dist`).
- `@wordpress/scripts` for ESLint (flat config, WordPress preset) and
  Playwright with `@wordpress/e2e-test-utils-playwright`
  (`playwright.config.ts`).

Support floor: WordPress 7.0+, PHP 8.2+, Node 20+ (`.nvmrc` pins 24).
wp-env runs PHP 8.3 and latest WordPress core. See `spec/decisions.md`.

```bash
npm run lint          # lint:php + analyze:php + lint:js
npm run lint:php      # composer lint   (PHPCS)
npm run analyze:php   # composer analyze (PHPStan)
npm run lint:js       # wp-scripts lint-js
composer lint:fix     # PHPCBF
```

No build step exists yet. Client-side code will live in `src/` and build
with `wp-scripts build` once the editor UI is scaffolded (TODO).

Class autoloading follows WordPress file naming:
`Automattic\VIP\ContentGates\Providers\Static_Provider` lives at
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

- Structure the code and documentation to maximize understanding. The audience consists of technical product managers and engineers, but not experts. Complex topics must be explained step-by-step, with diagrams and examples.
- Required result: zero restricted bytes in page, REST, and feed responses for non-entitled requests under the agreed access policy. Record the PRD's accepted risk exceptions explicitly.
- Cache hit rate must remain within 5 percentage points of the baseline on the same URLs with a comparable workload. Save the workload, versions, and results.
- Check crawler structured data with Google's Rich Results Test. Record all leak-surface findings and permitted limitations.
- Before completion, run applicable build, lint, analysis, and test checks. Report failures and skipped checks; missing tooling is not a pass.
- `npm run doctor` is the environment health check. TODO: Add safe debug logging and reproducible benchmark commands. Record confirmed traps with their regression tests.
- Organize the work in atomic commits that are easy for humans to review.
- Sign commits; ensure local Git configuration does not disable signing. Never bypass checks merely to obtain a passing result.

## Repo layout

- `content-gates.php`: plugin entrypoint, constants, autoloader
- `artifacts/`: gitignored Playwright output and auth storage state
- `bin/`: repo scripts (`doctor.mjs`)
- `config/`: data shared by the plugin and the Edge Worker (`crawlers.json`; not scaffolded yet)
- `edge/`: VIP-managed Edge Worker source (AssemblyScript; not scaffolded yet)
- `docs/`: docs for human users, including the public hook reference
- `inc/`: server PHP (`class-plugin.php` wires components to hooks)
- `spec/`: product specification
- `src/`: browser code (TypeScript; not scaffolded yet)
- `tests/`: ALL tests, fixtures, and test tooling
  - `tests/unit/`: PHPUnit tests (`bootstrap.php`, `test-*.php`)
  - `tests/e2e/`: Playwright specs and `global-setup.ts`
  - `tests/debugging/`: debugging/analysis tools
- `.github/workflows/ci.yml`: static checks, PHPUnit, Playwright

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
- TODO: Add cache/cookie/segment inspection tools under `tests/debugging/`
  once Phase B exists.

## Gotchas

Each of these has bitten. Don't rediscover them.

- **PHPUnit leaves the tests DB on a nonexistent theme.** The WordPress test
  library's install step resets the database and sets `stylesheet` to
  `default`, so every page on :8889 returns 500 for logged-in users until
  a real theme is active. `tests/e2e/global-setup.ts` pins
  `twentytwentyfive` for this reason. Do not remove that line.
- **`wp-env start` without `testsEnvironment: false` starts two sites.** The
  default config would also claim :8889 and race the real tests config.
  Both configs set the flag.
- **`npm run env:tests run ...` needs `--env-cwd`.** The container's cwd is
  the WordPress root, not the plugin. `test:php` already passes
  `--env-cwd=wp-content/plugins/content-gates`.
- **ESLint is flat config.** `@wordpress/scripts` 36 ships
  `eslint.config.cjs`; a legacy `.eslintrc` is ignored. Scripts in `bin/`
  need `/* eslint-disable no-console */` to print.
- **`composer.lock` must resolve on PHP 8.2, not the host's PHP.**
  `composer.json` pins `config.platform.php` to `8.2.0` so a newer host
  cannot lock a package that CI (8.3) or the wp-env container (8.3) cannot
  install. Never remove the pin to make a local `composer update` pass.
- **`WP_TESTS_DIR` is only set inside wp-env.** Running `vendor/bin/phpunit`
  on the host fails fast with a message pointing to `npm run test:php`.
