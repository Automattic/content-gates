# vip-go-api conventions

A map of how `vip-go-api` is written, drawn from `AGENTS.md`, `api-wpvip-com/AGENTS.md`, and
`CLAUDE.md`. The repo guides win where this file is stale.

This file covers the API repo only. For the frontend repos (`vip-dashboard`, `vip-go-admin-console`)
no conventions are recorded yet: read that repo's own `AGENTS.md` / `CONTRIBUTING.md` / `docs/` and
any notes in `knowledge/`, then follow the sibling components you choose. If the repo is not cloned
under `workspaces/`, stop with `BLOCKED: repo access` rather than inferring its conventions. Confirm each point against the
two or three existing features you chose before relying on it, and update this file through a
separate, labelled change when you find it wrong.

## Contents

1. Where code goes
2. Where business logic lives
3. Module wiring (GOOP NestJS)
4. Endpoint shape
5. Naming
6. Error handling and sensitive data
7. Data access
8. Async
9. Tests: structure and rules
10. Parker specifics
11. Documentation that ships with code

---

## 1. Where code goes

GOOP (repo root):

- New features go in `src/` as NestJS modules. Touch `lib/` (legacy JavaScript, `lib/modules/`, `lib/routes/`) only when the behaviour you're changing already lives there.
- `schemas/` holds DB schemas and `test_data.sql`. No migrations anywhere; schema changes are expressed in `schemas/` for local/tests and documented in the PR for VIP SysEng to coordinate in production.
- `config/` holds configuration; `config/config.dev.json` stays untracked.
- Generated and build output is never hand-edited.

Parker (`api-wpvip-com/`):

- `src/routes/` REST endpoints, `src/resolvers/` GraphQL resolvers, `src/utils/` utilities, `schemas/` GraphQL schemas. New code is TypeScript.

Imports use the repo's path aliases (for example `@lib/...`, `@fixtures/...`); follow the formatter config rather than hand-formatting.

## 2. Where business logic lives

Controllers and route handlers are thin: parse and validate input, call a service, shape the response. Business rules, authorization decisions, and persistence live in injectable services (GOOP) or the equivalent service layer (Parker).

Authorization is enforced in the service or domain logic, not only at the controller. A controller-level guard is a convenience; the service must refuse on its own, because other callers (jobs, `lib/` bridges, other services) reach it without the controller.

Sensitive operations are recorded through `EventsService.recordEvent()`. Look at how sibling services call it to match the event names and payload shape.

## 3. Module wiring (GOOP NestJS)

- Inside `src/`, modules talk through module imports/exports and constructor injection. Never use a bridge from inside `src/`.
- `lib/` reaches NestJS services only through module-specific bridges. If you need a `src/` service from `lib/`, find the existing bridge for that module or add one in the same style; do not import the service class directly.
- Before adding a module dependency, check for cycles. If two modules would depend on each other, extract the shared provider into its own module instead. `forwardRef` in a service constructor is not an acceptable fix.

## 4. Endpoint shape

GOOP NestJS endpoints:

- Request bodies and query params are DTO classes validated with `class-validator` decorators. Look at a sibling DTO for the validation style (nullability, enums, nested objects).
- Responses go through `ResponseInterceptor` with a typed response DTO, so the wire shape is declared, not inferred.
- Site-scoped routes use `@SiteParam` with the correct `ACCESS_RESTRICTION_ACTIONS` value for the operation. The action must match what the route does (read vs modify), because that is what the access check enforces.
- Each new or externally visible endpoint carries an `@ApiOperation` description that includes a runnable local cURL example.

GOOP legacy routes (`lib/routes/`):

- Preserve the existing validation and authorization mechanisms in the route; do not swap in NestJS patterns piecemeal.
- Externally visible changes get an `@apiExample {curl}` block in the apiDoc comment.

Access model to keep in mind: `api_user → api_user_role → api_user_role_cap → api_resource`, permission bits `1=GET, 2=PUT, 4=POST, 8=DELETE`. New routes that e2e tests must reach need matching rows in `schemas/test_data.sql`.

## 5. Naming

Follow the names already in the module you're extending; consistency within a module beats consistency with this list. Typical shapes to expect and match:

