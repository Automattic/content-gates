# Evidence environments

How `/factory` brings up the local stack that screenshots and recordings need (Step 10b, and Step 5c for bug fixes). The commands come from each repo's own `docs/SETUP.md` and `README.md`. Those files win if they disagree with this one; record the difference in the run report.

Status: **unverified**. No run has executed these recipes end to end yet. A step marked _(unverified)_ is a reading of the docs, not something a run has seen work. When a run confirms or corrects a step, the orchestrator updates this file between runs, never during one.

## Rules

- Local only. Every service is on `localhost`, every DB is a local dev DB. Never point a frontend at production or staging Parker or GOOP, even read-only. The Dashboard's `.env.development` defaults to production Parker, so a frontend started without the override is a non-negotiable violation, not a shortcut.
- Nothing from this file is committed. Overrides go in files the target repo already ignores (`.env.local` in vip-dashboard), or in environment variables on the command line. Do not edit a tracked file to get an environment up.
- Tokens are local secrets: capture them into a shell variable, pass them to the capture script through the environment, never echo or write them to a file, an artifact or `state.md`.
- Local DB changes for evidence (VIP flag, seed rows) are allowed only on the local dev DBs and are listed in `state.md` under `evidence-env:` so the human knows the DB was touched.
- Evidence files go to `runs/<run-id>/.artifacts/`, not into the worktree. Target repos do not ignore `.artifacts/` (checked for vip-dashboard, vip-go-admin-console and vip-go-api), so files there could be committed by accident.
- Confirm every port answers your code before capturing. See Health checks.

## Which stack a surface needs

| Surface changed | Services | Evidence |
|---|---|---|
| vip-dashboard | GOOP + Parker + Dashboard dev server | screenshots and/or video |
| vip-go-admin-console | GOOP + Admin Console dev server | screenshots and/or video |
| Parker only | GOOP + Parker | probe output (GraphQL/REST request and response pairs) |
| GOOP only | GOOP | probe output |
| vip-go-mu-plugins | none here; PHPUnit and its own tooling | test output; no recipe for a running WordPress site yet |

The "after" stack always runs from the run's worktrees (`workspaces/.worktrees/<run-id>/<repo>`). The "before" frontend runs from a temporary detached worktree at `origin/<default>` (see Before state).

## Ports

| Service | Port | Source |
|---|---|---|
| GOOP | 2999 | vip-go-api `docs/SETUP.md` |
| GOOP phpMyAdmin | 8181 | vip-go-api `docs/SETUP.md` |
| GOOP MariaDB | 3306 | `docker-compose.yml` |
| Parker | 4000 (GraphQL at `/graphql`) | `api-wpvip-com/docs/SETUP.md` |
| Parker MariaDB | 3307 | `docker-compose.yml` |
| Parker Redis | 6379 | `docker-compose.yml` |
| Parker phpMyAdmin | 8182 | `docker-compose.yml`, vip-dashboard `README.md` |
| Dashboard dev ("after") | 3000 | vip-dashboard `AGENTS.md` |
| Admin Console dev ("after") | 3001 | vip-go-admin-console `docs/SETUP.md` |
| Dashboard dev ("before") | 3002 | factory choice |
| Admin Console dev ("before") | 3003 | factory choice |

Parker's CORS allows only `http://localhost:3000` (vip-dashboard `README.md`), so the "before" Dashboard on 3002 may be refused _(unverified)_. If it is, capture "before" first on 3000 from the base worktree, stop it, then start the "after" Dashboard on 3000. Do not change Parker's CORS settings.

## Prerequisites

- Docker. On macOS this is Colima plus the standalone `docker-compose` (`colima start`). Check with `docker ps`.
- Node per each repo's `.nvmrc` (nvm).
- Playwright through npx, never added to a project: `npx --yes --package=playwright node <script>.mjs`. The first run needs `npx --yes playwright install chromium`.
- `before-and-after`: `npm install -g @vercel/before-and-after`.

Installing a missing tool needs the human's approval, as in any run.

## GOOP

From the vip-go-api worktree root:

```bash
docker-compose up -d
```

The local DB is seeded from `schemas/local_data.sql`. A run that changed `schemas/` needs `docker-compose down -v && npm run build && docker-compose up -d`. That resets the local GOOP DB, which is allowed because it is a local dev DB.

Health: `curl -fsS localhost:2999/v1/status/check`.

## Parker

Parker needs GOOP running. From the vip-go-api worktree:

```bash
(cd api-wpvip-com && npm install && npm run build)   # dist/ must exist, or startup fails on ./dist/schemas
docker-compose --profile parker up -d                 # plain `docker-compose up` starts GOOP only
```

After a code change in Parker, rebuild and restart: `docker-compose down && (cd api-wpvip-com && npm run build) && docker-compose --profile parker up -d`.

Health: `curl -fsS -X POST localhost:4000/graphql -H 'Content-Type: application/json' -d '{"query":"{ __typename }"}'` _(unverified: a request without a token may be refused; any GraphQL JSON response, including an auth error, shows Parker is up)_.

