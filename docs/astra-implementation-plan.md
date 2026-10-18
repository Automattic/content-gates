# Astra implementation plan

Status: independent prototype plan, not production acceptance.

## Finish line and evidence

Implement a runnable, independently designed Content Gates prototype from `origin/trunk` at `927595e`, with persistent segment administration, Group assignment, a versioned provider boundary, server-side withholding, and per-response consulted-set reporting. Prove the local behaviors with WordPress integration tests and browser/raw-response tests. Clearly identify simulated cache behavior and integration work still requiring VIP, Newspack, or product decisions.

The planning task ends with this reviewable document only. No implementation or dependency changes are part of this planning task.

Inputs read in full: `spec/content-gating-api.md`, `spec/segment-admin.md`, `spec/editor-ui.md`, this repository's `AGENTS.md`, the parent Newspack workspace's `AGENTS.md`, and the existing plugin bootstrap, tests, CI, and tooling configuration. The prior GPT-5.6 prototype and its plan were not consulted. Local upstream `vip-go-mu-plugins/cache/class-vary-cache.php` was inspected only to establish the actual adapter boundary and lifecycle hazards. Record its commit when implementation starts; a local checkout is not evidence of deployed VIP behavior.

Specification precedence: Segment Admin and Editor UI supersede the API draft's gate-builder and segment-deletion examples. No segment deletion and no full Newspack eligibility UI. Metering remains a project deliverable even though its detailed API is future work in the API draft.

## 1. Explicit prototype policies

These are conservative implementation assumptions, not claims that the product's open decisions are settled. Record them in public documentation and propose corresponding specification clarifications when implementing.

| Topic | Prototype policy |
| --- | --- |
| Storage | Non-public CPT `content_gate_segment`; post title is the display name; `publish` means active and `draft` means inactive. New records default to active, visibly stated in the create form. No public permalink or public CPT content endpoint. |
| Identity | Positive site-local post ID is the public segment reference and saved Group attribute. Deterministic VIP group name `cg_<blog_id>_<post_id>` uses only supported characters. Names never derive from labels. Blog switching must reload site-specific registry and request context. |
| Inactive assignments | Fail closed: an inactive segment's assigned Group remains withheld even if a stale cookie says membership is present. Preserve the ID and assignment. Membership reads return false; new grants fail with `content_gates_inactive_segment`; revocation remains allowed. Reactivation restores the same identity and requires invalidation. This policy needs product confirmation before release. |
| Invalid references | Missing, malformed, wrong-post-type, wrong-site, or otherwise unresolved references are not equivalent to No segment. Withhold and mark the response uncacheable if a safe dependency cannot be represented. An absent attribute alone means no restriction. |
| Authorization | Login is not entitlement. Content-edit permission permits an explicitly authorized authoring response, not public access or a grant. Public GETs still require membership for logged-in readers. |
| Provider contract | Prototype contract version `1`, independently documented from plugin version and VIP cookie `vc-v1`; ID-based calls, boolean membership token `1`, absence `none` in projection only. Do not write `none` into the cookie. Public function names are provisional until the first release. |
| Cache readiness | Default origin mode is `private, no-store` until a verified deployment adapter can safely suppress broad segmentation variance and select narrow variants. Diagnostics or a local simulator do not make a response safe for an arbitrary CDN. Never silently fall back to full-cookie caching as the claimed solution. |
| Platform absent | Missing `Vary_Cache` produces an explicit unavailable-adapter state. Restricted content fails closed. A local test double is injected only through test/development tooling, never silently substituted in production. |
| Meter/crawler | Do not grant access based on a prototype counter or an origin-only user-agent check. Define testable policy boundaries and fixtures, but label edge advancement, classification, reset configuration, and deployment as outstanding until implemented and verified. |

No arbitrary segment cap, invented production purge API, alternate membership cookie, or new cryptographic protocol will close an unresolved platform question. The accepted cookie-forgery and crawler-spoofing risks remain documented qualifications, not evidence that grants are tamper-proof.

## 2. Components and boundaries

Keep `Plugin::boot()` as visible wiring, with idempotent initialization. Use the existing `ContentGates` namespace and WordPress class filenames.

