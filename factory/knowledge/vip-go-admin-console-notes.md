# vip-go-admin-console — repo notes

_Appended by /factory from codebase-researcher output. Run 20261008-integration-frontend-1ebf._

## Repo notes

Next.js 16 static export + React 19, plain JavaScript (TS only on request), `@automattic/vip-design-system` + Theme UI. Layout: `app/` (routes/UI), `lib/` (client GOOP access, `goopFetch`), `shared/` (framework-free helpers), `server/` (BFF, CSP/Helmet), `e2e/`. Default branch `production`; branches `add/`, `update/`, `fix/`. Guides: `AGENTS.md` (= `CLAUDE.md`), `docs/ARCHITECTURE.md`, `docs/SETUP.md`, `docs/CONTRIBUTING.md`. Tests: `node --test` beside modules, Playwright `npm run test:e2e`; lint `npm run lint`. Integrations surface as a fully generic `IntegrationsSection` (status + delete only) on organization and environment pages, over GOOP `/v1/vip-integrations/backend/*`.
