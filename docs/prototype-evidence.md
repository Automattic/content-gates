# Astra prototype evidence

## Scope and environment

Independent implementation from `origin/trunk` at `927595e`, branch `glitch/astra-content-gates-prototype`. The prior GPT-5.6 prototype was not inspected. Planning precedes implementation in `astra-implementation-plan.md`.

Local test site: `http://localhost:8891`, canonical plugin bind maps only `/Users/max/Projects/content-gates-astra`. wp-env project `wp-env-content-gates-astra-tests-c908d59f`, WordPress 7.1.3, PHP 8.3.35. Parent verified siteurl, port and mount before destructive tests. PHPUnit and E2E run serially; global setup restores `twentytwentyfive` after PHPUnit resets the DB.

Local unmodified VIP source: `Automattic/vip-go-mu-plugins` commit `f5941dc4060c02f0290b3d7fd39a91807e552f7c`, file `cache/class-vary-cache.php`, SHA256 `2eb0d387b4372a0e48cb23e053561f3a91cf41f353cc958b6c594fff2345fde4`. This is a pinned local source checkout, not a claim that it is the latest deployed VIP build. It is copied verbatim to ignored `artifacts/vip-test/class-vary-cache.php`; never bundled with production code. `tests/fixtures/vip-loader.php` is installed explicitly as a local-only MU fixture. Tests are clearly separated:

* `test-gating.php` uses an injected fake, never a production fallback.
* `test-vip-adapter.php` uses the unmodified upstream class. Tests are explicitly skipped if the source was not provisioned; a skip is not a pass.
* `test-cache-model.php` simulates per-response predicates. It does not implement an edge.
* `responses.spec.ts` uses the actual VIP cookie writer on HTTP and transfers only the emitted membership cookie to an anonymous HTTP client.

## Reproduce

```sh
composer install
npm ci
npm run build
npm run test:prepare-vip # Downloads pinned source and verifies SHA256.
# For this isolated worktree, .wp-env.tests.override.json sets port 8891,
# autoPort:false and maps wp-content/plugins/content-gates to this directory.
WP_ENV_PORT=8891 npm run env:tests -- start
WP_ENV_PORT=8891 npm run test:prepare-vip -- --install
npm run lint:php
npm run analyze:php
npm run lint:js
npm run lint:contract
WP_ENV_PORT=8891 npm run test:php
WP_ENV_PORT=8891 WP_BASE_URL=http://localhost:8891 npm run test:e2e
```

The source fixture is not installed by plugin activation. Without VIP or the explicitly installed fixture, the demo still supports segment administration and editor assignments, but entitlement grants correctly fail unavailable.

## Verified local behaviors

| Area | Evidence |
| --- | --- |
| Persistent admin/editor | Chromium creates, renames, deactivates/reactivates same segment ID; Group select/save/reopen; inactive selected label retained; clear removes saved attribute; reactivation restores choices without assigning |
| Authorization | Anonymous/subscriber segment lookup denied, author/editor allowed metadata; admin UI checks `manage_options` and nonce; anonymous membership POST rejected |
| Storage | Renames preserve identity, core trash/delete vetoed, no CPT public REST endpoint |
| Membership | Fake tests cover positive/negative, invalid/inactive, version and timing errors, mutation-after-read, revocation; real source verifies parse/registration idempotence, exact `1`, multi-group removal, explicit last-removal error |
| Rendering | Nested denied parent never renders dynamic child or reads inner gate; entitled outer/denied inner is correct; source unchanged; login is not membership |
| Reports | Negative reads counted, invalid/removing filter rejected, late read denies, response contains projection and no-store |
| HTTP round trip | Real authenticated POST emits VIP cookie; anonymous cookie-only client receives entitled page/REST/RSS/Atom; bad value and missing cookie deny |
| Cache model | Conditional subscriber/newsletter sets, negative values, irrelevant donor, reversed insertion order, empty set, ambiguous stale candidates force MISS, invalidated model returns MISS |

## Leak matrix

