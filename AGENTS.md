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

TODO: Define the plugin entry point, PHP/client/edge directories, dependency versions, and supported WordPress/PHP/Node versions.

TODO: Document installation, build, lint, type-check, test, coverage, and packaging commands here once verified. Starting tools: PHPCS with WordPress rules, `@wordpress/scripts`, PHPUnit, and Playwright; confirm versions and compatibility during setup.

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
- TODO: Add environment health checks, safe debug logging, and reproducible benchmark commands. Record confirmed traps with their regression tests.
- Sign commits; ensure local Git configuration does not disable signing. Never bypass checks merely to obtain a passing result.

## Repo layout

- `content-gates.php`: plugin entrypoint
- `docs/`: docs for human users
- `inc/`: server PHP
- `spec/`: product specification
- `tests/`: ALL tests, fixtures, and test tooling
  - `tests/unit/`: PHPUnit tests
  - `tests/debugging/`: debugging/analysis tools

## Setup (from a clean checkout)

```bash
composer install          # PHP tooling (PHPCS/WPCS, PHPUnit 9 + polyfills)
npm install               # JS tooling (@wordpress/scripts, wp-env, Playwright)
```

## Environment

Two SEPARATE wp-env configs (dev and tests).

```bash
npm run env start         # DEV env (.wp-env.json)
npm run env:tests start   # TESTS env (.wp-env.tests.json)
```

`autoPort` is on, so when a port is busy wp-env picks a free one and prints
the URL it chose. Force ports with `WP_ENV_PORT`. Each config has its own
work dir under `~/.wp-env` (the tests one carries a `-tests-` segment), so
the two environments are fully independent — separate databases included.
Personal overrides go in `.wp-env.override.json` /
`.wp-env.tests.override.json` (both gitignored).

## Testing

```bash
npm run test:php            # PHPUnit in the wp-env tests container
npm run test:e2e            # Playwright E2E against the wp-env tests site
```

**Iterate at the cheapest layer that can catch the change.** The ladder,
fast → slow (only the last three need wp-env):

1. TODO: Define test ladder

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

- **`npm run doctor`**: read-only environment preflight
- TODO: Document additional diagnostics

## Gotchas

Each of these has bitten. Don't rediscover them.

- TODO: Document gotchas
