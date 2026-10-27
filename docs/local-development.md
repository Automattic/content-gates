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

There is no local edge. To see what the edge would do with a request, use
the headers origin sends (`X-WPVIP-Content-Gates-Declared`, the meter
header) and the edge suites (`npm run test:edge`), which run the worker's
phases in memory. Hits, misses, and narrowing are observed on a VIP
application only.

`npm run doctor` checks the toolchain, Docker, both wp-env instances, and
who owns the ports.

## Demo flows

Join and leave segments through the demo provider, which stands in for a
real entitlement provider and only loads where `WP_ENVIRONMENT_TYPE` is
`local`:

- **In a browser:** open `/segments/`, tick segments, save. This is the
  example provider in `examples/segment-provider/`. Any front-end page with
  `?content_gates_demo=1` shows the declared set and the raw membership in a
  corner box, with a link to `/segments/`; responses with the box are never
  cached.
- **With curl, REST path:**

  ```bash
  curl -s -c jar -X POST -H 'Content-Type: application/json' \
    -d '{"segment":"subscriber","action":"assign"}' \
    http://localhost:8888/wp-json/content-gates-dev/v1/membership
  curl -s -b jar http://localhost:8888/gated-article/ | grep -c SUBSCRIBER-ONLY   # 1
  curl -s        http://localhost:8888/gated-article/ | grep -c SUBSCRIBER-ONLY   # 0
  ```

- **With curl, form path (post-redirect-get):** the example provider's
  README has the recipe (fetch the nonce from `/segments/`, POST it back).
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

Things to try against origin. The edge forwards a crawler's classification
and a reader's membership as the `vip-go-seg` cookie, so a cookie set by
hand stands in for it:

| Try | Expect |
| --- | --- |
| `curl -si http://localhost:8888/gated-article/` | No `SUBSCRIBER-ONLY`; `X-WPVIP-Content-Gates-Declared: crawlers-ai, crawlers-search, subscriber` (what the edge may key on) |
| `curl -s -b 'vip-go-seg=vc-v1__newsletter_--_1' http://localhost:8888/gated-article/` | Same bytes: the URL does not declare `newsletter`, and the edge would serve the same copy |
| `curl -s -b 'vip-go-seg=vc-v1__subscriber_--_1' http://localhost:8888/gated-article/` | The article, and the prompt is gone |
| `curl -si http://localhost:8888/public-post/` | `X-WPVIP-Content-Gates-Declared:` empty: one copy for everyone |
| `curl -s -b 'vip-go-seg=vc-v1__crawlers-search_--_1' http://localhost:8888/gated-article/` | Full article, `content-gates-gated-1` class, JSON-LD in the footer, no `Set-Cookie` |
| `curl -si -b 'vip-go-seg=vc-v1__metered-articles_--_open' http://localhost:8888/metered-article/` | `METERED-BODY` and `X-WPVIP-Content-Gates-Meter: metered-articles; id=…; limit=3; period=2592000`; with `wall`, `METERED-WALL` |
| Deactivate `subscriber` on Settings > Content Gates | `content_gates_purge_all` fires (the site purge on VIP); subscribers see the gate until reactivation |

The meter counting down across views is the worker's job on hits and
misses alike; `npm run test:edge` shows it in `sequence.test.ts`.

## Commands

```bash
npm run env start | stop | destroy          # dev site (.wp-env.json)       :8888
npm run env:tests start | stop | destroy    # tests site (.wp-env.tests.json) :8889
npm run seed                                # (re)seed the dev site
npm run build | start                       # editor script, once or watching
npm run lint                                # PHPCS + PHPStan + ESLint
npm run test:php                            # PHPUnit in the tests container
npm run test:edge                           # Edge Worker logic, contract, and sequences, Node only
npm run test:js                             # editor pure modules, Node only
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
| `X-WPVIP-Content-Gates-Key` | The key the edge resolved the request to: the narrowed segmentation value, `none`, `bypass`, or `-` when phase 3 saw no phase 1 marker |

On a VIP application, comparing `X-VIP-Go-Segmentation-Debug` with
`X-WPVIP-Content-Gates-Key` shows what the edge narrowed away.

## When something misbehaves

- `npm run env run cli tail wp-content/debug.log`: `WP_DEBUG_LOG` is on.
  Undeclared reads log `_doing_it_wrong()` notices naming the segment.
- `wp content-gates declared <post_id> --recompute` recomputes a post's
  stored set from its blocks.
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
- Nothing local emulates WPVIP's edge. The edge suites test the worker
  under a stated contract; [known-limitations.md](known-limitations.md)
  lists what only a VIP application can show.
