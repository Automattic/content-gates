# Debugging and analysis tools

Scripts here help observe cache, cookie, and segment behavior without
re-instrumenting the plugin. Nothing here ships with the plugin. Add a tool
here when a failure is hard to observe, and link it from the Diagnostics
section of `AGENTS.md`.

## `edge-sim.mjs`: local WPVIP edge cache simulator

A dependency-free Node 20+ HTTP proxy that sits in front of a WordPress
origin and behaves like the parts of the VIP page cache that matter to
content gating. Use it to check cache-key correctness locally: which readers
share a stored copy, which get their own, and which requests bypass the
cache. It follows the "direction A" design in `spec/content-gating-api.md`
(standard per-stored-response `Vary` matching, RFC 9111 section 4.1).

It is a test double, not a replica. Read "Variances from WPVIP" below before
drawing platform conclusions from it.

### Start it

```bash
npm run edge-sim -- --origin http://localhost:8889            # tests site
npm run edge-sim -- --origin http://localhost:8888 --port 8890 --verbose
```

Flags: `--origin <url>` (default `http://localhost:8889`), `--port <n>`
(default `8891`), `--verbose` (one line per request). It prints the listen
URL and the origin on start. Stop it with Ctrl-C. Never leave it running
during `npm run test:php`; that suite resets the origin database and the
simulator's store would go stale.

### What it does with a request

