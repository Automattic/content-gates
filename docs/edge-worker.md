# Edge Worker

The Edge Worker is the WPVIP half of Content Gates. It runs at the CDN
layer and does three jobs the plugin cannot do from origin:

1. **Declared-set narrowing.** Rewrite the cache key so a URL has one copy
   per combination of the segments it actually declares, not per
   combination of every segment the reader is in.
2. **Crawler classification.** Decide per request whether the client is a
   search or AI crawler, and make sure only the worker can say so.
3. **Anonymous metering.** Count free views in signed cookies and advance
   the meter on cache hits as well as misses, where origin never runs.

The design is in [spec/page-cache.md](../spec/page-cache.md). This page
is the operator's and reviewer's guide to what the code does.

## Layout

`edge-workers/` is a VIP CLI project, laid out by `vip edge-workers init`
and `vip edge-workers new content-gates`: a project descriptor
(`edge-workers.json`), its own `package.json` with the SDK and the
AssemblyScript compiler at the versions the CLI pins, one worker under
`workers/`, and shared modules under `lib/`. The CLI discovers workers by
their `worker.json` and owns the compiler flags, so every customer compiles
the same way.

| Path | What it is |
| --- | --- |
| `edge-workers/workers/content-gates/worker.json` | The worker manifest: name, entry, no `location` (every request), `on_failure: error`. |
| `edge-workers/workers/content-gates/assembly/index.ts` | The worker. The only file that talks to the SDK. |
| `edge-workers/lib/worker.ts` | The three phases as pure functions over plain inputs. |
| `edge-workers/lib/segmentation.ts` | `Vary_Cache` format: parse, serialize, narrow. |
| `edge-workers/lib/declared.ts` | Declared header, KV record format, coverage check. |
| `edge-workers/lib/meter.ts` | Meter header, signed cookie, state machine. |
| `edge-workers/lib/crawlers.ts` | Classification against the compiled-in crawler list. |
| `edge-workers/lib/crawlers-config.ts` | Generated from `config/crawlers.json` by `scripts/crawlers-config.mjs`. |
| `edge-workers/lib/cookies.ts`, `crypto.ts`, `httpdate.ts` | Small helpers. |
| `edge-workers/sim/` | Local page-cache emulator around the same pure modules. |
| `edge-workers/tests/` | `node:test` suites for the modules and the simulator. |
| `edge-workers/scripts/crawlers-config.mjs` | Regenerates the crawler module before a build. |

Everything under `edge-workers/lib/` compiles under
AssemblyScript **and** runs unchanged under Node. That is why the code
avoids `Map`, closures, `for...of`, regex, template literals, and `===`
on strings, and why hashing is injected (`Crypto`) instead of imported:
the worker passes the SDK's host crypto, the simulator passes
`node:crypto`. One implementation, tested once, deployed twice.

## Request flow

```
Browser                      Edge (worker + cache)                       Origin
  |                                 |                                       |
  | GET /article-1/                 |                                       |
  | Cookie: vip-go-seg=...          |                                       |
  |-------------------------------->|                                       |
  |                 [phase 1] parse cookie, strip crawler groups,           |
  |                           classify UA, read KV record,                  |
  |                           narrow X-VIP-Go-Segmentation,                 |
  |                           rewrite Cookie, set marker headers            |
  |                                 |                                       |
  |                       cache lookup: URL + narrowed value                |
  |                                 |-- miss ------------------------------>|
  |                                 |   X-VIP-Go-Segmentation: narrowed     |
  |                                 |   X-WPVIP-Content-Gates-Edge: 1       |
  |                                 |<--------------------------------------|
  |                                 |   Vary: X-VIP-Go-Segmentation         |
  |                                 |   X-WPVIP-Content-Gates-Declared: ... |
  |                                 |   X-WPVIP-Content-Gates-Meter: ...    |
  |                 [phase 3] coverage check -> maybe no-store,             |
  |                           replace KV record with declared set           |
  |                                 |                                       |
  |                       store (unless no-store)                           |
  |                                 |                                       |
  |                 [phase 4] on hit or miss: advance meters,               |
  |                           rewrite Set-Cookie (humans only)             |
  |<--------------------------------|                                       |
```

