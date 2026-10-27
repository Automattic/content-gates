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
| `edge-workers/tests/` | `node:test` suites: the modules, the edge contract (`contract.test.ts`), and multi-request sequences through `edge-harness.ts` (`sequence.test.ts`). |
| `edge-workers/scripts/crawlers-config.mjs` | Regenerates the crawler module before a build. |

Everything under `edge-workers/lib/` compiles under
AssemblyScript **and** runs unchanged under Node. That is why the code
avoids `Map`, closures, `for...of`, regex, template literals, and `===`
on strings, and why hashing is injected (`Crypto`) instead of imported:
the worker passes the SDK's host crypto, the tests pass `node:crypto`.
One implementation, tested once, deployed once.

## Request flow

```
Browser                      Edge (worker + cache)                       Origin
  |                                 |                                       |
  | GET /article-1/                 |                                       |
  | Cookie: vip-go-seg=...          |                                       |
  |-------------------------------->|                                       |
  |                 [phase 1] parse cookie, strip crawler groups,           |
  |                           classify UA, read KV record,                  |
  |                           narrow vip-go-seg in the forwarded Cookie,    |
  |                           set marker headers                            |
  |                                 |                                       |
  |                       cache lookup: URL + X-VIP-Go-Segmentation,        |
  |                       which WPVIP derives from the forwarded cookie     |
  |                                 |-- miss ------------------------------>|
  |                                 |   Cookie: vip-go-seg=<narrowed>       |
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
4. Compute the narrowed segmentation:
   - **No record:** the full serialization of the reader's groups. This is
     today's keying and is always safe.
   - **Record present:** only the reader's values for the recorded groups,
     in `Vary_Cache` format; empty when none. An empty record (`v1:`, a
     page that declares nothing) yields one copy for everyone.
5. Set `X-WPVIP-Content-Gates-Edge` to the crawlers.json version, so the
   plugin can show a notice on version skew.
6. Rewrite the forwarded `Cookie` header so `vip-go-seg` holds the
   narrowed segmentation (with the worker's own crawler classification and
   never a browser-supplied crawler value), or no `vip-go-seg` at all when
   it is empty. WPVIP derives the cache key's `X-VIP-Go-Segmentation` from
   this forwarded cookie and ignores the header the worker sets (observed
   2026-10-27), so the cookie is what narrows the key. The worker still
   sets the header for a platform that honors it. The browser's cookie
   is never changed.
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
   finalize callback at the end of `wp`, so its reads are unknown.
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
`X-WPVIP-Content-Gates-Meter` headers, the response is a 200 (a redirect
carries origin's headers but delivers nothing to count), the client is
not a crawler, the secret is configured, and the response `Date` is
readable.

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
| `X-WPVIP-Content-Gates-Key` | phase 3, before the store | The key the request resolved to: the narrowed `X-VIP-Go-Segmentation` value, `none` for the anonymous copy, `bypass` when phase 1 left the request alone, `-` when phase 3 saw no phase 1 marker |

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
| present | `-` | Phase 3 ran but saw no lookup marker from phase 1: phase 1 did not run, or `resp.request` does not carry its rewrite. Phase 3 marks the response `no-store` |
| present, `Vary` set | header absent | The worker did not run phase 3 on this response |
| absent, `Vary` set | any | Origin fault: a segmented response with no declared set. Phase 3 marks it `no-store` |

The test harness reads the same header, so a local sequence and a live run
read the same way.

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

The worker writes both cookies with `Path=/`, `Max-Age`, and no `Domain`,
`Secure`, or `SameSite`. `Vary_Cache` writes `vip-go-seg` with `expires`
and `COOKIE_DOMAIN` instead. When origin wrote the cookie on this response,
phase 4 keeps origin's attributes, so the two writers differ only on hits,
where origin wrote nothing.

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
withheld. Set it as an application environment variable:

```bash
vip @<app>.<env> config envvar set CONTENT_GATES_METER_SECRET
```

Workers read application variables through `getenv()` (observed
2026-10-27; a worker that ran before the variable existed read null, so
set the variable first). The SDK reads variables at worker startup, so
redeploy the worker if the variable was set after the deploy. Never
compile the secret in or commit it. The test harness passes a fixed test
secret.

## Time without a clock

AssemblyScript's `Date.now()` compiles to an `env.Date.now` import that
the VIP host is not documented to provide; a missing import would stop the
worker from loading. The worker therefore reads time from the response's
`Date` header plus `Age`. On WPVIP `Date` is refreshed to delivery time on
hits and `Age` is absent (observed 2026-10-27), so precision is one second
on hits and misses alike. If the `Date` header is unreadable, phase 4 does
nothing for that response.

## Building

```bash
npm run edge:install  # once: edge-workers/ dependencies (SDK, AssemblyScript), its own package
npm run edge:build    # regenerates lib/crawlers-config.ts, then `vip edge-workers build --all`
npm run test:edge     # node:test suites: modules, contract, sequences; no build needed
npm run lint:js       # ESLint over edge-workers/ (and the rest of the project)
```

`vip edge-workers build` compiles `workers/content-gates/assembly/index.ts`
with `asc --runtime stub --optimizeLevel 3 --shrinkLevel 2 --path
node_modules` into `edge-workers/build/content-gates.wasm` (gitignored).
The CLI owns those flags: they are a contract with the platform's WASM
validator. `npm run edge:build` runs the CLI through `npx` at a pinned
version so CI and laptops compile alike; with VIP-CLI installed globally,
`cd edge-workers && npm run build` is the same thing.

`lib/crawlers-config.ts` is committed so the tests run without a build; `tests/crawlers.test.ts` fails when it is out of date
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

**Confirmed on the first deploys (2026-10-27):** `resp.request`, which
phase 3 reads, is populated on the deployed runtime; the cache keys on
the cookie the worker forwards, not on a header it sets, which is why
phase 1 narrows the cookie; and `getenv()` reads application environment
variables, so the secret needs no CLI flag (see "The secret").

## Testing the contract locally

There is no local emulation of WPVIP's edge. Three layers stand in for it,
each stating what it assumes:

1. **Pure functions.** Every decision the worker makes is a function over
   plain inputs in `lib/`, and `tests/*.test.ts` covers each branch. This
   is the worker's own contract: what it does with what it is given.
2. **The boundary fixture.** `tests/fixtures/edge-contract.json` (plugin
   root) pins the declared header, the meter header, the segmentation
   cookie, and the header names. `tests/unit/test-edge-contract.php` proves
   origin emits and parses them; `tests/contract.test.ts` proves the worker
   does. A format change fails one side until both move.
3. **Sequences.** `tests/edge-harness.ts` is an in-memory edge that runs the
   three phases around a cache with only the rules the design depends on:
   a copy is keyed by URL plus the narrowed `X-VIP-Go-Segmentation` value
   when `Vary` names that header; a response is stored only if it is a 200
   to a GET with no `Set-Cookie` and no `no-store`; phases run on every
   request and delivery; KV is a map; a purge keeps records.
   `tests/sequence.test.ts` runs the learn, narrow, widen, shrink, crawler,
   meter-on-hit, and bypass scenarios through it against a fake origin.

Each of the harness's rules was observed on WPVIP on 2026-10-27 through
the diagnostic headers above: keying on the forwarded cookie, `no-store`
honored, KV visible across locations, phases on passes. A local test
cannot show that they still hold after a platform change; the live
validation scenarios are the way to re-check.

## Open platform questions

[known-limitations.md](known-limitations.md) keeps the one numbered list;
none block local work, and none blocks metering any more. The one this
worker's code still leans on is 6: whether `Vary_Cache` re-serializes
groups it did not register, which decides the groups the next meter
state is merged into on a miss.