1. **Segment repository and registry**: CPT storage, validated IDs, active/inactive lifecycle, deterministic group mapping, and request-local registration snapshot. Object-cache raw segment metadata if useful, never rendered gated HTML.
2. **Membership transport interface**: register group, read exact value, assign `1`, remove via empty value, and explicit header-commit state. Implement a genuine `Vary_Cache` adapter; use an injected deterministic fake for narrow unit tests only.
3. **Public membership service/functions**: validation, timing, contract errors, entitlement reads, and consulted-set tracking. Every render decision goes through this service, including template/header/widget/shortcode code.
4. **Request variance collector**: record reads, project membership, freeze exactly once, and notify finalization observers. Request state cannot survive between test cases or long-lived requests.
5. **Group enforcement and delivery-surface protection**: pre-render short circuit plus separate handling of raw content and derived excerpts. No client reveal or fill endpoint.
6. **Admin and editor adapters**: simple segment list editor and a Group-only dropdown. They do not decide eligibility.
7. **Response lifecycle/cache adapter**: buffer supported output, finalize after all reads, preserve unrelated headers, and expose a deployment capability boundary. No guessed VIP edge behavior.
8. **Provider integrations**: documented server/client assignment flow, a worked Newspack bridge, and a non-browser/MCP-style caller exercise. Provider adapters own eligibility, not this plugin.
9. **Test tooling**: source-controlled fixtures, adversarial response assertions, an explicitly simulated response cache, and evidence templates for live VIP verification.

Suggested files: `inc/class-segment-repository.php`, `class-segment-registry.php`, `class-membership.php`, `class-variance.php`, `class-block-gates.php`, `class-response-protection.php`, `class-response-lifecycle.php`, `class-admin.php`, `class-editor.php`; `inc/cache/interface-membership-transport.php`, `class-vip-vary-cache-adapter.php`; `inc/providers/` for provider contracts/bridge; `inc/functions.php` for documented public functions. Exact class boundaries may be consolidated when implementation makes duplication apparent.

## 3. Membership lifecycle and genuine VIP adapter

The inspected upstream class registers `parse_cookies` at `init` priority 10 and `send_headers` at `PHP_INT_MAX`. `register_groups()` resets each registered value to an empty string. Consequently, registering after parsing can erase a real membership even if it is technically before `send_headers`.

Use this sequence and verify it against the actual pinned class:

1. Bootstrap components and detect the platform class without copying its implementation.
2. Register CPT and load/register every existing segment, including inactive ones needed for removal and reference stability, once at early `init` priority 5. Prevent duplicate underlying registration from clearing parsed state.
3. Let VIP parse its cookies at priority 10. Membership reads validate known group values as exactly `1`; unknown cookie groups do not create definitions or grants.
4. Run provider refresh/assignment on a documented provider-ready action at `init` priority 20 or later, but strictly before header commitment. Do not assume provider callbacks registered at plugin load have already run.
5. Let VIP write its cookie through its real sender. Translate transport errors into wrapper-specific errors without pretending success. Reject mutation after the wrapper enters the send-headers phase or PHP headers are committed, even if an underlying method would accept it.
6. Read membership during rendering through the public wrapper. Reads after variance freeze are a lifecycle defect and force an uncacheable response while still denying unsafe output.

Test registration and parsing on normal front end, REST, admin-ajax, feeds, and cron. These paths do not necessarily execute the same `send_headers` action. For a client assignment endpoint, explicitly integrate VIP's real cookie sender into the endpoint's response lifecycle where the normal action does not run; prove real `Set-Cookie` arrives before the body. If this cannot be made reliable using supported APIs, keep that path blocked rather than adding a second cookie serializer.

Proposed public surface, using the `ContentGates` namespace or consistently prefixed globals:

- `register_segment( int $segment_id ): true|WP_Error`: register an existing definition, not create eligibility. Idempotent; registration phase is before cookie parsing. Explain the intentionally stricter timing than the draft's broad before-headers description.
- `assign_reader_to_segment( int $segment_id ): true|WP_Error` and `remove_reader_from_segment( int $segment_id ): true|WP_Error`.
- `is_reader_in_segment( int $segment_id ): bool`: false for inactive/invalid/unavailable state; track every valid read, including false results. Invalid references also set the unsafe-cache flag. Never return a truthy `WP_Error` to template conditionals.
- Document diagnostics for invalid read references separately from the boolean predicate. Error-returning mutation APIs distinguish invalid ID, inactive, unregistered, unavailable transport, too early/not ready, too late, and unsupported contract version.