### Phase 1: `onClientRequest` (before cache lookup)

Runs on every request: `worker.json` has no `location`, because any URL
may serve gated content and the platform's only pre-invocation condition
is a single path rule with no negation. The "except" list is in the
worker instead (`lib/bypass.ts`):

0. **Bypass.** Non-GET/HEAD, `Authorization`, paths under `/wp-admin/`,
   `/wp-login.php`, `/wp-cron.php`, `/xmlrpc.php`, `/wp-content/`,
   `/wp-includes/`, static file extensions, and sessions marked by
   `wordpress_logged_in_`, `wp-postpass_`, `comment_author_`, or
   `vip-go-cb` cookies. None of these responses can be stored under a
   segmented key, so the worker sets only the version header and
   `x-wpvip-content-gates-lookup: bypass` and returns before the KV read.
   Phase 3 treats the mark as "never store"; phase 4 skips metering. On an
   article page with forty assets, this turns forty-one KV reads into one.
1. Parse `vip-go-seg` from the `Cookie` header and **drop every crawler
   group** (`crawlers-ai`, `crawlers-search`, ...). A browser that sends
   `crawlers-search_--_1` gains nothing.
2. Classify the `User-Agent` against `config/crawlers.json`
   (case-insensitive substring) and add `<key>=1` for each match.
3. Read the KV record for this URL (`cg:` + first 32 hex characters of
   `sha256(path + query)`).
4. Set `X-VIP-Go-Segmentation`:
   - **No record:** the full serialization of the reader's groups. This is
     today's keying and is always safe.
   - **Record present:** only the reader's values for the recorded groups,
     in `Vary_Cache` format. Empty string when none. An empty record
     (`v1:`, a page that declares nothing) yields one copy for everyone.
5. Set `X-WPVIP-Content-Gates-Edge` to the crawlers.json version, so the
   plugin can show a notice on version skew.
6. Rewrite the forwarded `Cookie` header so `vip-go-seg` holds the
   reader's non-crawler groups plus the worker's own classification. The
   browser cookie itself is never narrowed.
7. Set marker headers for phase 3 (see below).

### Phase 3: `onOriginResponse` (cache miss, after origin answers)

Only acts on responses whose `Vary` names `x-vip-go-segmentation`. A
response without it never ran `Vary_Cache`, so it is unsegmented and is
cached as usual.

0. **Markers missing:** the request snapshot lacks
   `x-wpvip-content-gates-lookup`, carries an unknown value, or says
   `narrowed` without a record marker -> `no-store`, and nothing is
   learned. Phase 3 cannot tell how the key was built, so it fails closed.
   If the platform ever drops `resp.request`, this costs origin requests,
   never an unchecked store.
1. **Undeclared:** `X-WPVIP-Content-Gates-Declared` missing ->
   `setCacheControl("no-store")`. The response skipped the plugin's
   `send_headers` callback, so its reads are unknown.
2. **Coverage check:** every declared group must be in the set of keys
   phase 1 narrowed with. When phase 1 used the full value (no record),
   the response is covered. When a declared group was narrowed away ->
   `no-store`, because the copy would be stored under a key that does not
   distinguish readers who differ in that group.
3. **Record update:** replace the KV record with the declared set. Replace,
   never merge, so the record shrinks when an editor removes a segment.
   The write is skipped when a narrowed lookup already used exactly this
   set.

The check is on the **record's keys**, not on the lookup value. A reader
who is simply not in a declared group has no value for it; their copy is
still correctly keyed, and storing it is what makes the anonymous copy of
a gated article cacheable at all.