1. **Pass-through** (forwarded as is, never stored, counted as `passes`):
   any method other than GET/HEAD; any `Authorization` header; any path
   under `/wp-admin/`, `/wp-login.php`, `/wp-json/`, or `/wp-cron.php`; any
   `Cookie` containing `wordpress_logged_in_`, `wp-postpass_`,
   `comment_author_`, or `vip-go-cb` (VIP's nocache cookie).
2. **Membership**: the `vip-go-seg` cookie
   (`vc-v1__<group>_--_<value>---__<group>_--_<value>`) is parsed into a
   group-to-value map. The request is forwarded with the original `Cookie`
   header and an `X-VIP-Go-Segmentation: <cookie value>` header, which is
   what VIP's CDN does.
3. **Lookup**: stored copies are keyed by URL (path + query). Each copy
   remembers the segments its own render reported as consulted and the
   requester's values for only those segments (`none` when absent). A
   request hits a copy when its values for that copy's consulted segments
   match. Copies are tried in insertion order; the first match wins.
4. **Miss**: the request goes to origin (`Accept-Encoding: identity`,
   redirects not followed). The consulted set comes from the response's
   `X-Content-Gates-Vary` header (comma-separated, trimmed, sorted; empty
   when absent). The response is stored only when all of these hold: method
   GET, status 200, no `Set-Cookie`, and `Cache-Control` has none of
   `no-store`, `no-cache`, `private`, `max-age=0`. TTL is `max-age`, or
   300 s when origin sends none.
5. **Legacy `Vary`**: when the response has `Vary: X-VIP-Go-Segmentation`
   and no `X-Content-Gates-Vary`, the copy is keyed on every group in the
   cookie (the status quo `Vary_Cache` behavior) and flagged `legacy: true`
   in stats. A reader matches a legacy copy only with identical full
   membership. When both headers are present, `X-Content-Gates-Vary` wins.
   That is the simulator's assumption about what the edge should do, per
   the spec's "Key on the consulted set" decision.

Hop-by-hop headers are stripped in both directions. Everything else is
passed through. The draft spelling `X-Content-Gate-Vary` (singular) is also
accepted so the simulator and the spec agree while the header name settles.

### Read the response headers

| Header | Meaning |
| --- | --- |
| `X-Edge-Sim: HIT` | Served from the simulator's store. `Age` is seconds since it was stored. |
| `X-Edge-Sim: MISS` | Fetched from origin. `X-Edge-Sim-Stored: yes/no` says whether it was stored. |
| `X-Edge-Sim: PASS` | Bypassed the cache. `X-Edge-Sim-Reason` says why. |
| `X-Edge-Sim-Key` | The variant key: `subscriber=1, newsletter=none`, or `{}` when the response consulted nothing. |

Example session:

```bash
curl -sI http://127.0.0.1:8891/ | grep -i x-edge-sim          # MISS, key {}
curl -sI http://127.0.0.1:8891/ | grep -i x-edge-sim          # HIT,  key {}
curl -sI -H 'Cookie: vip-go-seg=vc-v1__subscriber_--_1' \
  http://127.0.0.1:8891/?p=1 | grep -i x-edge-sim
```

Until origin sends `X-Content-Gates-Vary`, every stored copy consults
nothing and the key is `{}` for every reader. That is expected: it means one
copy per URL for everyone.

### Control endpoints

Handled by the proxy itself, never forwarded:

| Call | Result |
| --- | --- |
| `GET /__edge-sim/stats` | `{ hits, misses, passes, urls: { "<path?query>": [ { consulted, variantKey, legacy, storedAt, maxAge, hitCount } ] } }` |
| `POST /__edge-sim/purge` | Purges everything. Returns `{ purged: n }`. |
| `POST /__edge-sim/purge?url=<path>` | Purges one URL (path + query, URL-encoded). |
| `POST /__edge-sim/reset` | Clears the store and the counters. |

```bash
curl -s http://127.0.0.1:8891/__edge-sim/stats
curl -s -X POST 'http://127.0.0.1:8891/__edge-sim/purge?url=%2F%3Fp%3D1'
curl -s -X POST http://127.0.0.1:8891/__edge-sim/reset
```

### Self-checks

```bash
node --test 'tests/debugging/*.test.mjs'
```

Node 21+ treats `node --test` arguments as glob patterns, so pass the
pattern (quoted) rather than the directory; `node --test tests/debugging/`
only works on Node 20.

`edge-sim.test.mjs` covers the pure functions (`parseSegmentCookie`,
`computeVariantKey`, `matches`, `createStore`, and the header parsers)
against the spec's worked example: readers A and B share one copy of
`/article-1`, C and D share another, E's `/about` has one copy for everyone,
and legacy mode keys on the whole cookie.

### Variances from WPVIP

Each of these is a known difference. Do not treat simulator results on these
points as platform evidence; the spec requires live VIP evidence for
platform claims.

- **REST is always pass-through.** VIP caches some anonymous REST GETs. The
  simulator never caches `/wp-json/`.
- **Default TTL is 300 s** when origin sends no `max-age`. VIP's default and
  its handling of `Expires` and `s-maxage` are not reproduced.
- **No encryption and no `vip-go-auth`.** Only the plain `vip-go-seg` cookie
  and `X-VIP-Go-Segmentation` header are handled. `X-VIP-Go-Auth` is not.
- **No stale-while-revalidate or stale-if-error.** Expired copies are
  dropped on the next lookup; there is no background revalidation.
- **No purge API integration.** Origin-side purges (post updates,
  `wpcom_vip_purge_edge_cache_for_url()`) do not reach the simulator. Use
  `POST /__edge-sim/purge`.
- **In-memory only.** The store is lost on restart and is not shared between
  processes. Single edge node; no PoP or shard behavior.
- **First-match semantics.** Copies for a URL are tried in insertion order
  and the first match is served. The real cache may select among matching
  variants differently; the correctness property being tested (a reader is
  never served a copy whose consulted-set values differ from theirs) holds
  either way.
- **`X-Content-Gates-Vary` wins over `Vary: X-VIP-Go-Segmentation`.** This
  is the behavior the spec asks the edge to adopt, not something VIP does
  today. Today VIP keys on the full header, which is the `legacy` path.
- **Origin sees its own host.** Requests are sent with the origin's `Host`
  (plus `X-Forwarded-Host`), so links in responses point at the origin, not
  the simulator. Request each URL through the simulator directly.
- **Response bodies are fully buffered**, and `Accept-Encoding: identity` is
  forced, so compression and streaming behavior are not represented.
- **Request header coalescing.** Only the listed cookies, paths, methods, and
  `Authorization` cause a bypass. VIP has further rules (for example
  specific query strings and file types) that are not reproduced.
