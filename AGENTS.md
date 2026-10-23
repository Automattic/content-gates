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

Support floor: WordPress 7.0+, PHP 8.2+, Node 22+ (`.nvmrc` pins 24; the
edge simulator and its tests run `.ts` files with Node's built-in type
stripping, and the VIP Edge Worker SDK needs 22.19+). wp-env runs PHP 8.3
and latest WordPress core. See `spec/decisions.md`.

```bash
npm run lint          # lint:php + analyze:php + lint:js
npm run lint:php      # composer lint   (PHPCS)
npm run analyze:php   # composer analyze (PHPStan)
npm run lint:js       # wp-scripts lint-js (src/, edge-workers/, tests/e2e/, bin/)
composer lint:fix     # PHPCBF
npm run build         # editor script: src/ -> build/ (gitignored; the plugin enqueues build/index.js)
npm run edge:install  # once: edge-workers/ dependencies (VIP edge SDK, AssemblyScript), its own npm package
npm run edge:build    # Edge Worker: regenerate lib/crawlers-config.ts, then `vip edge-workers build` -> edge-workers/build/content-gates.wasm (gitignored)
```

Class autoloading follows WordPress file naming:
`Automattic\VIP\ContentGates\Providers\Newspack_Bridge` lives at
`inc/providers/class-newspack-bridge.php` (see `autoload()` in
`content-gates.php`). Tests use the core `test-*.php` convention.

`inc/platform/vendor/class-vary-cache.php` is a verbatim copy of WPVIP's
`Vary_Cache`, loaded only when `WP_ENVIRONMENT_TYPE` is `local` and the
platform copy is absent. It is excluded from PHPCS and analyzed as a
scanned file only. Re-copy from upstream to update; never edit it.

Keep development and test data separate. Do not run database-resetting suites against a shared environment concurrently. Local edge simulation should follow WPVIP cache behavior. Document and warn about any variances.

## Coding standards

- Follow [WordPress coding standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/) for PHP, JavaScript, CSS, HTML, documentation, and accessibility.
- Require zero lint errors and warnings, clean formatting, and passing configured type/static checks. Do not weaken rules or expand exclusions to hide failures.
- Validate inputs, escape outputs, and enforce caller authorization. Keep secrets and reader credentials out of source control and logs.
- Document public hooks and APIs, including errors, versioning, and compatibility. Keep provider, rendering, and edge responsibilities separate.

## Testing and coverage

- Test the changed behavior and add a meaningful regression test for each bug fix. Start with the smallest useful test, then run relevant integration and end-to-end checks.
- Cover successful, denied, invalid, and expired membership; PHP/REST callers; editor assignment; template checks; metering; crawler responses; and cache transitions.
- Assert response content, not only visual output. Test page, REST, and feed protection, plus every other leak surface listed in the spec.
- Verify cookie-setting timing, cache hits/misses, purges, and membership changes. Require live VIP evidence for platform claims.
- TODO: Configure line and branch coverage reporting, exclusions, and CI thresholds. Until agreed, require tests for each acceptance criterion and critical decision branch; identify uncovered paths. Do not claim a coverage percentage without measurement.

## Quality and diagnostics

- Structure the code and documentation to maximize understanding. The audience consists of technical product managers and engineers, but not experts. Complex topics must be explained step-by-step, with diagrams and examples.
- Required result: zero restricted bytes in page, REST, and feed responses for non-entitled requests under the agreed access policy. Record the spec's accepted risk exceptions explicitly.
- Cache hit rate must remain within 5 percentage points of the baseline on the same URLs with a comparable workload. Save the workload, versions, and results.
- Check crawler structured data with Google's Rich Results Test. Record all leak-surface findings and permitted limitations.
- Before completion, run applicable build, lint, analysis, and test checks. Report failures and skipped checks; missing tooling is not a pass.
- `npm run doctor` is the environment health check. TODO: reproducible benchmark commands for the hit-rate target. Confirmed traps live under Gotchas with their regression tests.
- Organize the work in atomic commits that are easy for humans to review.
- Sign commits; ensure local Git configuration does not disable signing. Never bypass checks merely to obtain a passing result.

