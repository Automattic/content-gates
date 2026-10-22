# Segment Admin

## Product Context and Goals

Deliver a VIP-native primitive that keeps restricted content off the wire for non-entitled anonymous readers while retaining edge-cache performance. The primitive consumes eligibility decisions from an external provider and provides tools to use the resulting segment membership to vary rendered content accordingly. Anonymous metering and crawler classification are the two (planned) exceptions where this project decides membership itself.

The product must be described as a gating primitive that needs an entitlement provider, plus a billing provider when selling access. “Content Gating” must not imply a complete monetization suite. Subscriptions and payments are separate integrations.

### Project deliverables

| Deliverable | Required result |
| --- | --- |
| Segment administration | Simple custom post type records with display name, immutable key, and active/inactive status, managed at Settings → Content Gates. Deactivation replaces deletion. |
| Editor assignment | A segment picker on Group blocks (one or more active segments, with an in/not-in toggle) and server-side withholding of the Group's contents. See Editor UI for the full rendering contract. |
| Template access | Public `is_reader_in_segment( $segment_id )` check usable in headers, footers, shortcodes, and widgets, without visual template-part controls. |
| Provider integration | Documented, versioned server- and client-side membership assignment contract; a worked Newspack `register_rule()` bridge is the only shipped first-party adapter. |
| Cache personalization | One `Vary_Cache` group per segment, with safe response variants and measured hit rate. Responses are keyed on the segments each URL declares before `send_headers`, narrowed by an Edge Worker; see [page-cache.md](page-cache.md). |
| Anonymous metering | A VIP Edge Worker advances each meter segment's `open` / `last` / `wall` state on every metered view, including cache hits, from the limit and period on the meter segment record. See the metering section of [page-cache.md](page-cache.md). No general eligibility-rule UI is implied. |
| Crawler classification | Search and AI crawlers receive VIP-maintained, edge-selected crawler segments and accurate Google paywall structured data using `isAccessibleForFree` and `hasPart` with `cssSelector`. See the crawler section of [page-cache.md](page-cache.md). |

Metering is a project deliverable. The design is in [page-cache.md](page-cache.md); integration evidence on a VIP test application is still required. A meter segment's limit and period are configured on its segment record.

## Use Cases

- As a publisher, I can open **Settings → Content Gates** in `wp-admin` to view segments, create a segment, edit its name, and see or change its active/inactive status. The site's active segments appear by name in the **Segments** control in Group block settings, with their keys used for assignments.
- As a publisher, I can deactivate a segment without losing its identity or existing content references, and reactivate the same segment later. Deactivated segments remain visible in the admin list with their status; there is no delete action.
- As a publisher, I can reopen the list editor and see the saved segment names and statuses. Adding, deactivating, reactivating, or reconfiguring a segment must account for affected content and cache variants; an ordinary content-edit purge alone is insufficient for a site-wide configuration change.
- As a publisher using Newspack, I can connect its eligibility decisions through the supplied `register_rule()` bridge. The bridge assigns membership; this primitive then makes that membership available to restriction checks and cache personalization.
- As a publisher using another entitlement provider, I can have that provider assign a reader to a configured segment through the documented, versioned server- or client-side contract. Other providers use this contract rather than requiring a first-party adapter or a new eligibility engine in the admin UI.
- As a publisher metering anonymous readers, I can use Newspack's existing free-article count and reset-period settings. A VIP Edge Worker maintains and advances the `open` / `last` / `wall` meter state even on cache hits, which never execute PHP.
- As a publisher, I have VIP-maintained crawler segments, such as search crawlers and AI crawlers, classified at the edge per request. I can assign content to them but not edit them. Their rendered view includes the required paywall structured data. This and anonymous metering are the only membership decisions this project makes itself.
- As a template developer, I can check membership with the documented public PHP call `is_reader_in_segment( $segment_id )` in a header, footer, shortcode, or widget without using an editor control.

## Finish Line / Verification

### Admin behavior and dependency isolation