### Phase 4: `onClientResponse` (every delivery, hit or miss)

Only acts when the response carries one or more
`X-WPVIP-Content-Gates-Meter` headers, the client is not a crawler, the
secret is configured, and the response `Date` is readable.

1. Verify `vip-go-meter`. Missing, foreign, or badly signed -> fresh
   record with count zero.
2. Reset a record whose period has elapsed.
3. Count the view only if the meter value the request carried was `open`
   or `last` (content was delivered) and the post ID is not in the seen
   list.
4. Next state: `open` when more than one view remains, `last` when
   exactly one, `wall` when none. A first visit (no cookie) gets a fresh
   record and is not counted; the content was withheld.
5. Write `Set-Cookie` for `vip-go-meter` and for `vip-go-seg` with the
   meter state merged into the reader's other non-crawler groups. Those
   groups come from **origin's `vip-go-seg` `Set-Cookie` on this
   response** when there is one (an expiring field means no groups), and
   from the request cookie otherwise. On a metered miss the provider may
   have just assigned or removed a group; reading the request cookie there
   would write the stale membership back over origin's decision. The
   response's `Set-Cookie` list is rewritten as a whole: other cookies pass
   through in place, origin's `vip-go-seg` field is replaced, and exactly
   one `vip-go-seg` reaches the browser, with origin's attributes when
   origin wrote the cookie.

## Carrying facts between phases

Phases run in isolation; module state resets between them. Phase 1 records
what phase 3 needs as request headers, which the host forwards to origin
and exposes in phase 3 through `resp.request` (the origin-socket snapshot):

| Header | Value |
| --- | --- |
| `x-wpvip-content-gates-lookup` | `full` or `narrowed` |
| `x-wpvip-content-gates-record` | The record phase 1 narrowed with, in KV record format (`v1:donor,subscriber`; `v1:` for an empty set), so the marker is never an empty header value |
| `x-wpvip-content-gates-kv` | The KV key, so phase 3 writes the same record phase 1 read |
| `x-wpvip-content-gates-crawler` | Crawler groups matched, comma separated (diagnostic) |

Phase 4's `resp.request` is the **client-socket snapshot before phase 1
ran**, so these markers are not visible there. Phase 4 re-derives what it
needs (user agent, cookies) from the raw request, which is what it wants
anyway: the browser's cookie, not the rewritten one.

Origin sees the marker headers. They are harmless and useful in logs.

## Diagnostic headers

Edge Workers expose no logs. Two response headers are the evidence of what
origin and the edge did, readable from outside with `curl -sI`. Both are
always on; see `spec/api.md`, "Diagnostic headers", for why they are not
gated.

| Header | Who | Value |
| --- | --- | --- |
| `X-WPVIP-Content-Gates-Declared` | origin | The groups this URL may read, sorted |
| `X-WPVIP-Content-Gates-Key` | phase 3, before the store | The key the request resolved to: the narrowed `X-VIP-Go-Segmentation` value, `none` for the anonymous copy, `bypass` when phase 1 left the request alone, `-` when phase 3 could not read phase 1's rewrite |

The key header is stored with the copy. A hit is by definition a request
that resolved to the stored copy's key, so the header is correct on hits
and misses alike. It is written whatever phase 3 decided, so a `no-store`
response still says what it was looked up under.

How to read a response, with the platform's cache status and the request
cookie next to it:

| Declared | Key | Meaning |
| --- | --- | --- |
| `1-subscribers, …` | `none` | Anonymous copy. The reader held none of the declared groups, or the record narrowed them all away |
| `1-subscribers, …` | `vc-v1__1-subscribers_--_1` | The subscriber copy |
| `1-subscribers, …` | `vc-v1__1-subscribers_--_1---__newsletter_--_1` | Narrowing failed to drop `newsletter`: no record yet (first request since purge) or a stale one |
| present | `-` | Phase 3 ran but saw no segmentation from phase 1: phase 1 did not run, or `resp.request` does not carry its rewrite (open question 8). Phase 3 marks the response `no-store` |
| present, `Vary` set | header absent | The worker did not run phase 3 on this response |
| absent, `Vary` set | any | Origin fault: a segmented response with no declared set. Phase 3 marks it `no-store` |