## Repo layout

- `content-gates.php`: plugin entrypoint, constants, autoloader
- `artifacts/`: gitignored Playwright output and auth storage state
- `bin/`: repo scripts (`doctor.mjs`)
- `config/`: data shared by the plugin and the Edge Worker (`crawlers.json`)
- `edge-workers/`: the VIP CLI edge-workers project (`vip edge-workers init` layout): `workers/content-gates/` (manifest + SDK entry), `lib/` (shared pure modules), `sim/` (local simulator), `tests/` (Node tests), `scripts/` (crawler config generator). Its own npm package: `npm run edge:install`
- `docs/`: docs for humans and agents; start at `docs/README.md`
- `inc/`: server PHP (`class-plugin.php` wires components to hooks; `docs/architecture.md` maps each class)
- `spec/`: product specification
- `src/`: block editor code (TypeScript), built by `wp-scripts` into `build/`
- `tests/`: ALL tests, fixtures, and test tooling
  - `tests/unit/`: PHPUnit tests (`bootstrap.php`, `class-content-gates-test-case.php`, `test-*.php`)
  - `tests/e2e/`: Playwright specs, `helpers.ts`, and `global-setup.ts` (seeds the tests site)
  - `tests/fixtures/mu-plugins/`: the local-only demo provider, seeder, and purge bridge, mapped into both wp-env sites
  - `tests/phpstan/`: stubs for static analysis only
  - `tests/debugging/`: debugging/analysis tools
- `.github/workflows/ci.yml`: static checks, edge tests, PHPUnit, Playwright

## Setup (from a clean checkout)

```bash
composer install          # PHP tooling (PHPCS/WPCS, PHPUnit 9 + polyfills)
npm install               # JS tooling (@wordpress/scripts, wp-env, Playwright)
npm run edge:install      # edge-workers/ tooling (VIP edge SDK, AssemblyScript); only needed for edge:build
npm run setup             # build the editor script, start the dev site, seed demo data
npm run edge:sim          # optional: edge simulator on :8890 in front of the dev site
```

`docs/local-development.md` has the demo flows (join/leave segments with
curl or the footer box, crawler and meter walkthroughs, what each response
header means).

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

Both configs map `tests/fixtures/mu-plugins/` to `wp-content/mu-plugins`,
so the demo provider and `wp content-gates-dev seed` exist in both sites
(the fixture is inert outside `WP_ENVIRONMENT_TYPE=local` and inside
PHPUnit). `npm run seed` seeds the dev site; `test:e2e` seeds the tests
site itself.

`autoPort` is on, so when a port is busy wp-env picks a free one and prints
the URL it chose. Force ports with `WP_ENV_PORT`. Each config has its own
work dir under `~/.wp-env` (`wp-env-content-gates-<hash>` and
`wp-env-content-gates-tests-<hash>`), so the two environments are fully
independent — separate databases included. Personal overrides go in
`.wp-env.override.json` / `.wp-env.tests.override.json` (both gitignored).

## Testing

```bash
npm run test:edge           # Edge Worker logic + simulator, Node only, no Docker
npm run test:php            # PHPUnit in the wp-env tests container
npm run test:e2e            # Playwright E2E against the wp-env tests site (+ in-process edge simulator)
```

**Iterate at the cheapest layer that can catch the change.** The ladder,
fast → slow (only the last two need wp-env):

1. `npm run lint:php` and `npm run analyze:php`: PHPCS and PHPStan on the
   host. Catches standards, types, and undefined symbols in well under a
   second.
2. `npm run lint:js`: ESLint over `src/`, `edge-workers/`, `bin/`, `tests/e2e/`,
   and `playwright.config.ts`. `npx tsc --noEmit` type-checks `src/`.
3. `npm run test:edge`: `node --test` over the pure edge modules and the
   simulator against a fake origin. Seconds, no Docker.
4. `npm run test:php`: PHPUnit inside the tests-env `cli` container with the
   WordPress test library. Covers segments, membership, declared set,
   rendering, excerpts, REST, admin switcher, Newspack bridge.