- On a VIP test application, verify that the **Settings** menu contains a **Content Gates** submenu item and that it opens a page titled **Content Gates** with the segment list editor. Create a segment, edit its name, and change its status. Verify that each segment is a custom post type record and that reopening the page loads its saved name and status.
- Confirm that renaming, deactivating, and reactivating a segment retain the same key and existing Group block assignments. The Group block's **Segments** control offers active segments from the current site. Inactive segments remain identifiable in existing assignments, are marked inactive, and are excluded from new assignment choices.
- Verify that segments cannot be trashed or permanently deleted, including unreferenced segments. Omit individual and bulk trash/delete actions and reject deletion through supported server-side paths, including REST and standard WordPress post deletion functions; hiding an action alone is insufficient.
- Confirm that the editor and membership/cache integration read the saved segment records by key. Reopening the admin and editor must preserve existing content-to-segment references when unrelated list entries change.
- Demonstrate that the custom post type segment admin functions without Reader Activation or WooCommerce. Verify content assignment, any reused `Content_Gate` / `Block_Visibility` / `Access_Rules` restriction checks, and metering settings with those dependencies absent or stubbed out; compatibility of reused Newspack components remains a discovery requirement. The original gate-builder UI is replaced by this simple admin and is not a release dependency.

### Membership and cache integration

- Document and version the assignment API's signature, return value, cookie-setting mechanism, and deprecation policy. Verify server- and client-side assignment, the worked Newspack `register_rule()` bridge, and a real MCP-style assignment action that takes effect on a later request. The contract is specified in [api.md](api.md). The plugin ships no membership HTTP route; providers that need one write their own (see [decisions.md](decisions.md)).
- Verify that `is_reader_in_segment( $segment_id )` reports the membership used for content restriction, including outside post rendering in template code.
- Register each segment as its own `Vary_Cache` group. Readers may belong to multiple segments; each response is keyed on membership values for the segments the URL declares before `send_headers`, as specified in [page-cache.md](page-cache.md), rather than on one mutually exclusive tier or the entire cookie. Prove that registration and cookie-writing assignment occur before `send_headers` on every path that calls them; late calls are a feasibility blocker. Send the declared set with the response; reads outside the declared set must fail closed.
- Exercise anonymous metering through the configured allowance and reset period on warm cache hits as well as cold responses. Verify the accepted first-visit trade-off: a round trip is required before metered access is granted, and refusing cookies cannot repeatedly obtain a free first article.
- Verify the separate crawler variant, accurate `isAccessibleForFree` and `hasPart` / `cssSelector` markup, and Google's Rich Results Test acceptance. Document that user-agent classification can be spoofed and an unrecognized crawler receives the stripped anonymous view.
- For segment additions, deactivation, reactivation, reconfiguration, and provider rule changes, identify all affected URLs and purge every segmented and metered variant. Reproduce the behavior on live traffic in a VIP test application and inspect the first cold-cache response after purging, as well as subsequent hits. Deactivating a segment must not leave stale entitled content in cache.
- Obtain realistic segment-count and per-page co-occurrence data from Newspack engineering before validating cache cardinality. Ask how many distinct gates publishers configure and how often one post combines assignments to more than one or two gates. No VIP publisher was live with this primitive at discovery time, so its own usage could not answer this. Use the measured numbers in a synthetic test; gated URLs must retain an edge hit rate within 5 percentage points of the baseline on the same URLs. No segment limit or measured cardinality has been established yet.
- Pass the adversarial suite across every configured segment: no gated bytes in non-entitled anonymous page renders, REST responses, or RSS/feeds, and no cross-segment cache leakage. Record triage results for oEmbed, AMP, head metadata, sitemaps, listing teasers, and cached objects; any remaining gaps on those other surfaces must be explicit known limitations.

## Accepted Risks and Trade-offs