Client assignment must not expose an endpoint that grants whatever segment an anonymous caller names. A versioned uncacheable POST uses a trusted provider verifier or narrowly authorized administrative/test action. Enforce authentication/authorization, nonce where appropriate, validation, same-origin credentials/CORS policy, safe redirect destinations, and no CSRF grants. No GET mutations. For browser providers, their proof is verified server-side without logging it. A provider returns a decision, not raw arbitrary cookie values.

The reference server flow is POST on `init`, assignment, then redirect after the real cookie-send hook. Verify idempotent cases where no new cookie is emitted rather than hanging the redirect. A grant takes effect on the next page request; no restoration of already withheld bytes. A cacheable GET refresh only happens on a MISS. Expiry/revocation on edge HITs is limited by provider/cookie expiry and cannot be claimed immediate. Test revoked and expired states, including the first subsequent origin refresh.

Publish signatures, hook timing, error codes, contract negotiation and deprecation policy, cookie mechanism, and security assumptions. Changing `none` semantics or response signaling requires a contract-version change, independently of `vc-v1`.

Add first-party static checks that reject direct VIP membership reads outside the adapter and flag registration/assignment callbacks wired after the allowed phase. Runtime timing checks remain necessary because static inspection cannot resolve arbitrary hook wiring. The real class has no read-observer hook, so reliable logging of arbitrary third-party direct reads requires upstream instrumentation; do not claim the wrapper can intercept them. Audit deployed themes/plugins and record this residual risk. Measure serialized cookie size using real VIP output, warn with headroom before 4 KB without logging its contents, and document measured recommendations rather than inventing a segment-count cap.

## 4. Segment administration and Group editing

**Admin**: Settings > Content Gates, capability `manage_options`, accessible list/create/edit forms, names and stable IDs, active/inactive controls, and persisted errors/success notices. No full CPT editor, gate builder, eligibility rules, priority, or billing controls. Apply nonces and capability checks to every mutation, including custom REST routes; editor readers may list the current site's segments with appropriate authoring capability, but cannot administer them.

**No deletion**: omit individual and bulk trash/delete controls; disallow corresponding mapped capabilities; reject deletion routes; veto standard `wp_trash_post()` and `wp_delete_post()` through their supported pre-operation filters for this CPT. Test active, inactive, referenced, and unreferenced records. Direct database modification by arbitrary privileged code is outside the supported API guarantee.

**Editor**: extend only `core/group`, register nullable integer `contentGateSegmentId` on PHP and JS sides, add an inspector Segment dropdown listing active segment names plus No segment. Clearing removes the attribute. An existing inactive reference remains selected and visibly marked inactive, without becoming a choice for new assignments. An unresolved assignment displays a warning and remains saved until the author explicitly changes it. Loading/fetch errors must not clear saved assignments.

Add a minimal `@wordpress/scripts` build, registered generated asset dependencies, translations, and packaging/build instructions, because the scaffold currently has no client build. Keep the public front end free of reveal JavaScript. Test with Reader Activation and WooCommerce absent.

**Rendering**: `pre_render_block` inspects Group assignments and returns an empty string when denied before children render. Allowance returns incoming `$pre_render` unchanged, preserving other filters. Nested gates naturally consult only reached inner gates. Avoid shared render-fragment caches and audit filter ordering with third-party pre-render caching. Author-specified core visibility remains authoritative; membership never forces it visible. Use the public hook rather than private WordPress visibility functions, preserving the stated WordPress 6.6 support floor; test newer visibility behavior only where supported.

## 5. Withholding and leak surfaces

Treat raw-response assertions as the primary security oracle. Use a unique restricted sentinel in text, attribute markup, nested blocks, and dynamic child output; search complete raw bodies and serialized payloads, not only DOM visibility.

