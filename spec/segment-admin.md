# Segment Admin

This file specifies segment management and carries the shared project context, discovery plan, and accepted risks. [Editor UI](editor-ui.md) specifies content assignment, server-side withholding, and delivery verification. Together with the [Content Gating API spec](content-gating-api.md), these are the implementation requirements. The original [PRD](https://linear.app/a8c/project/cms-content-gating-cache-aware-anonymous-gating-7ee422c04ec1/overview) and [discovery document](https://linear.app/a8c/document/prd-detail-discovery-plan-competition-risks-ownership-e2a33a453bdb) are provenance; the requirements below do not depend on reading them.

## Product Context and Goals

Deliver a VIP-native primitive that keeps restricted content off the wire for non-entitled anonymous readers while retaining edge-cache performance. Publishers should not need a custom build costing $150,000 or more, or an external paywall vendor, to obtain cache-safe gating. The primitive consumes eligibility decisions from Newspack, WooCommerce, or another provider and maintains the resulting segment membership. Anonymous metering and crawler classification are the two exceptions where this project decides membership itself.

Primary users are VIP publishers running or evaluating monetization and content-gating systems, with different content for anonymous and entitled readers and a dependence on VIP's page cache for performance and hosting cost. The initial demand identified by Cole Daylor includes MediaNews Group, Clarity Media Group, Unherd, The Spectator, Daily Kos, Las Vegas Review-Journal, and Recurrent Ventures; PMC was discussing Newspack at discovery time. These are demand signals, not confirmed adopters. Publishers without an entitlement provider who also do not want WooCommerce are outside this slice's target audience.

The product must be described as a gating primitive that needs an entitlement provider, plus a billing provider when selling access. “Content Gating” must not imply a complete monetization suite. Subscriptions and payments are separate integrations.

### Project deliverables

| Deliverable | Required result |
| --- | --- |
| Segment administration | Simple custom post type records with name, stable ID, and active/inactive status, managed at Settings → Content Gates. Deactivation replaces deletion. |
| Editor assignment | One active-segment dropdown on Group blocks, with server-side withholding of the Group's contents. See Editor UI for the full rendering contract. |
| Template access | Public `is_reader_in_segment( $segment_id )` check usable in headers, footers, shortcodes, and widgets, without visual template-part controls. |
| Provider integration | Documented, versioned server- and client-side membership assignment contract; a worked Newspack `register_rule()` bridge is the only shipped first-party adapter. |
| Cache personalization | One `Vary_Cache` group per segment, with safe response variants and measured hit rate. The API spec refines the original full-cookie proposal to key on the segments each response consulted. |
| Anonymous metering | VIP Edge Worker advances the `anon` / `m1` / `m2` / `m3` / `wall` sequence, including cache hits. Preserve configurable free-article count and reset period; no general eligibility-rule UI is implied. |
| Crawler classification | Search and AI crawlers receive a distinct edge-selected segment and accurate Google paywall structured data using `isAccessibleForFree` and `hasPart` with `cssSelector`. |

Metering is a project deliverable even though stepped-segment API details are future work in the current API draft. A final metering design and its integration evidence are still required; the simple segment admin does not provide per-segment meter configuration.

### Product rationale from discovery

The competitive assessment motivates the constraints; it is not a new vendor evaluation:

| Existing approach | Implication for this project |
| --- | --- |
| Newspack's client-side anonymous gate ships the article and then hides it. Its hosting has not had to solve VIP's cache-personalization problem. | Retain useful Newspack engine patterns while replacing delivery with server-side withholding and VIP cache integration. |
| Piano, Zephr, and similar enterprise products make server-side decisions through CDN integration or a dynamic layer. Newspack reported strong publisher usability for its own engine. | Avoid rebuilding the whole monetization system; this slice closes the anonymous-rendering and cache gap, not billing or entitlement portability. |
| MonetizationOS's Cloudflare-worker approach calls an external API per request. | Render once per cache variant and advance anonymous meters from a cookie at the edge, without an external entitlement API call on every page request. |

## Segment Model and Admin Design

This spec covers defining the segments used by the [Editor UI](editor-ui.md) and their integration with membership assignment and caching. A segment is a custom post type record representing a group of readers, such as “Subscribers” or “Newsletter members,” with a name, a stable post ID, and an active/inactive status. This is a content-gating primitive that consumes membership decisions, not a subscription or billing product.

Segment administration is a list editor on a page titled **Content Gates** under the **Settings** menu in `wp-admin` (**Settings → Content Gates**). Each segment is persisted as a custom post type record. The post ID identifies the segment independently of its name or status. Once created, a segment can be deactivated and reactivated, but cannot be trashed or permanently deleted. Complex gate configuration remains deferred. The saved records supply the segments used by the editor and membership/cache integration.

## Use Cases

- As a publisher, I can open **Settings → Content Gates** in `wp-admin` to view segments, create a segment, edit its name, and see or change its active/inactive status. The site's active segments appear by name in the **Segment** dropdown in Group block settings, with their post IDs used for assignments.
- As a publisher, I can deactivate a segment without losing its identity or existing content references, and reactivate the same segment later. Deactivated segments remain visible in the admin list with their status; there is no delete action.
- As a publisher, I can reopen the list editor and see the saved segment names and statuses. Adding, deactivating, reactivating, or reconfiguring a segment must account for affected content and cache variants; an ordinary content-edit purge alone is insufficient for a site-wide configuration change.
- As a publisher using Newspack, I can connect its eligibility decisions through the supplied `register_rule()` bridge. The bridge assigns membership; this primitive then makes that membership available to restriction checks and cache personalization.
- As a publisher using another entitlement provider, I can have that provider assign a reader to a configured segment through the documented, versioned server- or client-side contract. Other providers use this contract rather than requiring a first-party adapter or a new eligibility engine in the admin UI.
- As a publisher metering anonymous readers, I can use Newspack's existing free-article count and reset-period settings. A VIP Edge Worker maintains and advances the `anon` / `m1` / `m2` / `m3` / `wall` states even on cache hits, which never execute PHP.
- As a publisher, I have a separate crawler/bot segment, classified through the edge-worker mechanism. Its rendered view includes the required paywall structured data. This and anonymous metering are the only membership decisions this project makes itself.
- As a template developer, I can check membership with the documented public PHP call `is_reader_in_segment( $segment_id )` in a header, footer, shortcode, or widget without using an editor control.

## Finish Line / Verification

### Admin behavior and dependency isolation

- On a VIP test application, verify that the **Settings** menu contains a **Content Gates** submenu item and that it opens a page titled **Content Gates** with the segment list editor. Create a segment, edit its name, and change its status. Verify that each segment is a custom post type record and that reopening the page loads its saved name and status.
- Confirm that renaming, deactivating, and reactivating a segment retain the same post ID and existing Group block assignments. The Group block's **Segment** dropdown offers active segments from the current site. Inactive segments remain identifiable in existing assignments, are marked inactive, and are excluded from new assignment choices.
- Verify that segments cannot be trashed or permanently deleted, including unreferenced segments. Omit individual and bulk trash/delete actions and reject deletion through supported server-side paths, including REST and standard WordPress post deletion functions; hiding an action alone is insufficient.
- Confirm that the editor and membership/cache integration read the saved segment records by ID. Reopening the admin and editor must preserve existing content-to-segment references when unrelated list entries change.
- Demonstrate that the custom post type segment admin functions without Reader Activation or WooCommerce. Verify content assignment, any reused `Content_Gate` / `Block_Visibility` / `Access_Rules` restriction checks, and metering settings with those dependencies absent or stubbed out; compatibility of reused Newspack components remains a discovery requirement. The original gate-builder UI is replaced by this simple admin and is not a release dependency.
- Reuse the upstream engine through accepted extension points rather than maintain a fork. Draft and open the necessary patch against `newspack-workspace`; likely requirements are suppressing client-side metering reveal and connecting `Vary_Cache` membership to `Content_Gate` restriction checks.

### Membership and cache integration

- Document and version the assignment API's signature, return value, cookie-setting mechanism, and deprecation policy. Verify server- and client-side assignment, the worked Newspack `register_rule()` bridge, and a real MCP-style assignment action that takes effect on a later request. The exact contract remains a discovery decision; this spec does not prescribe an endpoint path or payload.
- Verify that `is_reader_in_segment( $segment_id )` reports the membership used for content restriction, including outside post rendering in template code.
- Register each segment as its own `Vary_Cache` group. Readers may belong to multiple segments; each response is keyed on membership values for the segments it consulted, as specified by the API wrapper, rather than on one mutually exclusive tier or the entire cookie. Prove that registration and cookie-writing assignment occur before `send_headers` on every path that calls them; late calls are a feasibility blocker. Report the consulted set after rendering, including failed membership checks.
- Exercise anonymous metering through the configured allowance and reset period on warm cache hits as well as cold responses. Verify the accepted first-visit trade-off: a round trip is required before metered access is granted, and refusing cookies cannot repeatedly obtain a free first article.
- Verify the separate crawler variant, accurate `isAccessibleForFree` and `hasPart` / `cssSelector` markup, and Google's Rich Results Test acceptance. Document that user-agent classification can be spoofed and an unrecognized crawler receives the stripped anonymous view.
- For segment additions, deactivation, reactivation, reconfiguration, and provider rule changes, identify all affected URLs and purge every segmented and metered variant. Reproduce the behavior on live traffic in a VIP test application and inspect the first cold-cache response after purging, as well as subsequent hits. Deactivating a segment must not leave stale entitled content in cache.
- Obtain realistic segment-count and per-page co-occurrence data from Derrick Koo or Newspack engineering before validating cache cardinality. Ask how many distinct gates publishers configure and how often one post combines assignments to more than one or two gates. No VIP publisher was live with this primitive at discovery time, so its own usage could not answer this. Use the measured numbers in a synthetic test; gated URLs must retain an edge hit rate within 5 percentage points of the baseline on the same URLs. No segment limit or measured cardinality has been established yet.
- Pass the adversarial suite across every configured segment: no gated bytes in non-entitled anonymous page renders, REST responses, or RSS/feeds, and no cross-segment cache leakage. Record triage results for oEmbed, AMP, head metadata, sitemaps, listing teasers, and cached objects; any remaining gaps on those other surfaces must be explicit known limitations.

### Decisions still required

For the stakeholder prototype in this repository, the implementation uses
`content_gate_seg`, `publish` for active, `cg_inactive` for inactive, and an
active initial state. It maps a segment to `cg_<site-id>_<post-id>`. Existing
inactive restrictions fail closed and continue to report their stable cache
group; new assignment is rejected and removal remains available. These are
provisional implementation decisions, not a final production contract.

- Choose the custom post type slug, the WordPress post-status values representing active/inactive, and the initial status for newly created segments. The storage model and stable post ID are decided. Specify how that site-local post ID maps to the API draft's named segment registry and cookie/cache identifiers; renaming the display name must not change the identifier.
- Define how deactivation affects existing restrictions, membership checks, and assignment API calls before implementation. Existing references must be preserved and shown as inactive; deactivation must not silently remove content assignments. The runtime access behavior is not yet specified.
- Resolve dependency isolation, the upstream extension points, API contract details, pre-`send_headers` timing, and realistic cache cardinality before claiming the corresponding verification criteria are met.
- The API draft still describes a reused gate builder and segment removal. These UI specs supersede those examples for administration: use the custom post type list and deactivation only. Its membership/cache contract remains relevant; resolve the stable-ID mapping and lifecycle behavior before integrating it.

### Discovery questions and required evidence

These are investigations, not assertions that the integrations already work. Named approaches are candidates unless this spec makes an explicit design decision. The protection and performance criteria are firm.

| Question | Evidence needed to close it |
| --- | --- |
| Can the required Newspack pieces run without Reader Activation or WooCommerce? | Working segment admin, editor assignments, reused restriction checks, and free-article/reset-period settings with those dependencies absent or stubbed. Prefer upstream extension hooks and avoid pulling in unrelated dependencies. |
| Does assignment work for agents and MCP callers? | A real MCP-style action invokes the assignment endpoint, receives the membership state, and gets the entitled view on a later request. A browser-only demonstration is insufficient. |
| Which delivery surfaces leak? | A definitive result for page render, REST, feeds, oEmbed, AMP, head metadata, sitemaps, listing teasers, and cached objects. Fix page/REST/feed leaks; fix or explicitly document gaps on the other surfaces. |
| Does purging remove every variant, and what happens immediately afterwards? | Reproducible live VIP traffic test across segmented and metered variants, followed by inspection of the first cold response and later hits after a content or configuration change. Existing purge documentation is not runtime evidence. |
| Is the crawler view SEO-correct? | Accurate paywall markup in the crawler response and acceptance by Google's Rich Results Test, without indexing or cloaking regressions. |
| Which Newspack hooks are required? | Concrete patch drafted and opened against `newspack-workspace`, plus working restriction/membership integration. Likely hooks suppress the client-side metering reveal and bridge segment membership into restriction checks. Newspack indicated willingness to accept upstream changes; that is not evidence a patch is merged. |
| Is cache cardinality realistic? | Publisher gate counts and per-post overlap from Newspack engineering, then a VIP synthetic workload with a hit rate within 5 percentage points of the same URLs' baseline. |
| Is the assignment contract ready for third parties? | Published signatures, return values/errors, cookie mechanism, contract version, and deprecation policy; Newspack's bridge works end to end without immediately changing the contract. Test registration and assignment before `send_headers` on every calling path. |

## Accepted Risks and Trade-offs

- **Cookie forgery:** discovery identified `vip-go-auth` as AES-128-CBC with a static per-site key and IV, without a MAC/HMAC integrity check. Padding validation alone does not prove an authentic membership claim. Both edge and PHP may also accept plaintext `vip-go-seg` when `vip-go-auth` is absent, even when encryption is enabled. An Edge Worker could strip the plaintext fallback, but that is a candidate mitigation, not a decided requirement. Accept the forgery risk for this slice and revisit if forgery is demonstrated in practice. Record and verify the deployed platform's behavior; do not claim encryption makes entitlement tamper-proof or require a new cryptographic format as a release blocker.
- **Crawler spoofing:** classification uses user agent. A spoofed crawler user agent receives the crawler variant; an unrecognized crawler receives the stripped anonymous view. This accepted limitation qualifies the zero-leak claim and must be visible in release documentation.
- **First metered visit:** a genuine first-time reader sees the gate one request earlier than in Newspack's current flow. Require a round trip before granting metered access so refusing cookies cannot repeatedly obtain a free first article.
- **Configuration invalidation:** content-edit invalidation does not cover site-wide changes. Segment additions, deactivation/reactivation, reconfiguration, and provider rule changes need a purge across every affected URL and variant. Preserve segment records and assignments throughout.
- **Feasibility and scope:** reliable pre-`send_headers` registration/assignment, real segment cardinality, and the necessary upstream hooks are unresolved. Validate timing before significant implementation investment. Keep product messaging clear that entitlement and billing remain separate.

## Release Success and Ownership

Technical completion means the project deliverables run on a VIP test application, the adversarial cache-correctness suite passes for every configured segment, and every leak-surface finding is either fixed or explicitly recorded as an allowed known limitation. The editor spec defines the required test evidence. Page HTML, REST, and feeds must contain zero restricted bytes for non-entitled requests under the agreed access policy and documented risk exceptions. Gated URLs must stay within 5 percentage points of their baseline hit rate on the same URLs with a comparable workload.

The adoption target is at least one publisher, migrated or new, running the product in production within six months. Record the measurement window and publisher outcome in project tracking; this is a business success metric, not something a test-site demonstration proves.

| Role | Owner or stakeholder |
| --- | --- |
| Accountable | Jacob Smith |
| Product | Jacob Smith |
| Engineering | Max Schmeling |
| Design | David Bowan |
| Edge-worker metering and segment-configuration design | Chris Zarate |
| Consulted: gating semantics and publisher requirements | Derrick Koo |
| Consulted: requirements ranking | Cole Daylor |
| Consulted: Newspack Access Control engine | Claudiu Lodromanean and Chris Reaume |
| Informed | Alan Smodic and Josh Fosburg |

## Implementation References

- [Newspack Access Control source](https://github.com/Automattic/newspack-workspace/tree/main/plugins/newspack-plugin/includes/content-gate): `Content_Gate`, `Block_Visibility`, `Content_Restriction_Control`, `Access_Rules`, and `Metering` are integration references. Reuse useful engine hooks and patterns without importing the full gate configuration model.
- [Newspack Reader Activation source](https://github.com/Automattic/newspack-workspace/tree/main/plugins/newspack-plugin/includes/reader-activation): an example entitlement/identity integration to support, not a required dependency of segment administration.
- [Standalone Woo Access Control](https://github.a8c.com/Automattic/woo-access-control): related implementation reference; this does not expand the shipped adapter scope.
- [VIP Cache Personalization API](https://docs.wpvip.com/caching/page-cache/customize-behavior/cache-personalization-api/) and [Vary_Cache implementation](https://github.com/Automattic/vip-go-mu-plugins/blob/develop/cache/class-vary-cache.php): platform mechanisms to validate on the VIP test application. The local API spec defines the wrapper and consulted-set requirements.

## Temporary No-Go

- Complex gate/segment configuration is deferred. For now, each segment is a custom post type record with a name, stable ID, and active/inactive status. Do not add per-segment content/access rules, priority, layouts, or metering settings to this list editor. Provider integration and edge metering remain separate concerns.
- Do not build evaluation of identity-based eligibility rules into this primitive: subscription status, one-time purchases, email domains, reader data, institutional access, and IP-based access belong to the entitlement provider. Anonymous metering and crawler classification are the explicit exceptions for this slice.
- Do not ship first-party adapters for Passport or other providers beyond the Newspack `register_rule()` bridge. They can integrate through the public assignment contract.
- A fill endpoint and fragment hydration are separate work. Membership assigned after the default render must not imply that withheld content can be restored in that same response.
- Subscription management, billing, payments, promotions, and analytics are not supplied by this primitive; those capabilities require WooCommerce and WooPayments in the described monetization stack. Content gifting, premium newsletters, group subscriptions, and identity/registration/verification changes are also outside this slice.
- Do not invent a segment cap, new eligibility-rule UI, cookie/API format, or cache-purge mechanism to close an unanswered discovery question. Validate the integration and document the chosen behavior first.

## Permanent No-Go

These are boundaries of the segment primitive's contract, rather than a claim that integrations can never grow.

- Once created, a segment must never be trashed or permanently deleted. Retire it by deactivating it while preserving its ID and references.
- A segment definition or content assignment must never, by itself, grant a reader entitlement. Consume provider decisions through the assignment contract; keep membership qualification separate from content assignment.
- Never use client-side hiding or reveal of already-delivered restricted content as the enforcement mechanism for a non-entitled anonymous request.
- Never collapse distinct membership combinations into a shared cache variant that can expose one segment's content to another reader.
- Never require PHP or an external entitlement service to run on every cached page request to maintain the anonymous meter or select the correct segment view.