- **Cookie forgery:** discovery identified `vip-go-auth` as AES-128-CBC with a static per-site key and IV, without a MAC/HMAC integrity check. Padding validation alone does not prove an authentic membership claim. Both edge and PHP may also accept plaintext `vip-go-seg` when `vip-go-auth` is absent, even when encryption is enabled. An Edge Worker could strip the plaintext fallback, but that is a candidate mitigation, not a decided requirement. Accept the forgery risk for this slice and revisit if forgery is demonstrated in practice. Record and verify the deployed platform's behavior; do not claim encryption makes entitlement tamper-proof or require a new cryptographic format as a release blocker.
- **Crawler spoofing:** classification uses user agent. A spoofed crawler user agent receives the crawler variant; an unrecognized crawler receives the stripped anonymous view. This accepted limitation qualifies the zero-leak claim and must be visible in release documentation.
- **First metered visit:** a genuine first-time reader sees the gate one request earlier than in Newspack's current flow. Require a round trip before granting metered access so refusing cookies cannot repeatedly obtain a free first article.
- **Configuration invalidation:** content-edit invalidation does not cover site-wide changes. Segment additions, deactivation/reactivation, reconfiguration, and provider rule changes need a purge across every affected URL and variant. Preserve segment records and assignments throughout.
- **Feasibility and scope:** reliable pre-`send_headers` registration/assignment, real segment cardinality, and the necessary upstream hooks are unresolved. Validate timing before significant implementation investment. Keep product messaging clear that entitlement and billing remain separate.

## Release Success and Ownership

Technical completion means the project deliverables run on a VIP test application, the adversarial cache-correctness suite passes for every configured segment, and every leak-surface finding is either fixed or explicitly recorded as an allowed known limitation. The editor spec defines the required test evidence. Page HTML, REST, and feeds must contain zero restricted bytes for non-entitled requests under the agreed access policy and documented risk exceptions. Gated URLs must stay within 5 percentage points of their baseline hit rate on the same URLs with a comparable workload.

## Implementation References

- [Newspack Access Control source](https://github.com/Automattic/newspack-workspace/tree/main/plugins/newspack-plugin/includes/content-gate): `Content_Gate`, `Block_Visibility`, `Content_Restriction_Control`, `Access_Rules`, and `Metering` are integration references. Reuse useful engine hooks and patterns without importing the full gate configuration model.
- [Newspack Reader Activation source](https://github.com/Automattic/newspack-workspace/tree/main/plugins/newspack-plugin/includes/reader-activation): an example entitlement/identity integration to support, not a required dependency of segment administration.

## Temporary No-Go

- Complex gate/segment configuration is deferred. For now, each segment is a custom post type record with a name, stable ID, and active/inactive status. Do not add per-segment content/access rules, priority, or layouts to this list editor. A meter segment's limit and period are the only per-segment settings. Provider integration and edge metering remain separate concerns.
- Do not build evaluation of identity-based eligibility rules into this primitive: subscription status, one-time purchases, email domains, reader data, institutional access, and IP-based access belong to the entitlement provider. Anonymous metering and crawler classification are the explicit exceptions for this slice.
- Do not ship first-party adapters for Passport or other providers beyond the Newspack `register_rule()` bridge. They can integrate through the public assignment contract.
- A fill endpoint and fragment hydration are separate work. Membership assigned after the default render must not imply that withheld content can be restored in that same response.
- Subscription management, billing, payments, promotions, and analytics are not supplied by this primitive; those capabilities require WooCommerce and WooPayments in the described monetization stack. Content gifting, premium newsletters, group subscriptions, and identity/registration/verification changes are also outside this slice.
- Do not invent a segment cap, new eligibility-rule UI, or cache-purge mechanism to close an unanswered discovery question. The cookie stays in `Vary_Cache` format. Validate the integration and document the chosen behavior first.

## Permanent No-Go

These are boundaries of the segment primitive's contract, rather than a claim that integrations can never grow.

- Once created, a segment must never be trashed or permanently deleted. Retire it by deactivating it while preserving its ID and references.
- A segment definition or content assignment must never, by itself, grant a reader entitlement. Consume provider decisions through the assignment contract; keep membership qualification separate from content assignment.
- Never use client-side hiding or reveal of already-delivered restricted content as the enforcement mechanism for a non-entitled anonymous request.
- Never collapse distinct membership combinations into a shared cache variant that can expose one segment's content to another reader.
- Never require PHP or an external entitlement service to run on every cached page request to maintain the anonymous meter or select the correct segment view.