The simulator sets the same key header, next to its own `X-Edge-Sim-*`, so
a local run and a live run read the same way.

## KV record layout

| Key | Value |
| --- | --- |
| `cg:<32 hex of sha256(path+query)>` | `v1:<sorted group names, comma separated>` |

Examples: `v1:crawlers-ai,crawlers-search,subscriber`; `v1:` for a URL
that declares nothing. The `v1:` prefix distinguishes an empty set from an
absent record and lets a format change be detected. A record that fails
to decode is treated as absent, which falls back to full-value keying.

KV is not durable. An evicted record costs one extra miss per URL per
edge location, never a leak.

## Cookie formats

Both cookies use `Path=/` and no `Domain`, `Secure`, or `SameSite`, the
same attributes `Vary_Cache` sets, so the two writers of `vip-go-seg`
never disagree.

**`vip-go-seg`** (`Vary_Cache` format, shared with origin):

```
vc-v1__metered-articles_--_last---__subscriber_--_1
```

Groups sorted by name, `_--_` between name and value, `---__` between
groups, characters `[A-Za-z0-9_-]`. The worker writes it with
`Max-Age=86400` (one day), the plugin's default cookie lifetime
(`content_gates_cookie_lifetime`). The worker rewrites the whole cookie on
every metered view, so this value also bounds how long provider-assigned
groups outlive a lapsed entitlement; a longer value would extend them.

**`vip-go-meter`** (private to the worker, `HttpOnly`, `Max-Age` = the
longest period among the meters written):

```
v1.<record>~<record>.<signature>
record    = <group>:<periodStart>:<count>:<seen>
seen      = <hash>_<hash>_...             (may be empty)
hash      = first 8 hex chars of sha256(postId)
signature = first 32 hex chars of hmacSha256(secret, "v1.<payload>")
```

Example: `v1.metered-articles:1792670400:2:a1b2c3d4_deadbeef.3f9c...`.
The spec leaves the encoding open; this one uses only cookie-safe
characters, costs about nine bytes per counted article, and hashes post
IDs so a reader's history is not legible in the cookie. A forged or edited
cookie fails the signature and is treated as absent, which is no better
than deleting it.

## The secret

Phase 4 reads `CONTENT_GATES_METER_SECRET` with `getenv()`. When it is
unset or empty the worker does **no metering at all**: it writes no
cookies, so readers stay at the no-cookie state and metered Groups stay
withheld. Set it at deploy time as a worker environment variable; never
compile it in or commit it. The simulator takes `--secret` and defaults
to `local-dev-secret`.

## Time without a clock

AssemblyScript's `Date.now()` compiles to an `env.Date.now` import that
the VIP host is not documented to provide; a missing import would stop the
worker from loading. The worker therefore reads time from the response's
`Date` header plus `Age`. Precision is one second on a miss and within the
cache TTL on a hit, which is fine for periods measured in days. If the
`Date` header is unreadable, phase 4 does nothing for that response.

## Building

```bash
npm run edge:install  # once: edge-workers/ dependencies (SDK, AssemblyScript), its own package
npm run edge:build    # regenerates lib/crawlers-config.ts, then `vip edge-workers build --all`
npm run test:edge     # node:test suites for the pure modules and the simulator; no build needed
npm run lint:js       # ESLint over edge-workers/ (and the rest of the project)
```