| Surface | Local result / limitation |
| --- | --- |
| Singular page | Raw HTTP denied bytes absent; entitled bytes present, including attribute sentinel; logged-in unentitled denied |
| REST post view | Raw serialized body denied sentinel absent; entitled present; anonymous edit context denied, authorized edit context intentionally has saved raw content |
| RSS / Atom | Complete raw HTTP bodies denied sentinel absent; entitled present; automatic-excerpt regression includes wholly gated content |
| Archive and search | Raw HTTP denied sentinel absent; consulted projection and no-store present |
| Automatic excerpt | Generated from protected render; core raw-source fallback replaced; manual excerpts are explicitly public editorial data |
| oEmbed | Raw oEmbed JSON and embedded HTML independently HTTP-checked for absent restricted sentinel |
| AMP | Not installed; unverified |
| Head metadata / JSON-LD | Tested theme response has no sentinel; arbitrary SEO/plugin raw-content reads remain unaudited |
| Sitemaps | No dedicated raw XML test; core title/URL-only behavior is not proof for custom extensions |
| Raw post/object storage | Saved content remains unchanged by rendering; no rendered-fragment/object-cache writes introduced; external fragment caches unaudited |
| Revisions/autosaves/custom CPT/preview/block renderer | Core authorization retained; post edit-context raw access checked in PHP and actual HTTP; revisions/autosaves/custom routes remain incomplete |

## Explicit blockers and unverified production requirements

1. **No live VIP narrow-cache integration, cache hits, purge guarantees or benchmark.** Broad VIP Vary remains intact; no-store is mandatory. Diagnostic headers and local model are not cache integration. No realistic segment-cardinality dataset, baseline hit rate, or within-5-percentage-points claim.
2. **Last-membership revocation upstream defect.** The pinned VIP writer does not delete an empty group cookie. Explicit error prevents false success; upstream fix and real response verification are needed before release. Cookie expiry/encryption behavior is delegated to VIP and not claimed authenticated or tamper-proof.
3. **No deployed meter or crawler worker.** No origin-only counter or user-agent grant bypass is shipped. First-visit round-trip, HIT advancement, repeats/concurrency/reset, encryption conformance, crawler classification and Rich Results remain unverified. Structured-data generation is not implemented.
4. **No end-to-end Newspack integration.** Source-informed bridge example is documentation only; upstream suppression/extension patch has not been opened. UI and gating run with Reader Activation and WooCommerce absent, but that does not prove integration dependency isolation.
5. **No real MCP action.** The non-browser HTTP cookie round trip is the evidence, not an MCP claim.
6. **Buffer lifecycle limitations.** Normal supported responses buffered and no-store; early flush/streaming/binary/abnormal termination, nested-buffer behavior, TTFB/peak-memory budget and arbitrary output handlers need broader tests. No-store remains even when reports cannot be complete.
7. **Support matrix.** Tested PHP 8.3/latest local WordPress only, not PHP 8.1/WordPress 6.6/multisite. Cross-blog switching fails closed rather than re-registering after parse.
8. **Cookie-size guardrail.** A pre-4KB cookie-size warning and measured segment-count recommendation are not implemented; the prototype does not claim the cookie budget requirement is met.
9. **Accepted risk qualifications.** Cookie forgery and crawler user-agent spoofing remain accepted spec limitations, not claims of protection. Public manual excerpts, titles and other independently authored metadata cannot be inferred as restricted from Group assignments.

## Final quality run

Final local run (2026-10-17), sequential:

- `npm run build`: PASS, distributable editor bundle generated.
- `npm run lint:php`: PASS, zero warnings/errors.
- `npm run analyze:php`: PASS, PHPStan level 8, zero errors.
- `npm run lint:js`: PASS.
- `WP_ENV_PORT=8891 npm run test:php`: PASS, 19 tests / 111 assertions, no skips.
- `WP_ENV_PORT=8891 WP_BASE_URL=http://localhost:8891 npm run test:e2e`: PASS, 4 Chromium tests / 19.4 seconds.
- `npm run test:prepare-vip`: PASS, downloaded pinned upstream source matches the recorded checksum. CI now provisions it explicitly before genuine integration tests.
- `npm run lint:contract`: PASS, first-party adapter-boundary audit; does not instrument unknown third-party code.
- Additional `WP_ENV_PORT=8891 WP_BASE_URL=http://localhost:8891 npm run test:e2e -- responses.spec.ts`: PASS, 1 test / 3.9 seconds after adding actual HTTP anonymous edit-context denial, authorized raw-content preservation, raw oEmbed JSON and embed-page sentinel checks.
- Repeated `npm run build`, `npm run lint:js`, and `npm run lint:contract` after tooling/test additions: PASS. Generated editor bundle and asset metadata are fresh build output, not hand-formatted.
- `git diff --check`: clean after formatting cleanup.

No measured coverage percentage or production-readiness claim is made.

### Dependency diff

Seven directly imported WordPress editor packages were declared. Their graph adds 155 package nodes, removes none and changes zero existing package versions. Original two-space lockfile formatting was restored to remove unrelated whole-file indentation churn. npm reported pre-existing/transitive audit and PHP-wasm engine warnings on host Node 24.16; no forced dependency upgrades were performed. Browser/runtime checks above passed.