| Surface | Implementation/verification obligation |
| --- | --- |
| Singular page and templates | Remove denied Groups and children before render. Wrapper checks cover template/header/footer/widget/shortcode reads. Test logged out, cookie-identified, authenticated-but-unentitled, and entitled. |
| Archives and search | Protect rendered loop content and automatic excerpts. Collect the union of reads actually reached across all rendered posts. Explicit manual excerpts are public editorial data; disclose that copying a secret into one cannot be inferred from a Group assignment. |
| REST rendered content | Enforce normal Group checks, including embed/view contexts, collection results, block renderer responses, and applicable CPT endpoints. Do not bypass merely for `REST_REQUEST` or `is_admin()`. |
| REST raw content and authoring | Never return unrestricted saved blocks to public callers. Core authenticated edit-context/raw-content access needs `edit_post` for that specific object and private/no-store responses. Include revisions/autosaves and preview endpoints in the audit; no capability-wide anonymous exception. |
| Excerpts | Prune denied block subtrees before generating automatic excerpts. Avoid helpers that strip block comments while retaining their restricted inner text. Do not mutate `post_content` in storage. |
| RSS and Atom | Test full content and summary modes plus content-derived descriptions. Same pruning/withholding and finalization as HTML. |
| oEmbed | Inspect title, excerpt/description, embedded HTML, and the embed page itself. Fix content-derived leaks or record an explicit limitation; never count untested as passed. |
| AMP | Test if installed; otherwise record not available, not verified. Integration hooks must not assume the normal theme renderer runs. |
| Head metadata/structured data | Inspect descriptions, Open Graph, Twitter, JSON-LD, and escaped/encoded article fields. Plugin-generated metadata may bypass normal content hooks and needs explicit integration or a known limitation. |
| Sitemaps/listing teasers | Inspect actual XML/HTML output and descriptions. A URL/title alone is not a leak of a Group's body, but copied restricted text is. |
| Cached objects/fragments | Audit raw post cache versus rendered gated output. Raw WordPress post persistence is normal; do not store or serve rendered gated fragments. No object cache, transient, or block cache may hide reads. |

Page, REST, and feed leaks are blocking failures for prototype completion, not accepted limitations. Other surfaces require a definitive tested result, fix, or explicit unverified/known-limitation record. Unknown third-party delivery routes remain a deployment audit requirement.

## 6. Consulted sets, buffering, and cache correctness

For each request, maintain an ordered-independent set of stable group identifiers. Record a read before evaluating it. Deduplicate and sort at finalization. Project the current membership onto that response's set using exact `1` or `none`. Never key on labels, all configured segments, the full membership cookie, or another render's set.

Keep the draft's `content_gate_consulted_segments` filter and `content_gate_finalize_variance` action (singular spelling is intentional) or document a deliberate pre-release rename. A filter cannot safely remove a recorded dependency: reject such a result, retain the original dependencies, and disable caching with a diagnostic. Validate additions and freeze once. Debug output appears only with `WP_DEBUG`; it must not change authorization, expose credentials, or echo full cookies. Report consulted names and projected values only.

Required worked fixture:

- Subscriber view consults `{subscriber}`; newsletter and donor membership differences share this view.
- Non-subscriber newsletter branch consults `{subscriber, newsletter}` and stores the negative subscriber value.
- Denied outer Group consults only its outer segment; permitted outer Group reaches and records its inner segment.
- A no-read page has `{}`. A sitewide header read means it is no longer a no-read page, even if the article itself is public.

A local cache simulator stores each response with its own consulted names and matching values. Lookup compares each candidate against its own predicate. Include mixed render orders, irrelevant membership, and conflicting/stale candidates; ambiguity causes a MISS, never an arbitrary entitlement choice. This is a correctness model, not a deployed cache or performance result.

Start full-response buffering early enough to cover front end and non-HTML output. Finalize before bytes leave origin, after all membership reads. Use path-specific integration where required, notably REST serving. Preserve unrelated `Vary`, cache controls, content encoding, cookies, status, and redirects. Test shutdown, `wp_die`, 404, early exits, HEAD, nested output buffers, flush attempts, and streaming/binary exclusions. If the lifecycle cannot guarantee a complete signal, no-store must already be in place before headers commit; do not rely on repairing headers after a flush. Late output or failed buffering cannot be labeled safely cacheable.

The real VIP adapter owns membership and cookie serialization. A separate edge-response adapter owns suppression of only broad `X-VIP-Go-Segmentation`/auth variance and emission of a verified narrow signal. Do not simply remove broad Vary and assume a diagnostic header is honored. Do not unhook the VIP sender and hand-write cookies. Preferred integration is a supported upstream Vary filter or a platform-verified edge override. The inspected source provides no such broad-Vary suppression filter, so this remains a concrete integration dependency.