`vip edge-workers build` compiles `workers/content-gates/assembly/index.ts`
with `asc --runtime stub --optimizeLevel 3 --shrinkLevel 2 --path
node_modules` into `edge-workers/build/content-gates.wasm` (gitignored).
The CLI owns those flags: they are a contract with the platform's WASM
validator. `npm run edge:build` runs the CLI through `npx` at a pinned
version so CI and laptops compile alike; with VIP-CLI installed globally,
`cd edge-workers && npm run build` is the same thing.

`lib/crawlers-config.ts` is committed so tests and the simulator run
without a build; `tests/crawlers.test.ts` fails when it is out of date
with `config/crawlers.json`.

The worker imports only `env.abort`, `crypto.sha256`, `crypto.hmac_sha256`,
`header.*`, `request.*`, `kv.get`, `kv.set`, and `env.env_get`. Check with
`wasm-objdump -x -j Import edge-workers/build/content-gates.wasm` (from
`wabt`) after a build, or temporarily add `--textFile` to the compile.

## Deploying

VIP-CLI compiles and uploads the worker from `edge-workers/`; the deployable
artifact is `build/content-gates.wasm`. Remote commands take an
`@<app>.<env>` target and need VIP Dashboard access to that environment.

```bash
cd edge-workers
vip @<app>.develop edge-workers validate content-gates   # server-side dry run, no upload
vip @<app>.develop edge-workers deploy content-gates     # compile, validate, upload (disabled)
vip @<app>.develop edge-workers enable content-gates     # start handling traffic
vip @<app>.develop edge-workers list                     # what is deployed, and its state
vip @<app>.develop edge-workers get content-gates
vip @<app>.develop edge-workers disable content-gates    # stop traffic; the worker stays deployed
```

`deploy --enable` does the upload and the enable in one step. A deployed
but disabled worker handles no traffic, so deploy first, read `list`, then
enable on a non-production environment.

`worker.json` has no `location`, so the worker runs on every request. That
is required: phase 1 must see every request to narrow the key. If the
manifest ever gains a `location`, note the CLI rule that removing the key
later does **not** clear a stored rule; set it to `null` to clear it.

`on_failure` is `error`. With `continue`, a worker that fails in phase 3
after phase 1 already narrowed the key would let the response be stored
unchecked, which is the one failure the design must not have. `error`
turns a worker fault into a failed request instead. Confirm on the
platform whether the policy is per phase or per worker before relying on
this for production.

**Still unverified:** how the `CONTENT_GATES_METER_SECRET` environment
variable is set for a worker. The CLI exposes no flag for it; until it is
set, the worker does no metering (see "The secret"). Two platform
requirements to confirm on the first deploy:

- Phase 3 uses `resp.request`, which the SDK README says requires a runtime
  with `request.*` host-function support. The diagnostic headers show the
  answer on the first response (see "Diagnostic headers").
- The worker must be allowed to rewrite `X-VIP-Go-Segmentation` and the
  `Cookie` header, and the cache must key on the rewritten value
  (spec/page-cache.md, platform question 1).

## Running the simulator

```bash
npm run env start                       # dev WordPress on :8888
npm run edge:sim -- --verbose           # emulated edge on :8890 -> :8888
curl -i http://localhost:8890/article-1/ -H 'Cookie: vip-go-seg=vc-v1__subscriber_--_1'
curl -s http://localhost:8890/__edge/stats | jq
curl -X POST http://localhost:8890/__edge/purge -d '{"url":"/article-1/"}'
curl -X POST http://localhost:8890/__edge/reset
```

Flags: `--origin` (default `http://localhost:8888`), `--port` (default
`8890`), `--secret` (default `local-dev-secret`), `--verbose`.

Every response carries three diagnostic headers:

| Header | Meaning |
| --- | --- |
| `X-Edge-Sim` | `HIT`, `MISS`, or `PASS` |
| `X-Edge-Sim-Key` | The narrowed segmentation value used for lookup, or `-` |
| `X-Edge-Sim-Record` | Declared groups known for the URL, `(empty)`, or `-` for none |