5. `npm run test:e2e`: Playwright against the tests site. Covers response
   bytes on page/feed/REST, cookies, the admin page, the editor control,
   and the edge simulator in front of the real site. Starts the tests env
   if the base URL does not answer; needs `npm run build` first for the
   editor spec.

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
succeeds). Start our tests env instead (autoPort picks a free port) and
pass the `WP_BASE_URL` that `npm run doctor` prints. `WP_BASE_URL` only
moves the URL; `global-setup.ts` still seeds our container through
wp-env. Only `CONTENT_GATES_E2E_EXTERNAL=1` skips the seed, for a site
that is not our container and already carries it.

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
- **Edge simulator**: `GET /__edge/stats` lists stored copies and KV
  records; `POST /__edge/reset` clears them. Every response carries
  `X-Edge-Sim`, `X-Edge-Sim-Key`, and `X-Edge-Sim-Record`.
- **Diagnostic response headers**, always on: origin's
  `X-WPVIP-Content-Gates-Edge-Received` (the worker's request markers as
  origin saw them), the worker's `X-WPVIP-Content-Gates-Edge-Coverage`
  (phase 3's decision, stored with the copy) and
  `X-WPVIP-Content-Gates-Edge-Meter` (phase 4, per delivery). Edge Workers
  have no logs; `docs/edge-worker.md` has the reading table.
- **`?content_gates_demo=1`** on any dev front-end page shows the declared
  set and Join/Leave buttons per segment (local only, never cached).
- **`wp content-gates list`** and **`wp content-gates declared <id>`** show
  the segment table and a post's stored declared set from the shell.

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
- **Core fires `send_headers` before the `wp` action.** The spec originally
  said otherwise. The plugin finalizes the declared set at the end of `wp`;
  membership writes close at `send_headers`. `spec/decisions.md` records it.
- **PHPUnit restores hook snapshots between tests.** `WP_UnitTestCase`
  snapshots `$wp_filter` in `set_up()` and restores it in `tear_down()`, so
  a per-request component instance from an earlier test comes back hooked
  next to the fresh one. `Content_Gates_Test_Case::new_request()` scrubs
  callbacks bound to those classes before `Plugin::reset_request_state()`.
  Symptom when this is missing: the second test in a class withholds content
  that the first test showed.
- **The test library serves REST through `Spy_REST_Server`.** It buffers the
  body into `$server->sent_body`; `ob_start()` around `serve_request()`
  captures nothing. `Test_REST::serve()` reads the spy.
- **`header()` and `setrawcookie()` warn under PHPUnit** because the runner
  already wrote to stdout. `Vary_Cache` calls both at `send_headers`. The
  test base chains an error handler that drops only
  "Cannot modify header information".
- **Core's `excerpt_remove_blocks()` unwraps `core/group`.** A gated Group's
  inner blocks render into auto-excerpts without `pre_render_block` ever
  seeing the Group. `Excerpt_Guard` exists for this;
  `Test_Excerpt_Guard::test_core_excerpt_leaks_without_guard` proves the
  leak so nobody removes the guard.
- **The post factory sets a manual excerpt.** `WP_UnitTest_Factory_For_Post`
  fills `post_excerpt`, which bypasses excerpt generation. The test base's
  `create_post()` clears it.
- **`register_rest_route()` outside `rest_api_init` is `_doing_it_wrong`.**
  Tests that need a throwaway route register it on the live server with
  `rest_get_server()->register_route()`.
- **`node --test <directory>` fails on Node 22 and 24** with "Cannot find
  module .../edge-workers/tests"; only Node 26 expands a directory argument. The
  `test:edge` script passes a quoted glob (`'edge-workers/tests/*.test.ts'`), which
  Node itself expands on every supported version. Do not "simplify" it back
  to the directory form: CI runs the `.nvmrc` version and would fail.
- **Pure edge modules must stay inside the AssemblyScript/TypeScript
  intersection.** No `Map`, closures over locals, `for...of`, spread,
  `===` on strings, or 64-bit integers. `npm run edge:build` and
  `npm run test:edge` both have to pass; one without the other proves
  nothing.