Evaluate standard per-segment request headers/Vary first because it preserves per-response matching, but do not declare VIP support without a live test. The alternative edge manifest must not key existing responses using another render's set. Trusted edge integration must remove/overwrite spoofed client segmentation headers. Preserve logged-in cache bypass and no-cache cookies, and never share responses that set membership cookies.

## 7. Invalidation and configuration changes

Emit an explicit invalidation request boundary for content/assignment edits, segment creation/rename/status changes, provider-rule changes, and cache-contract cutover. This event is not itself a purge. Attach old/new IDs and affected-resource context; catalog dependencies for singular URLs, listing/feed/API surfaces, global templates, and reusable/synced patterns. A reverse post-reference index alone does not cover template-level PHP checks.

Until an existing supported platform purge mechanism is validated, keep cache enablement unavailable for that integration. A deliberately documented broad purge via a real supported API is acceptable when dependency mapping is incomplete; do not invent its method signature. Local simulator invalidation/generation tests prove only local behavior. VIP acceptance requires every variant purged, including segmented/metered copies, the first cold render checked, and later hits checked after each change. Membership changes alone select a different variant and need no content purge. Disabling a segment must never leave an old entitled cache response reachable.

## 8. Newspack, metering, and crawlers

**Newspack bridge**: inspect current upstream `Content_Gate`, `Block_Visibility`, `Content_Restriction_Control`, `Access_Rules`, `Metering`, and the actual `register_rule()` signature before coding against them. Bridge externally decided entitlement to stable segment assignment in the validated early lifecycle, and route restriction reads through the wrapper. Suppress Newspack client-side metering reveal so no restricted bytes are delivered first. Do not copy/fork the engine or import the full gate builder. An isolated bridge fixture can validate the contract, but it is not proof that the real integration works. Required extension hooks need a concrete upstream patch and dependency-isolation evidence before release; opening that patch is a separate publication action within the overall authorized workflow.

**Metering**: preserve an interface for stepped values rather than implement them as many boolean segments. Final design must cover configured allowance/reset period, first-visit round trip, cache-HIT advancement, repeat-article counting, concurrent requests, expiry, reset, subscribers, crawler interactions, and cookie refusal. Do not infer unlimited free access from absent cookies. No origin-only increment can satisfy the HIT requirement. A worker simulator may demonstrate `anon/m1/m2/m3/wall`, but label this simulated and do not expose a production bypass on the basis of it. Coordinate policy with existing Newspack settings rather than add per-segment metering controls. PHP/worker serialization conformance and encrypted-mode support must be established before two cookie writers are enabled.

**Crawlers**: edge classification selects a separate segment. A deliberate provider/policy mapping must determine which gated sections a crawler may view; do not silently treat crawler membership as universal subscriber membership. Origin trusts only the verified integration boundary. The accepted user-agent-spoofing policy must be stated, and unrecognized crawlers get the stripped anonymous view. Generate paywall structured data with `isAccessibleForFree` and accurate `hasPart/cssSelector` selectors for actual gated sections; do not inject article text into public JSON-LD. Validate fixture selectors locally; Google's Rich Results Test and real crawler variant behavior remain live evidence requirements. Origin user-agent tests alone do not prove edge classification.

## 9. Ordered implementation slices and acceptance tests

