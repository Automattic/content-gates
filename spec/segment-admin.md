# Segment Admin

Source: [Content Gating PRD](https://linear.app/a8c/project/cms-content-gating-cache-aware-anonymous-gating-7ee422c04ec1/overview), especially “What's in this version,” “Out of Scope,” and “Open items.” The [companion PRD](https://linear.app/a8c/document/prd-detail-discovery-plan-competition-risks-ownership-e2a33a453bdb) adds discovery criteria, cache-invalidation requirements, and accepted risks.

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
- Demonstrate that the custom post type segment admin functions without Reader Activation or WooCommerce. Verify content assignment, `Content_Gate` / `Block_Visibility` / `Access_Rules` restriction checks, and metering settings with those dependencies absent or stubbed out; compatibility of those Newspack components remains a discovery requirement.
- Reuse the upstream engine through accepted extension points rather than maintain a fork. Draft and open the necessary patch against `newspack-workspace`; likely requirements are suppressing client-side metering reveal and connecting `Vary_Cache` membership to `Content_Gate` restriction checks.

### Membership and cache integration

- Document and version the assignment API's signature, return value, cookie-setting mechanism, and deprecation policy. Verify server- and client-side assignment, the worked Newspack `register_rule()` bridge, and a real MCP-style assignment action that takes effect on a later request. The exact contract remains a discovery decision; this spec does not prescribe an endpoint path or payload.
- Verify that `is_reader_in_segment( $segment_id )` reports the membership used for content restriction, including outside post rendering in template code.
- Register each segment as its own `Vary_Cache` group and verify variants for full combinations of reader memberships, not a single mutually exclusive reader tier. Prove that registration and assignment occur before `send_headers` on every path that calls them; late calls are a feasibility blocker.
- Exercise anonymous metering through the configured allowance and reset period on warm cache hits as well as cold responses. Verify the accepted first-visit trade-off: a round trip is required before metered access is granted, and refusing cookies cannot repeatedly obtain a free first article.
- Verify the separate crawler variant, accurate `isAccessibleForFree` and `hasPart` / `cssSelector` markup, and Google's Rich Results Test acceptance. Document that user-agent classification can be spoofed and an unrecognized crawler receives the stripped anonymous view.
- For segment additions, deactivation, reactivation, reconfiguration, and provider rule changes, identify all affected URLs and purge every segmented and metered variant. Reproduce the behavior on live traffic in a VIP test application and inspect the first cold-cache response after purging, as well as subsequent hits. Deactivating a segment must not leave stale entitled content in cache.
- Obtain realistic segment-count and per-page co-occurrence data from Derrick Koo or Newspack engineering before validating cache cardinality. Use those numbers in a synthetic test; gated URLs must retain an edge hit rate within 5 percentage points of the baseline on the same URLs. The PRD supplies no segment limit or measured cardinality yet.
- Pass the adversarial suite across every configured segment: no gated bytes in non-entitled anonymous page renders, REST responses, or RSS/feeds, and no cross-segment cache leakage. Record triage results for oEmbed, AMP, head metadata, sitemaps, listing teasers, and cached objects; any remaining gaps on those other surfaces must be explicit known limitations.

### Decisions still required

- Choose the custom post type slug, the WordPress post-status values representing active/inactive, and the initial status for newly created segments. The storage model and stable post ID are decided.
- Define how deactivation affects existing restrictions, membership checks, and assignment API calls before implementation. Existing references must be preserved and shown as inactive; deactivation must not silently remove content assignments. The runtime access behavior is not yet specified.
- Resolve dependency isolation, the upstream extension points, API contract details, pre-`send_headers` timing, and realistic cache cardinality before claiming the corresponding verification criteria are met.
- Carry forward the PRD's accepted cookie-forgery risk as a known limitation. The companion document notes the lack of cookie integrity protection and plaintext-cookie fallback; it does not require a new cryptographic format as a release blocker. Do not claim that encrypted membership cookies prove an entitlement cannot be forged.

## Temporary No-Go

- Complex gate/segment configuration is deferred. For now, each segment is a custom post type record with a name, stable ID, and active/inactive status. Do not add per-segment content/access rules, priority, layouts, or metering settings to this list editor. Provider integration and edge metering remain separate concerns.
- Do not build evaluation of identity-based eligibility rules into this primitive: subscription status, one-time purchases, email domains, reader data, institutional access, and IP-based access belong to the entitlement provider. Anonymous metering and crawler classification are the explicit exceptions for this slice.
- Do not ship first-party adapters for Passport or other providers beyond the Newspack `register_rule()` bridge. They can integrate through the public assignment contract.
- A fill endpoint and fragment hydration are separate work. Membership assigned after the default render must not imply that withheld content can be restored in that same response.
- Subscription management, billing, payments, promotions, and analytics are not supplied by this primitive; the PRD leaves those capabilities to WooCommerce and WooPayments. Content gifting, premium newsletters, group subscriptions, and identity/registration/verification changes are also outside this slice.
- Do not invent a segment cap, new eligibility-rule UI, cookie/API format, or cache-purge mechanism to close an unanswered discovery question. Validate the integration and document the chosen behavior first.

## Permanent No-Go

These are boundaries of the segment primitive's contract, rather than a claim that integrations can never grow.

- Once created, a segment must never be trashed or permanently deleted. Retire it by deactivating it while preserving its ID and references.
- A segment definition or content assignment must never, by itself, grant a reader entitlement. Consume provider decisions through the assignment contract; keep membership qualification separate from content assignment.
- Never use client-side hiding or reveal of already-delivered restricted content as the enforcement mechanism for a non-entitled anonymous request.
- Never collapse distinct membership combinations into a shared cache variant that can expose one segment's content to another reader.
- Never require PHP or an external entitlement service to run on every cached page request to maintain the anonymous meter or select the correct segment view.