Token for local user 1. Do not use `bin/generate-token.sh` here: it runs `docker exec -it`, which needs a TTY the agent does not have.

```bash
PARKER_TOKEN=$(docker exec -e SKIP_REDIS_CONNECT=true parker_app dist/bin/generate-token.js 1)   # do not echo
```

_(unverified: the exact output format; strip anything that is not the token.)_

VIP-only features (`[forVIP]` flags, `@isVIP`) need the local user to be VIP:

```bash
docker exec parker_db mysql -uroot api -e "UPDATE users SET is_vip = 1 WHERE id = 1"
```

_(unverified: the container user, DB name `api` from `docker-compose.yml`, and whether the root password is empty.)_ For the non-VIP capture, set it back to 0.

App and environment data: the Dashboard page needs a Parker app and environment that maps to a GOOP site from `local_data.sql`. _(unverified: whether Parker's local seed provides one. If it does not, find the minimal local-only seed in Parker's `schemas/` and `docs/TESTING.md` and record the step here after the run.)_

## VIP Dashboard

From the vip-dashboard worktree. `npm ci` is already done by `new-feature`.

```bash
grep -q '^NEXT_PUBLIC_API_BASE_URL=' .env.local 2>/dev/null || echo 'NEXT_PUBLIC_API_BASE_URL="http://localhost:4000"' >> .env.local
npm run dev            # long-running: start in the background, port 3000
```

`.env.local` is in vip-dashboard's `.gitignore`. Check with `git check-ignore .env.local` before writing it. If the file already has a different `NEXT_PUBLIC_API_BASE_URL`, stop and ask; do not overwrite it.

Sign-in: Playwright sets the token in `localStorage` before the app loads, per the README's workaround:

```js
await context.addInitScript((t) => window.localStorage.setItem("vipAccessToken", t), process.env.PARKER_TOKEN);
```

Health: `curl -fsS -o /dev/null localhost:3000`. Then check that the page's first GraphQL request goes to `localhost:4000`, not `api.wpvip.com`, either from Playwright's request log or with `page.on('request')`. A request to `api.wpvip.com` means the override did not apply: stop capture at once.

## Admin Console

Needs GOOP. From the vip-go-admin-console worktree:

```bash
npm run dev            # long-running, port 3001; BFF_GOOP_URL defaults to http://localhost:2999 in dev
```

Never set `BFF_GOOP_URL` to the production API.

Sign-in: Playwright opens `http://localhost:3001`, then POSTs `/auth/dev-login` with `X-Console-Request: 1` and a JSON body `{userId, accessToken}` for a local GOOP user (see the repo's `README.md`, "Local development login"). The BFF sets an HttpOnly cookie, so the session lives in the Playwright context. _(unverified: which local GOOP user and token in `schemas/local_data.sql` can read its own profile. Find one and record it here, without the token value.)_

The "before" console on 3003 needs `PORT=3003 BFF_ORIGIN=http://localhost:3003 npm run dev`.

## Before state

- **Features:** create a temporary worktree with `git -C workspaces/<repo> worktree add --detach workspaces/.worktrees/<run-id>/<repo>-before origin/<default>`. Run `npm ci` in it and start the frontend on its "before" port against the same local backends. If the run changed the backend, say so beside the "before" image, because the base frontend is talking to the new backend. Remove the worktree after capture (`git worktree remove`).
- **Bug fixes:** capture "before" from the run's own worktree in Step 5c, before any builder commits. No second worktree is needed.

## Health checks (all surfaces)

Before capturing, for every service:

1. The health request above succeeds.
2. `lsof -nP -iTCP:<port> -sTCP:LISTEN` shows a listener. For a Node dev server, check the PID's working directory is the right worktree: `lsof -a -p <pid> -d cwd`.
3. For Docker services under Colima, `lsof` shows Colima's `ssh` forward, not the app. Check `docker inspect -f '{{range .Mounts}}{{.Source}} {{end}}' <container>` instead. The bind mount must be this run's worktree. If it is another checkout (left over from another run or session), stop that stack first, but only if `state.md` shows this factory started it. Otherwise ask.

## Teardown

- Always stop the dev servers (Dashboard, Admin Console, both "before" and "after") and remove the "before" worktree once capture is done.
- Leave the Docker stacks running until the run ends. List them in `report.md` under cleanup: `docker-compose --profile parker down` in the vip-go-api worktree, then `colima stop`.

## Known traps

- `docker-compose up` without `--profile parker` starts GOOP only. Parker's three containers are profile-gated. (Run 20261012-sourcepoint-cmp-45cd.)
- Parker fails with `ENOENT ... ./dist/schemas` if `npm run build` was not run in `api-wpvip-com/`.
- Compose V2 can fail with `getaddrinfo ENOTFOUND parker_db` (Parker `docs/SETUP.md`). The standalone `docker-compose` binary used on this machine _(unverified for this error)_.
- Colima's port forward hides the real listener from `lsof`; use `docker logs` and `docker inspect`.
- `bin/generate-token.sh` uses `docker exec -it` and fails without a TTY.