1. **Baseline and genuine transport spike.** Record branch/base, versions and upstream class commit. Run doctor, install pinned dependencies, verify the isolated site identity/port, and run existing smoke tests. Add transport contract and tests against the real class for registration-before-parse, repeat-registration protection, exact membership, removal, expiry/revocation handling, real cookie output, and REST timing. Stop cache-integration claims if timing is unsupported.
2. **Registry and administration.** Implement CPT/repository, stable mapping, early registry, accessible list/create/rename/activate/deactivate UI, authorization and deletion vetoes. Verify persistence and stable IDs with all deletion paths and cross-site boundaries. No membership is granted by record creation.
3. **Public membership API and provider flow.** Implement versioning/errors, assignment/revocation, real POST-cookie-redirect-GET, uncacheable client route, and direct PHP caller. Test unauthorized attempts, malformed values, inactive/invalid IDs, unsupported versions, too-late calls, and a non-browser caller retaining the returned cookie for a later request. A test HTTP client is not a real MCP action; record which was exercised.
4. **Collector and Group rendering.** Add exact consulted-set tracking and pre-render gating; test false reads, deduplication, inactive/invalid references, nested gates, no dynamic child execution on denial, permitted children, unchanged saved attributes, and no login-only grant. Add Group editor UI/build and persistence/reopen/clear/inactive scenarios.
5. **Response protection.** Cover raw and rendered REST, excerpts, RSS/Atom, public pages, archive/search, and permissioned editing. Add adversarial unique-byte fixtures for every configured segment and compare both positive and negative requests. Complete leak-surface matrix rather than stopping at a successful page demo.
6. **Finalization and local cache model.** Implement buffered origin reporting with conservative no-store fallback; test all output paths and early-exit behavior. Add per-response cache-model tests for nested sets, alternating reader order, negative values, irrelevant memberships, no-read responses, and invalidation. Report these as simulations only.
7. **Integration exemplars and limitations.** Build the worked Newspack provider bridge against verified APIs or clearly scoped fixtures while documenting missing upstream hooks. Add metering/crawler policy/conformance fixtures without claiming deployed workers. Produce a reproducible demo guide and an evidence ledger with pass/fail/unverified labels.
8. **Required quality gates and handoff.** Build assets; run PHPCS with zero warnings/errors, PHPStan level 8, JS lint, PHPUnit, then E2E sequentially. Self-review security/contract edges and fix in-scope failures. Record exact commands/results, unsupported paths, and honest prototype scope. Sign any requested commits; do not weaken lint or bypass checks.
9. **Separate production-validation phase.** Deploy to a VIP test application only through the authorized workflow. Verify the narrow edge signal, cache storage/matching, HIT/MISS behavior, all-variant purge, cookie timing, worker advancement, crawler behavior, and real integration dependency isolation. Open required upstream patches. Collect realistic gate co-occurrence data before benchmarking. This phase is required for the product finish line, but is not replaced by completing the local prototype.

## 10. Verification environment and evidence ledger

Use this worktree's own wp-env dev/tests instances, separate from the original prototype and shared Newspack environment. Respect the assigned explicit test port and pass `WP_BASE_URL`; do not let Playwright reuse a foreign server just because it answers. Verify active plugin checkout and database before destructive fixture setup. PHPUnit and E2E share this worktree's test database and must run sequentially. Preserve E2E setup's real-theme activation after PHPUnit resets the database. Dependency installation is not evidence that tests ran. Add CI build step for the new editor bundle and verify distributable assets.

Unit/integration tests belong under `tests/unit`, E2E and raw HTTP checks under `tests/e2e`, optional analysis/cache-model tooling under `tests/debugging`. Fixtures and harnesses must not expose public test-grant endpoints in the shipped plugin. Run the minimum targeted checks while iterating, then the configured complete gates once after integration. Test PHP 8.1 compatibility and the WordPress support floor in addition to the default PHP 8.3/latest WordPress environment where feasible; record untested combinations.

Maintain `docs/prototype-evidence.md` during implementation with:

- Fixture URLs/IDs and environment, plugin, WordPress, PHP, VIP source and browser versions.
- Every acceptance criterion mapped to a test/result; no invented coverage percentage.
- Leak matrix containing method, audience, raw-response result, fix/limitation, and whether local or live.
- Provider timing trace, actual cookie round trip, contract error coverage, and invalidation events versus actual purge evidence.
- Explicit separation of fake transport tests, genuine local VIP-class tests, simulated cache tests, and live VIP tests.
- Newspack dependency-isolation result, patch status, real MCP caller status, metering worker status, and crawler/Rich Results status.
- Buffering TTFB/peak-memory measurements and an agreed ceiling before production enablement.
- For VIP benchmarking: the same URLs and comparable workload before/after, warmup, segment distribution, per-page co-occurrence, baseline/observed hit rate, variant counts, and artifact locations. Hit rate must be within 5 percentage points. With differing consulted sets, count actual per-response predicates rather than misleadingly applying a single response's `2^n` bound to the entire URL; include negative states and stepped-state products.

No page-cache performance target, purge guarantee, worker behavior, Rich Results acceptance, publisher adoption, or production readiness can be reported as achieved from origin tests alone. The six-month publisher-adoption metric remains project tracking, not an implementation test.

## Plan completion

All three specification files have been processed into an ordered, reviewable implementation and acceptance-test plan. The remaining decisions are explicit conservative prototype policies or named integration evidence requirements. No implementation beyond this plan is included in the planning task.
