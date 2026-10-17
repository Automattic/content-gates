---
name: build-with-tests
description: >
  How to build, implement, or extend a production feature in the WordPress VIP codebase
  (vip-go-api: GOOP and Parker) from a technical brief, with unit tests written alongside the
  code and typecheck, lint, and the test suite run at the end. Use this whenever the task is to
  build, implement, add, extend, wire up, or ship a feature, endpoint, service, integration,
  job, or non-trivial bug fix in a target repo, even if the request doesn't say "feature" or
  mention tests. Also use it when a spec-writer brief or user story is handed over for
  implementation. Not for read-only research, spec writing, or docs-only edits.
---

# Build with tests

You are implementing a change in a VIP repo from a technical brief. The brief says *what*; this skill is *how the team does it*. The goal is a diff a senior VIP engineer would merge without asking for rework: it matches the surrounding code, it is tested in the way the repo tests things, and every check the repo requires is green on the final commit.

## 1. Read before you write

Read these in order, fully, before touching code. Skipping this is the most common cause of a PR that works but gets rejected for not fitting.

1. `CLAUDE.md` at the factory root. It holds the non-negotiables (no migrations, no permission widening, no secrets, local-only mutations) and the platform facts (GOOP vs Parker, `src/` vs `lib/`, access control). If anything in the brief conflicts with it, stop and report; CLAUDE.md wins.
2. The technical brief from `spec-writer` (usually `runs/<run-id>/spec.md`). Note the files it says will change, the tests it requires, the tenant-isolation and timezone sections, and any open question marked as blocking. A blocking open question means you stop with `NEEDS_HUMAN`; do not resolve it by picking an answer.
3. The target repo's `AGENTS.md` at the repo root for GOOP, and `api-wpvip-com/AGENTS.md` for any Parker work. These are the authority on code conventions and override anything in this skill that disagrees.
4. The `docs/*.md` the brief or the area needs: `ARCHITECTURE.md` for boundaries, `TESTING.md` for required checks and fixtures, `JOBS.md` if the change touches jobs or queues.

You should be able to say, before writing a line: which API, which layer, which files, which tests, and which commands prove it works. If you can't, the gap is in the brief; report it rather than filling it in yourself.

## 2. Find two or three similar features and match them

The codebase already contains the answer to "how should this look." Before writing, find two or three existing features that do something structurally similar: same kind of endpoint, same kind of service, a sibling integration, a job of the same shape. Grep for the DTO base classes, the decorators, the service method names, the table names the brief mentions.

For each, read the production file *and* its test file side by side. Note:

- how the module is wired (imports/exports, providers, bridges from `lib/`)
- how input is validated and how errors are shaped and thrown
- where authorization is checked and what helper does it
- how the database is reached (query builder, repository, raw helper) and how list reads are batched
- what the test file mocks, what it leaves real, which fixtures it loads, and how it names cases

Then write your code to look like the best of those examples, not the oldest. Where the examples disagree with `AGENTS.md`, follow `AGENTS.md`; the researcher or the brief may have flagged such deviations. Where they disagree with each other, prefer the one in `src/` (NestJS) over `lib/`, and the more recently touched one.

Matching matters because reviewers pattern-match. Code that looks like its neighbours gets reviewed for logic; code that looks foreign gets reviewed for everything.

## 3. Write tests alongside the code

Write the test for a unit when you write the unit, in the same sitting, before moving to the next piece. This is ordinary good engineering, not a ceremony: you do not need a failing test first, and you do not need to alternate red and green. What you need is that no production function reaches the commit without a test that would fail if it broke.

A workable rhythm:

1. Implement one cohesive piece (a DTO plus validator, a service method, a controller action, a job handler).
2. Write or extend the test for it right away, while the edge cases are in your head. Extend the existing test file for that module when one exists; new files only for new modules.
3. Run the focused test: `npm run jest -- path/to/test -t 'name'`. Fix until green.
4. Move on.

What the tests need to be, drawn from `AGENTS.md` and `CLAUDE.md`:

- They test behaviour, not wiring. Never mock the thing the test claims to verify. Mock external boundaries (other services' HTTP via MSW at `@fixtures/msw/server`, never `nock`); keep internal implementations real.
- Each assertion must fail when the protected behaviour breaks. When you're unsure, break the code deliberately, watch the test fail, then restore it. Cheap, and it catches tests that pass for the wrong reason.
- Spy arguments go through `toHaveBeenCalledWith` and matchers, never `mock.calls[0][1]` indexing.
- Access-sensitive operations get three cases: an admin that succeeds, a non-admin that succeeds where allowed, and a denied request with the exact denial response. If the brief marked the operation as access-sensitive and you have fewer than three, you're not done.
- Cover what the brief's "Tests required" section lists, tied to acceptance-criterion numbers, plus the failure paths the examples you studied cover for their own features.
- State in the PR what the mocks leave unverified. Passing call assertions do not prove authorization, persistence, redaction, or concurrency.
- If e2e tests need new route or permission rows, add them to `schemas/test_data.sql`.

Don't add duplicate suites, fixtures, or cases without a distinct regression they catch. More tests is not the goal; tests that would catch the bug you'd otherwise ship is.

## 4. Finish with the full set of checks

On the final commit, after your last change, run everything the repo requires and keep the output. "I ran it earlier" doesn't count once the code moved.

For `vip-go-api` (confirm against `docs/TESTING.md`, which is authoritative):

```bash
npm run check-types
npm run lint
npm run format:check
npm run jest                      # full suite, not just your files
npm run build
```

For endpoint changes, also exercise the live local API and record the response:

```bash
curl --max-time 10 http://localhost:2999/v1/<your-route>
```

Local requests bypass auth by default; `docs/TESTING.md` explains how to test with realistic permissions, and you need to for anything access-sensitive. Check `docker ps` before starting services; `docker logs --tail 100 vip_go_api_app` for app logs; `docs/SETUP.md` for recovery.

If a check fails, fix the cause, not the check. If the environment can't be brought up after following `docs/SETUP.md`, the run is `BLOCKED`, not "verified by inspection". Record the commands and summarized output in `runs/<run-id>/verification.md` so the PR can cite real evidence.

Before committing, `git status` and confirm nothing untracked and local (`config/config.dev.json`, `.env*`, `.artifacts/`) is staged. Stage with explicit paths or `git add -u`, never `-A`.

## 5. Conventions

The conventions the repo follows are summarised in [references/conventions.md](references/conventions.md): naming, error handling, where business logic lives, module wiring, endpoint shape, and how tests are structured, for both GOOP and Parker. Read it once at the start of the build, then confirm the specifics against the two or three examples you chose in step 2, because the code is the ground truth and the summary is only a map.

## When to stop instead of continuing

Stop and report `NEEDS_HUMAN` or `BLOCKED` when:

- the brief has an unanswered blocking question, or you discover one while reading the code
- the change needs a migration, a widened role/cap/service-account permission, or a new scheduler, table, queue, or dependency the brief didn't call out
- the implementation is growing well past the brief's "Files that will change" list
- you find an unrelated bug, security issue, or exposed secret (report it, don't fix it here)
- a required check fails for a reason outside your change

A short honest stop beats a plausible PR that hides a guess.
