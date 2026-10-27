# Local development

Everything needed to run the prototype on a laptop, for a human or an agent.
Requirements: Docker (OrbStack or Docker Desktop), Node 22+ (`.nvmrc` pins
24), PHP 8.2+ with Composer.

## One-command setup

```bash
composer install && npm install   # tooling
npm run setup                     # build the editor script, start wp-env, seed demo data
```

`npm run setup` leaves a dev site at http://localhost:8888 (admin / password)
with the plugin active, the demo provider loaded, four segments
(`subscriber`, `newsletter`, `donor`, and the meter `metered-articles`), the
two VIP crawler segments, and seven demo posts whose text carries markers
such as `SUBSCRIBER-ONLY` so a response can be checked with `grep`.

Then, to see the edge behavior, start the simulator in front of it:

```bash
npm run edge:sim                  # http://localhost:8890 -> origin :8888
```

Use `:8890` as the site. Every response carries `X-Edge-Sim: HIT|MISS|PASS`
and `X-Edge-Sim-Key`, the narrowed cache key the request used.

`npm run doctor` checks the toolchain, Docker, both wp-env instances, and
who owns the ports.

## Demo flows

Join and leave segments through the demo provider, which stands in for a
real entitlement provider and only loads where `WP_ENVIRONMENT_TYPE` is
`local`:

- **In a browser:** open any front-end page with `?content_gates_demo=1`. A
  box in the corner shows the declared set and a Join/Leave button per
  segment. Responses with the box are never cached.
- **With curl, REST path:**

  ```bash
  curl -s -c jar -X POST -H 'Content-Type: application/json' \
    -d '{"segment":"subscriber","action":"assign"}' \
    http://localhost:8890/wp-json/content-gates-dev/v1/membership
  curl -s -b jar http://localhost:8890/gated-article/ | grep -c SUBSCRIBER-ONLY   # 1
  curl -s        http://localhost:8890/gated-article/ | grep -c SUBSCRIBER-ONLY   # 0
  ```

- **With curl, form path (post-redirect-get):**

  ```bash
  curl -s -c jar -D - -o /dev/null -d content_gates_dev_assign=subscriber http://localhost:8890/gated-article/
  ```

- **Through the example provider:** open `/segments/` (or
  `/?content_gates_provider=1`). The page lists every active reader segment
  with a checkbox; save, and the browser carries the membership. It is the
  smallest complete provider, in `examples/segment-provider/`, and shows
  the write window, the post-redirect-get, and the `no-store` page a real
  provider needs. No login: anyone can grant themselves anything, which is
  why it is never deployed.
- **As an editor:** the admin bar shows **View as** on every front-end page.
  Pick segments and a meter state; the response is `Cache-Control:
  no-store` and never reaches the edge.

Things to try, with the simulator running:

| Try | Expect |
| --- | --- |
| Open `/gated-article/` twice anonymously | `MISS` then `HIT`; no `SUBSCRIBER-ONLY` |
| Join `newsletter`, open it again | `HIT` with the same empty key: the URL does not declare `newsletter` |
| Join `subscriber` | `MISS` with key `vc-v1__subscriber_--_1`, then `HIT`s; the article is there and the prompt is gone |
| Open `/public-post/` with any membership | One copy for everyone; `X-Edge-Sim-Key: -` (nothing to vary on) |
| `curl -A Googlebot http://localhost:8890/gated-article/` | Full article, `content-gates-gated-1` class, JSON-LD in the footer, no `Set-Cookie` |
| Open `/metered-article/`, `/metered-article-2/`, `/metered-article-3/`, then the first again in one cookie jar | First visit shows the gate and sets `open`; then three articles; then `METERED-WALL` |
| Deactivate `subscriber` on Settings > Content Gates | The simulator's store is purged; subscribers see the gate until reactivation |
| `curl -s http://localhost:8890/__edge/stats` | Stored copies per key and the KV record per URL |

## Commands

```bash
npm run env start | stop | destroy          # dev site (.wp-env.json)       :8888
npm run env:tests start | stop | destroy    # tests site (.wp-env.tests.json) :8889
npm run seed                                # (re)seed the dev site
npm run edge:sim -- --origin http://localhost:8888 --port 8890 --verbose
npm run build | start                       # editor script, once or watching
npm run lint                                # PHPCS + PHPStan + ESLint
npm run test:php                            # PHPUnit in the tests container
npm run test:edge                           # Edge Worker logic and simulator, Node only
npm run test:e2e                            # Playwright against the tests site (seeds it)
npm run edge:install                        # once: edge-workers/ dependencies
npm run edge:build                          # regenerate crawlers-config.ts, then `vip edge-workers build` -> edge-workers/build/content-gates.wasm
```

WP-CLI, from the host:

```bash
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates list
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates create "VIP members"
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates rename vip-members "VIP readers"
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates deactivate vip-members
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates activate vip-members
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates meter metered-articles --limit=5 --period-days=30
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates declared 18
npm run env run cli --env-cwd=wp-content/plugins/content-gates wp content-gates sync-crawlers
```

`wp help content-gates <subcommand>` documents each one.

## Reading a response

| Header | Meaning |
| --- | --- |
| `X-WPVIP-Content-Gates-Declared` | What this URL may read. Empty means one copy for everyone |
| `X-WPVIP-Content-Gates-Meter` | A meter is in play on this singular page |
| `Vary: X-VIP-Go-Segmentation` | `Vary_Cache` is active (any group registered) |
| `X-VIP-Go-Segmentation-Debug` | Full membership, `WP_DEBUG` only, from `Vary_Cache` |
| `X-WPVIP-Content-Gates-Key` | The key the edge resolved the request to: the narrowed segmentation value, `none`, `bypass`, or `-` when phase 3 saw no phase 1 rewrite |
| `X-Edge-Sim`, `X-Edge-Sim-Key`, `X-Edge-Sim-Record` | Simulator only: hit status, the narrowed key, the KV record |

Comparing `X-VIP-Go-Segmentation-Debug` with `X-WPVIP-Content-Gates-Key`
shows what the edge narrowed away.

## When something misbehaves

- `npm run env run cli tail wp-content/debug.log`: `WP_DEBUG_LOG` is on.
  Undeclared reads log `_doing_it_wrong()` notices naming the segment.
- `wp content-gates declared <post_id> --recompute` recomputes a post's
  stored set from its blocks.
- `curl -s http://localhost:8890/__edge/stats` and
  `curl -X POST http://localhost:8890/__edge/reset` inspect and clear the
  simulator.
- Playwright failures keep a trace, video, and `error-context.md` under
  `artifacts/test-results/`; open with `npx playwright show-trace`.

## Gotchas

- The two wp-env sites share nothing, but PHPUnit wipes the tests database.
  Never run `test:php` while `test:e2e` is in flight.
- `npm run test:e2e` seeds the tests site through WP-CLI in our container,
  whatever `WP_BASE_URL` says (autoPort may have moved the site). Set
  `CONTENT_GATES_E2E_EXTERNAL=1` only to run against a site that is not our
  container; that site must already carry the seed.
- The editor script must be built (`npm run build`) or the Group block has
  no Segments control; the admin shows a notice when it is missing.
- The simulator is a stand-in for WPVIP. Its variances are listed in
  [edge-worker.md](edge-worker.md).
