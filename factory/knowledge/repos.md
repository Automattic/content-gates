# VIP repo registry

The repos the factory may read or change. `/factory` uses this to decide what to clone (on demand, into `workspaces/<repo>/`); `codebase-researcher` uses it to decide where a question's answer lives. Clones are caches: every run fetches and cuts its worktree from `origin/<default>`, so the registry, not the clone, is the source of truth for what exists.

Columns marked "confirm on clone" are not yet recorded. Fill them in from the repo itself the first time it is cloned, via the researcher's Repo notes, and never from memory.

## Core platform

| Repo | URL | What it is | Relation to the rest | Default branch | Contributor guide |
|---|---|---|---|---|---|
| `vip-go-api` | https://github.com/Automattic/vip-go-api (Parker: https://github.com/Automattic/vip-go-api/tree/trunk/api-wpvip-com) | GOOP (private API, repo root) and Parker (public API, `api-wpvip-com/`). Source of truth for platform state. | Everything below talks to one of these two. | `trunk` | `AGENTS.md` (GOOP), `api-wpvip-com/AGENTS.md` (Parker), `docs/` |
| `vip-dashboard` | https://github.com/Automattic/vip-dashboard | VIP Dashboard, the customer-facing UI. | Calls Parker. Uses `vip-design-system`. | confirm on clone | confirm on clone |
| `vip-go-admin-console` | https://github.com/Automattic/vip-go-admin-console | Admin Console (`admin.wpvip.com`), the internal VIP-engineer UI. | Calls GOOP. Likely uses `vip-design-system`. | confirm on clone | confirm on clone |
| `vip-cli` | https://github.com/Automattic/vip-cli | VIP-CLI, the command-line tool for interacting with and managing WordPress VIP applications. | Calls Parker. | confirm on clone | confirm on clone |

## Services

| Repo | URL | What it is | Relation to the rest | Default branch | Contributor guide |
|---|---|---|---|---|---|
| `vip-insights-service` | https://github.com/Automattic/vip-insights-service | Exposes actionable performance and security information to customers so they can gauge application health and make proactive improvements. | Listed in CLAUDE.md as a GOOP dependent ("Insights Service"). Surfaces in Dashboard. | confirm on clone | confirm on clone |
| `vip-auth` | https://github.com/Automattic/vip-auth | The VIP Authentication service (`id.wpvip.com`). | Parker auth is SSO via WordPress.com or GitHub; how this service relates is to be confirmed on clone. Treat as access-sensitive: any change here is Class-critical for review. | confirm on clone | confirm on clone |
| `vip-passport` | https://github.com/Automattic/vip-passport | Passport on VIP. | Purpose and relation to `vip-auth` to be confirmed on clone. Treat as access-sensitive until confirmed. | confirm on clone | confirm on clone |

## WordPress runtime

| Repo | URL | What it is | Relation to the rest | Default branch | Contributor guide |
|---|---|---|---|---|---|
| `vip-go-mu-plugins` | https://github.com/Automattic/vip-go-mu-plugins | Development repository for the WordPress VIP Go MU plugins (PHP, runs inside customer WordPress sites). | Source of `vip-go-mu-plugins-built`. Not Node; its own test and lint tooling. | confirm on clone | confirm on clone |
| `vip-go-mu-plugins-built` | https://github.com/Automattic/vip-go-mu-plugins-built | The assembled `mu-plugins` folder for VIP Go, without submodules. | Build output of `vip-go-mu-plugins`. **Do not edit directly**; changes go to the source repo. | confirm on clone | n/a (generated) |
| `vip-go-mu-plugins-ext` | https://github.com/Automattic/vip-go-mu-plugins-ext | External dependencies for the MU plugins, including Jetpack, WP-Parsely, and VIP integrations. Exists to automate external dependency management while keeping back-compat. | Consumed by `vip-go-mu-plugins` and bundled into `vip-go-mu-plugins-built`. Mostly vendored third-party code: a change here is usually a dependency update, not a feature, and the back-compat requirement means version bumps need the same care as code. The VIP integrations it carries are the WordPress-side counterpart of the GOOP integrations system. | confirm on clone | confirm on clone |

## Shared libraries and kits

| Repo | URL | What it is | Relation to the rest | Default branch | Contributor guide |
|---|---|---|---|---|---|
| `vip-design-system` | https://github.com/Automattic/vip-design-system | Design system components used throughout WordPress VIP. | Consumed by `vip-dashboard` and likely `vip-go-admin-console`. A change here affects every consumer; `frontend-builder` should reuse its components rather than adding to it unless the brief says so. | confirm on clone | confirm on clone |
| `vip-integrations-starter-kit` | https://github.com/Automattic/vip-integrations-starter-kit | Everything needed to develop a WPVIP integration. | Companion to the GOOP integrations system (`GenericIntegrationService`, `integration_center`). Reference material for integration work; rarely a change target. | confirm on clone | confirm on clone |

## Rules that follow from this registry

- A repo not listed here is not a factory target. If a request needs one, add it here first (a human decision), then run.
- `vip-go-mu-plugins-built` is generated output and is never a change target.
- `vip-auth` and `vip-passport` are authentication surfaces: treat every change as access-sensitive, and expect the validator to grade gaps there as critical.
- `vip-go-mu-plugins-ext` is mostly vendored third-party code with a back-compat promise. The CLAUDE.md rule against dependency bumps applies in reverse here: a dependency bump *is* the change, so the brief must say which consumers are affected and how back-compat is verified.
- `vip-design-system` is shared: prefer consuming it over changing it. A change to it needs the brief to say which consumers are affected and how they are verified.
- Relations marked "likely" or "to be confirmed" are inferences from the descriptions, not facts. Confirm against the code before relying on them in a brief.
