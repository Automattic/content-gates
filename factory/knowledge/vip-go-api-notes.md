# vip-go-api — repo notes

_Appended by /factory from codebase-researcher output. Run 20261007-admin-reset-2fa-4965._

## Repo notes

Parker (`api-wpvip-com/`): Express + Apollo GraphQL; JS/TS (new code TS), Babel build to `dist/`. Models in `src/models/` own authorization (`Model.userCan`, `loadedFor` pattern) and audit (`recordEvent` → GOOP `/event_log`). Org roles/capabilities hardcoded in `src/utils/acl.ts`. Schema directives: `@isVIP`, `@requireElevatedPermission`, `@rateLimit`. SQL in `schemas/schema.sql` + `schemas/migrations/`. Tests: Jest + jest-extended in `__tests__/`, `npm run test` (lint+jest); fixtures via `testUtils.ts` `generateUser`/`getContext`; external HTTP mocked with **nock** (not MSW — the MSW rule matches GOOP); env stubs in `setupTests.js`. Auth: VIP Auth OIDC (`src/routes/oauth/vip-auth.ts`), sudo re-auth via `/rechallenge`. No `docs/` dir in this clone.

2FA/MFA: not stored in vip-go-api at all. Lives in VIP Auth, an external WordPress-based IdP (`VIPAUTH_DOMAIN`). Parker stores only `users.vip_auth_id` and proxies reads via `GET /wp-json/auth/v1/user/mfa`. `WPInstallationTwoFactorStatus` is a *different* concept (site-user 2FA on customer installs, GOOP security-boost integration).

Default branch: `trunk`.

## Repo notes — integrations (run 20261008-integration-frontend-1ebf)

Integrations are code-defined, not DB-defined: `src/vip-integrations/integrations/integrations.const.ts` is the catalog; `integration_center` only caches marketing copy synced from an external WordPress site ("The WPVIP Integration Center", `integration_center_remote_url`). Three API surfaces under `src/vip-integrations/`: `frontend-api/` (Parker/Dashboard), `backend-api/` (Admin Console), `integrations-center/` (catalog content). Parker re-exposes these as GraphQL (`Integration.graphql`, `IntegrationCenter.graphql`) via `src/utils/integrations.ts`, authorized by `integration:list` / `integration:manage` in `src/utils/acl.ts`.

## From run 20261012-sourcepoint-cmp-45cd (codebase-researcher)

Integration slug is validated by `@IsEnum(VipIntegration)` in GOOP request DTOs; Parker's `manageIntegration` forwards slug as `String!` with no per-slug logic. GOOP Jest needs the docker-compose MariaDB/Redis up (per-suite databases). Only `tollbit` has a per-integration `*.e2e.spec.ts`.
