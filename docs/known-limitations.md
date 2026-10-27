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

## Platform questions (unverified on WPVIP)

1. Can a phase 1 worker rewrite `X-VIP-Go-Segmentation`, and does the cache
   key on the rewritten value? The whole narrowing design depends on it.
2. Can a phase 3 worker set `Cache-Control: no-store` so the cache does not
   store the response? The SDK's `setCacheControl()` suggests yes.
3. KV read latency in phase 1, write visibility across edge locations, and
   eviction rate. KV is non-durable; eviction costs one miss per URL, not a
   leak.
4. Can origin write to edge KV at save time? Not needed for correctness.
5. Encrypted mode (`vip-go-auth`): can a worker read and rewrite the
   decrypted groups? The worker is written for plaintext mode only.
6. The cap on stored variants per URL.
7. Can the worker hold a signing secret (`getenv`) and is JA3/JA4 exposed for
   cookie binding? `getenv` exists in the SDK; JA3 is not documented.
8. Does `resp.request` (the request snapshot the coverage check reads) exist
   on the deployed runtime? The SDK README says it needs runtime support.
   When the markers are absent, phase 3 sets `no-store` on every segmented
   response, so the failure costs cache hits, not safety. The answer is
   readable from outside: `X-WPVIP-Content-Gates-Key` reads `-` on a
   response that carries a declared set (see
   [edge-worker.md](edge-worker.md#diagnostic-headers)).
9. Does VIP's site purge (`WPCOM_VIP_Cache_Manager::purge_site_cache()`)
   exist under that name and purge every variant? The default listener
   guards with `method_exists`.
10. Is `Date` (and `Age`) reliably present on hits, or is a host clock
    available to workers? Phase 4's meter clock comes from them; without
    `Age` it lags by up to the TTL on hits.
11. Does `Vary_Cache` at origin re-serialize groups it did not register,
    so a meter value survives a miss that rewrote the cookie? The source
    suggests yes. Phase 4 no longer depends on it for counting, only for
    the groups it merges the next state into.
12. Does the VIP edge expose verified-bot information (IP ranges or
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
  PHPUnit suite runs single-site only.
- **Rich Results Test.** The JSON-LD follows Google's paywall schema; it has
  not been submitted to the test, which needs a public URL.

## Disputed protocol changes

Proposed protocol changes that were reviewed and not adopted, with the
evidence that would reopen them, are in
[spec/open-questions.md](../spec/open-questions.md): the declared-set
fingerprint, exact-equality coverage, and meter cookie v2.

## Simulator variances

`docs/edge-worker.md` lists where the local simulator can differ from the
real edge. Treat every edge claim in this repository as "designed and
simulated", not "observed".