`GET /__edge/stats` returns hits, misses, passes, stored entries with
their keys, and KV records with the URL each belongs to. `POST
/__edge/purge` with `{"url": "/path"}` purges every variant of that URL
(what a post save does on VIP); without a body it purges everything.
`POST /__edge/reset` purges and clears KV.

From Playwright, `import { startEdgeSim } from '../../edge-workers/sim/server.ts'`
and call `startEdgeSim({ origin, port: 0, secret })`; it resolves to
`{ url, close() }`.

WordPress generates links with its configured home URL, so a browser that
enters through `:8890` leaves for `:8888` on the first click unless
`WP_HOME`/`WP_SITEURL` point at the simulator. For header-level checks
with `curl` or `fetch` this does not matter.

## Variances from WPVIP

Everything the simulator guesses. Treat each as "plausible, not
confirmed" until measured on VIP.

- **Cache key composition.** Key is method + path + query + the narrowed
  `X-VIP-Go-Segmentation` value, applied only when the stored response's
  `Vary` names that header; otherwise one copy serves every reader. VIP
  keys on a fixed `Vary` allowlist; the real key may include host, scheme,
  or other allowed fields.
- **TTL.** `s-maxage`, then `max-age`, else 300 seconds. VIP's defaults,
  `Expires` handling, and `X-Accel-Expires` are not emulated. Stale
  entries are not revalidated; they simply miss.
- **Pass rules.** The simulator passes through exactly the requests the
  worker bypasses (`lib/bypass.ts`): one list for both. `/wp-json/` is
  cacheable like any GET. VIP's own pass list is longer and includes
  platform-specific rules; a request VIP passes but the worker handles is
  harmless (the rewrite happens, nothing is stored).
- **Storability.** Only 200 GET responses without `Set-Cookie` and without
  `no-store`, `no-cache`, `private`, or `max-age=0`. VIP may store other
  statuses (301, 404) and may strip rather than refuse some cookies.
- **Phases on a pass.** Phase 1 runs on every request (as on the
  platform) and bypasses these; phase 3 does not run on a pass in the
  simulator, and phase 4 runs and skips with `bypass`. Whether VIP runs
  response phases on a pass is unknown; the worker is correct either way.
- **`setCacheControl("no-store")`** is emulated by replacing
  `Cache-Control` and adding `X-Original-Cache-Control`, as the SDK does.
  Whether VIP forwards the rewritten header to the client is unknown.
- **KV durability and visibility.** The simulator's KV is a process-local
  map with no eviction and immediate visibility. VIP's is LRU-evicted,
  site-wide, with unknown cross-location propagation.
- **`Age`.** The simulator adds `Age` on hits. VIP may not, in which case
  phase 4's clock lags by up to the TTL on hits.
- **`resp.request` runtime requirement.** The simulator always has the
  snapshots; VIP needs a runtime with `request.*` support.
- **Encrypted mode.** `vip-go-auth` (encrypted segmentation) is not
  supported by the worker or the simulator. The design is specified for
  plaintext mode only.
- **Host header.** Requests are forwarded with the origin's `Host`,
  `Accept-Encoding: identity`, and hop-by-hop headers stripped; redirects
  are passed through, not followed.

## Open platform questions

Collected from the spec and this implementation; none block local work.

1. Does the cache key on a worker-rewritten `X-VIP-Go-Segmentation`, and
   does the rewritten `Cookie` reach origin?
2. Does `setCacheControl("no-store")` in phase 3 stop the store?
3. KV read latency in phase 1, write visibility across locations, and
   eviction rate.
4. Does the runtime support `request.*` (needed by phase 3)?
5. Is `Date` (and `Age`) reliably present on hits, or is a host clock
   available to workers?
6. Can a worker read a secret from an environment variable at deploy
   time (`getenv`), and is JA3/JA4 exposed for cookie binding?
7. Can a worker read and rewrite decrypted groups in encrypted mode?
8. What is the cap on stored variants per URL?