- NestJS: `FooModule`, `FooController`, `FooService`, `CreateFooDto`, `FooResponseDto`, files in kebab-case (`foo.service.ts`, `foo.controller.spec.ts`).
- Integrations: a class extending `GenericIntegrationService`, registered in the integrations factory module, with its slug matching the `integration_center` row.
- Tests: `*.spec.ts` beside the unit for unit tests; e2e under the repo's e2e location per `docs/TESTING.md`. Test names describe behaviour ("rejects a non-admin with 403"), not implementation ("calls repository.save").
- Events recorded via `EventsService` follow the existing event-name vocabulary; grep for the nearest sibling before inventing one.

When the brief and the codebase disagree on a name, use the codebase's.

## 6. Error handling and sensitive data

- Errors are actionable to the caller and silent about internals: say what was wrong with the request, not which table or query failed.
- Never log, print, or return secrets, tokens, credentials, or PII. Sensitive fields are returned only when the authorized endpoint's contract requires them.
- In the integrations system, any config key that holds a secret goes in `encryptedConfigKeys`, and the frontend-facing API never exposes it.
- Use the repo's established exception types and HTTP mappings (look at how sibling services throw for not-found, forbidden, validation). Don't introduce a new error shape for one endpoint.

## 7. Data access

- Parameterized queries or the ORM/query builder only. Values are never interpolated into SQL.
- List reads are batched; per-item DB work inside a loop is bounded or removed. An N+1 pattern is a defect, not a style issue.
- Every query is scoped to its tenant (client, site, or network site). An ID from the request is not trusted until the tenant check has run. See the brief's "Tenant isolation" section for the specific scoping key.
- Timestamps are stored in UTC; any user-facing time handling follows the brief's "Timezones and time" section.
- `safeParseJson` from `@lib/utils/object` is the helper for JSON that might be malformed; don't reimplement it.

## 8. Async

- Async helpers return their Promises. A function that awaits internally but returns nothing makes errors vanish.
- Intentional fire-and-forget is marked with `void` and has its rejection handled; an unhandled rejection in a job is an outage.

## 9. Tests: structure and rules

Structure:

- Unit tests sit next to the unit as `*.spec.ts` and exercise the service with real internal collaborators where practical.
- External HTTP is mocked with MSW via `@fixtures/msw/server`. `nock` is not used.
- Jest needs MySQL and Redis available; check `docker ps`. e2e and live HTTP checks need GOOP running (`curl --max-time 10 http://localhost:2999/v1`).
- Fixtures live under `@fixtures/`; reuse before adding.

Rules (from `AGENTS.md`):

- Extend existing coverage. No duplicate cases, suites, or fixtures without a distinct regression to catch.
- Never mock the behaviour the test claims to verify. Mock boundaries, keep the thing under test real.
- Assertions must fail when the protected behaviour breaks.
- Spy arguments: `toHaveBeenCalledWith` with matchers, never positional `mock.calls` indexing.
- Access-sensitive changes: admin and non-admin users, including denied requests.
- Say what the mocks leave unverified. Passing call assertions do not prove authorization, persistence, redaction, or concurrency.

Commands: `npm run jest -- path/to/test -t 'name'` while iterating; `npm run jest`, `npm run lint`, `npm run check-types`, `npm run format:check`, `npm run build` before delivery. `docs/TESTING.md` is authoritative on what is required before merge.

## 10. Parker specifics

- Read `api-wpvip-com/AGENTS.md` before any Parker change; it may refine or override points above.
- Auth is SSO (WordPress.com or GitHub) with App Roles and Organization Roles; VIP-only features are gated by status. Match the existing role checks in sibling routes/resolvers.
- Parker reaches GOOP as Parker Bot, a least-privilege service account. If a Parker feature seems to need a GOOP permission Parker Bot lacks, that is a design problem to escalate, never a permission to add.
- Redis is used for caching; check how sibling resolvers key and invalidate before adding a cached read.
- Cross-API features ship GOOP first, then Parker, each verifiable on its own.

## 11. Documentation that ships with code

- New or changed endpoints: cURL example in `@ApiOperation` (NestJS) or `@apiExample {curl}` (legacy apiDoc).
- Schema changes: updated `schemas/`, plus a PR note for SysEng.
- Anything in `docs/` that your change makes stale gets updated in the same PR.
- The PR body states what was verified, how, and what the mocks leave unverified.
