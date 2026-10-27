# Known limitations and open questions

What this prototype does not do, what it assumes, and what must be proven on
a WPVIP application before any of it is a product claim.

## Accepted risks (from the spec)

- **Cookie forgery.** `vip-go-seg` is plaintext and `vip-go-auth` has no
  integrity check. A reader who writes `subscriber_--_1` into the cookie is a
  subscriber. Recorded in `spec/segment-admin.md`; not mitigated here.
- **Crawler spoofing.** Classification is by user agent. A spoofed agent
  receives the crawler variant. Verified-bot data at the edge is a platform
  question.
- **First metered visit.** A reader with no cookie sees the gate once; the
  worker writes `open` and the next metered view delivers content. Deleting
  the cookie resets the allowance after one more round trip.
- **Meter counts subscribers too.** The worker does not know gating
  semantics. Harmless while subscribed.

## Platform questions

Open questions only. What has been observed on WPVIP (first live pass,
`content-gates-prototype.go-vip.net`, 2026-10-27) is stated as fact where
it applies: keying on the forwarded cookie and the provider write rule in
`spec/decisions.md`; `no-store`, the pass window, KV visibility, both
purges, and metering in `spec/page-cache.md`; the request snapshot, phases
on passes, dropped empty header values, `Date` on hits, and the meter
secret in `docs/edge-worker.md`.

1. KV eviction rate. Writes are visible everywhere within a second, but
   how often records are evicted decides how much narrowing is lost; an
   eviction costs one miss per URL per location, never a leak.
2. Can origin write to edge KV at save time? Not needed for correctness.
3. Encrypted mode (`vip-go-auth`): can a worker read and rewrite the
   decrypted groups? The worker is written for plaintext mode only.
4. The cap on stored variants per URL.
5. JA3/JA4 for cookie binding is not documented for workers. (The meter
   secret itself is settled: `getenv('CONTENT_GATES_METER_SECRET')` reads
   the application environment variable, observed 2026-10-27. The earlier
   null reading came from a variable that was not set, not from a
   platform gap.)
6. Does `Vary_Cache` at origin re-serialize groups it did not register,
   so a meter value survives a miss that rewrote the cookie? The source
   suggests yes. Phase 4 depends on it only for the groups it merges the
   next state into, not for counting.
7. Does the VIP edge expose verified-bot information (IP ranges or
   reverse DNS) to a worker? Without it, crawler classification stays
   user-agent based and spoofable (accepted risk).

This list is the single source; spec/page-cache.md and edge-worker.md
point here by number.

## Not built in this slice

- **PHPCS sniff** that flags direct `Vary_Cache` reads outside the plugin
  (spec/api.md asks for one).
- **Newspack integration beyond the rule bridge:** suppressing the
  client-side metering reveal, bridging `Content_Gate` restriction, and the
  reverse rule (segment as a Newspack rule value). Discovery against
  `newspack-workspace` is still open.
- **Template-part, pattern, and widget-area gating UI.** Developers use
  `is_reader_in_segment()` with a declaration.
- **Autosave-aware previews.** The declared set is computed when a post is
  saved; a Group assigned in an unsaved preview fails closed until saved.
- **Search.** A search for a gated phrase still finds the post. No bytes
  leak, but existence does.
- **Fill endpoint or fragment hydration.** Separate work.
- **Coverage reporting and CI thresholds.** PHPUnit runs without coverage.
- **Hit-rate benchmark.** No workload has been measured. The variant bound
  is by construction (2^k for k declared boolean segments, times 4 per meter)
  but the 5-percentage-point target needs the VIP measurement the spec
  describes.
- **Multisite** is implemented (group names are `{blog_id}-{key}`) but the
  PHPUnit suite runs single-site only, so the prefix has no test.
- **Fail-closed without `Vary_Cache`** (`content_gates_platform_unavailable`)
  has no test: every suite runs with `WP_ENVIRONMENT_TYPE=local`, where the
  bundled copy loads.
- **Cookie expiry at the browser.** The lifetime filter and the clamp are
  tested; that a browser drops the cookie after `Max-Age` is browser
  behavior and has no test.
- **Rich Results Test.** The JSON-LD follows Google's paywall schema; it has
  not been submitted to the test, which needs a public URL.

## Disputed protocol changes

Proposed protocol changes that were reviewed and not adopted, with the
evidence that would reopen them, are in
[spec/open-questions.md](../spec/open-questions.md): the declared-set
fingerprint, exact-equality coverage, and meter cookie v2.

## What local evidence means

There is no local edge emulation. The worker's logic is tested as pure
functions; the formats that cross the origin/edge boundary are pinned in
`tests/fixtures/edge-contract.json` and checked on both sides; and
multi-request sequences run through an in-memory edge that applies only
the cache rules the design depends on (`edge-workers/tests/edge-harness.ts`).
Those rules were observed on WPVIP on 2026-10-27 (see the platform
questions above for what is still open). A platform change would not show
up locally; re-run the live validation scenarios after one.
