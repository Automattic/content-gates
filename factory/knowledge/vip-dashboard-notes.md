# vip-dashboard — repo notes

_Appended by /factory from codebase-researcher output. Run 20261008-integration-frontend-1ebf._

## Repo notes

Next.js App Router (`app/`) + React 19, TypeScript, Apollo Client against Parker GraphQL, `@automattic/vip-design-system` + Theme UI `sx`, `i18n-calypso`. Code in `src/` (`views/`, `components/`, `utils/hooks`, `state/`, `config/features*.ts`, `generated/`). Default branch `production`. Guide: `AGENTS.md` (= `CLAUDE.md`), `README.md`, `DEBUGGING.md`. Tests: Jest colocated `*.test.tsx` + `__mocks__/MockAppProviders.tsx`; E2E Playwright in `__tests__/e2e/`. Commands: `npm ci`, `npm run jest`, `npm run check-types`, `npm run lint`, `npm run generate`, `npm run test-e2e`. No MSW. Integrations: generic `[slug]` routes rendered from `integrationCenter` CMS blocks, gated by per-slug flags in `src/config/features.default.ts`; optional per-slug form in `src/components/Integrations/index.tsx`.

## From run 20261012-sourcepoint-cmp-45cd (codebase-researcher)

Per-integration gating = three flags (`route:/apps/:id/:environment/integrations/<slug>`, `route:/orgs/:id/integrations/<slug>`, `route:/integrations-center/<slug>`) via `useFeature().isIntegrationRouteEnabled`; unknown slugs fall back to `AppIntegrationMap.default` (toggle-only form). Integration union types live in `src/views/Integrations/types.ts`.
