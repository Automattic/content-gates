# Editor UI

This file specifies content assignment, server-side withholding, and delivery verification. [Segment Admin](segment-admin.md) specifies segment management, shared project context, discovery, and accepted risks. Together with the [API contract](api.md), the [page cache design](page-cache.md), and the [decisions](decisions.md), these are the implementation requirements. The original [PRD](https://linear.app/a8c/project/cms-content-gating-cache-aware-anonymous-gating-7ee422c04ec1/overview) and [discovery document](https://linear.app/a8c/document/prd-detail-discovery-plan-competition-risks-ownership-e2a33a453bdb) are provenance; reading them is not required to understand this spec. Explicit decisions in these specs supersede the original proposals.

## Problem and Goals

Newspack's anonymous metering model sends the full restricted article and a gate in a hidden div, then uses `Metering::render_frontend_metering_gate()` and client-side JavaScript to choose what to show. A reader, bot, or agent can obtain the paid article by disabling JavaScript, inspecting source, or scraping the response. That model does not meet this project's protection requirement and does not use VIP's cache personalization to separate entitled and non-entitled responses.

The replacement must withhold restricted content at origin, retain fast edge-cache delivery, accept membership decisions from multiple entitlement providers, and serve search and AI crawlers an SEO-correct variant. It supports static, cookie-based memberships. Dynamic restoration after the initial render belongs to separate fill-endpoint work.

An entitled subscriber should retain access to the same full article they can read today; login alone is not an entitlement. Humans and agent/MCP callers must meet the same content-delivery requirements. The block dropdown assigns content to a reader segment; it does not implement subscriptions or determine membership.

## Scope and Editor Design

This spec covers assigning content to reader segments in the editor and verifying that those assignments affect delivery. For this slice, a segment is a custom post type record with a display name, an immutable key, and active/inactive status. Segments can be deactivated but cannot be deleted. Membership comes from an entitlement provider, except for anonymous metering and crawler classification.

The block UI adds a **Segments** control to the settings for Group blocks (`core/group`). It lists the active segments on the current site by name and stores the selected segments' keys as a block attribute, together with a **Show to** toggle: readers in any of the selected segments, or readers in none of them. A Group may list more than one segment, for example subscriber and crawler. The restriction applies to the Group and its contents. A Group with no segments selected is unrestricted; that is the default. [decisions.md](decisions.md) holds the withholding and multi-segment semantics.

## Use Cases

- As an editor, I can select a Group block and choose one or more of the site's active segments in its block settings, and choose whether the Group shows to readers in those segments or to readers in none of them. Segments are managed in the [Segment Admin](segment-admin.md) list editor at **Settings → Content Gates** in `wp-admin`.
- As an editor, I can group the blocks I want to restrict and assign a segment to that Group. I can change the selection, flip the **Show to** toggle, or clear all segments to remove that Group's restriction.
- As an editor, I can save content assignments and reopen the content with those assignments intact. The editor assigns content to a segment; it does not decide whether an individual reader qualifies for membership.
- As an editor, I can still identify existing assignments to a deactivated segment, marked inactive. The segment is excluded from new assignment choices, and its existing references are preserved.
- As a non-entitled anonymous reader, I receive the public content and applicable gate, with restricted content omitted from the server response. Disabling JavaScript, inspecting source, or scraping the response does not reveal the restricted content.
- As an entitled reader, including an authenticated subscriber with a valid entitlement, I receive the content allowed by my segment memberships. Integration must preserve legitimate subscriber access while applying this spec's Group-based assignments.
- As a publisher using anonymous metering, my content assignments work with the edge-managed `anon` / `m1` / `m2` / `m3` / `wall` sequence. A first-time reader sees the gate one request earlier than in Newspack today: granting metered access requires a round trip. The editor must not depend on a client-side reveal to restore withheld content.
- As a search or AI crawler, I receive the separate crawler variant with accurate paywall structured data. The editor's content assignments must remain compatible with identifying the gated portions in that markup.

## Server-Side Rendering

### Block visibility and rendering hooks

- WordPress core's block visibility support omits a block's HTML when `$block['attrs']['metadata']['blockVisibility']` is boolean `false`. Viewport-based visibility instead adds CSS and leaves the content in the response, so it must not be used for access enforcement. See the [core visibility implementation](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/block-supports/block-visibility.php) and [visibility documentation](https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/).
- Segment membership is our PHP decision; core visibility does not supply that decision. Persist only the segment keys and the show-to mode on the Group, with the attributes registered on both the server and in the editor. Do not persist a particular reader's visibility result in the saved post.
- Prefer the public [`pre_render_block`](https://developer.wordpress.org/reference/hooks/pre_render_block/) filter for enforcement. Inspect `core/group` and its segment attribute; when access is denied, return `''`. This short-circuits normal rendering of the entire Group, including its nested blocks and their dynamic render callbacks. Otherwise return the incoming `$pre_render` value so normal rendering and other filters can continue.
- If integrating through core visibility instead, use [`render_block_data`](https://developer.wordpress.org/reference/hooks/render_block_data/) to set `$parsed_block['attrs']['metadata']['blockVisibility'] = false` for a denied request. This change is in memory for that render only. Core applies visibility through `render_block`, after rendering the block's contents, so it omits the HTML but does not avoid child render work. Do not call core's private `wp_render_block_visibility_support()` function directly.
- When access is allowed, preserve existing core visibility settings. Membership must not force a block visible if its author has hidden it or override its viewport settings.
- Newspack's separate [`Block_Visibility` class](https://github.com/Automattic/newspack-workspace/blob/main/plugins/newspack-plugin/includes/content-gate/class-block-visibility.php) uses `render_block` and returns an empty string for hidden content. This is useful integration context, but our simple segment selector does not require importing its full access-rule model.

Illustrative enforcement callback; the attribute and helper names are provisional:

```php
add_filter( 'pre_render_block', function ( $pre_render, $block ) {
    if ( 'core/group' !== ( $block['blockName'] ?? '' ) ) {
        return $pre_render;
    }

    $keys = $block['attrs']['contentGatesSegments'] ?? array();
    $mode = $block['attrs']['contentGatesMode'] ?? 'in';

    if ( array() !== $keys && content_gates_should_withhold( $keys, $mode ) ) {
        return '';
    }

    return $pre_render;
}, 10, 2 );
```

The predicate validates each segment reference and calls `is_reader_in_segment()` per key with OR semantics: "in" withholds unless the reader is in at least one listed segment, "not in" withholds if the reader is in any of them. Inactive segments are decided in [decisions.md](decisions.md): they leave the declared set, so reads of them return `false`. Invalid references must not be silently treated as unrestricted. Clearing all segments removes the attribute.

### Delivery contexts

- Apply enforcement to public server-rendered content, including REST and feed paths that render blocks. Do not exempt a request merely because `REST_REQUEST` or `is_admin()` is true. Any authoring-preview exception must check permission to edit the relevant content.
- A block render filter does not protect delivery paths that return raw saved content or generate excerpts without rendering blocks. Those paths need the same restriction decision before exposing restricted content.
- Load the segment registry and perform cookie-writing assignments before `send_headers`. The plugin computes each post's declared set from its saved Group assignments at save time and stores it in post meta. At `send_headers` it unions the declared sets of the posts the response renders with any explicit declarations and sends the declared-set header. The cache variant depends on the reader's values for the declared set, including non-membership, as defined in [page-cache.md](page-cache.md). Full-cookie keying is not the current design.
- Every membership check goes through the public API. A read of a segment that is not in the declared set fails closed and returns `false`, so a render can never depend on a segment the cache key does not cover. Nested Groups need nothing extra: an inner Group's segments are in the post's declared set whether or not the outer Group renders. Non-HTML paths (feeds, oEmbed, REST) compute their union before output, as specified in [page-cache.md](page-cache.md).
- Do not store rendered gated HTML in object-cache entries, transients, or block fragment caches in this slice. Reusing a fragment can conceal the membership reads that produced it. Edge-cache hits must select the correct completed response without running the PHP block callback again.
- The cache-personalization contract targets anonymous and cookie-identified readers. Logged-in WordPress users bypass VIP's page cache; preserving their legitimate content access is still required, but their requests do not prove anonymous edge-cache correctness.

## Finish Line / Verification

### Editor behavior and dependency isolation

- On a VIP test application, create active and inactive segments through **Settings → Content Gates**. Select a Group block and verify that its **Segments** control offers only the current site's active segments by name. With no active segments, the control is empty and the Group stays unrestricted.
- Select one or more segments, save, and reopen. Confirm that their keys are persisted in the Group's block attributes, that the post's declared set in post meta lists them, and that the restriction check uses them for the Group and its contents. Change the selection, flip the **Show to** toggle, and clear all segments; verify that each change survives saving and reopening. A new Group starts with no segments assigned.
- Rename, deactivate, and reactivate an assigned segment. Confirm that its key and existing assignments remain intact, inactive assignments are marked as such, and only active segments are offered for new assignments. Verify the decided inactive semantics: the segment leaves the post's declared set, reads of it return `false`, and the saved assignment is preserved rather than discarded.
- Exercise unassigned and restricted Groups within the same post, different Groups assigned to different segments, and readers with different combinations of memberships. Verify that a non-entitled reader receives none of the restricted Group's content. A Group lists one or more segments with OR semantics and an in/not-in toggle; there is no rule builder or priority.
- Demonstrate that the Group dropdown and withholding work with Reader Activation and WooCommerce absent or stubbed out. Where the integration reuses Newspack's `Content_Gate`, `Block_Visibility`, `Content_Restriction_Control`, or `Access_Rules`, prove those paths work under the same conditions. Reusing their patterns does not require importing their full gate-builder or eligibility model; dependency isolation remains an unverified discovery requirement.

### Delivery and cache correctness

- For every configured segment, test entitled and non-entitled requests against the same content. Inspect raw responses, including with JavaScript disabled. Non-entitled anonymous page renders contain zero gated bytes; entitled requests retain the expected full content.
- Include nested Groups and a dynamic child block in a restricted Group. With the preferred pre-render approach, verify that a denied Group produces no wrapper or child HTML and its child render callback does not execute. A permitted outer Group must still allow a restricted inner Group to enforce its own segment check.
- Verify that rendering for different readers does not change saved block attributes, and that an entitled reader still respects an author's core visibility settings. Exercise rendered REST/feed responses and paths that expose raw content or derive excerpts separately.
- Repeat against cold origin responses and warm edge-cache responses, including alternating readers with different membership combinations. A cached entitled response must not be served to a non-entitled reader. Each segment is a `Vary_Cache` group; the response declares the segments the URL may read. Readers differing only in undeclared segments share a response. Verify that a post with nested Groups declares the inner Group's segments even for readers the outer Group denies.
- Verify that the Newspack integration suppresses its client-side metering reveal and connects segment membership to the restriction check. Rendering gated content in hidden HTML is a failure even if the visible page looks correct.
- Edit gated content or its assignments, purge, and verify all affected segmented and metered variants, including the first response after the purge. Do not assume a URL purge has cleared every variant without observing it on the VIP test application.
- Verify zero restricted-content leaks through anonymous REST and RSS/feed responses as well as page HTML. Triage oEmbed, AMP, head metadata, sitemaps, listing teasers, and cached objects; record a definitive result for each. Other surfaces may ship with an explicit known limitation; page render, REST, and feeds must meet the zero-leak requirement.
- On the crawler variant, verify accurate `isAccessibleForFree` and `hasPart` / `cssSelector` paywall markup and acceptance by Google's Rich Results Test. Crawler classification belongs to the edge integration, not a new editor eligibility control.
- Include these scenarios in the project's adversarial cache-correctness suite. On representative gated URLs and realistic segment combinations, the edge hit rate must stay within 5 percentage points of the baseline for the same URLs. Coordinate this measurement with the cache implementation.

### Release evidence

- Ship the editor assignment and rendering behavior on a VIP test application, alongside the segment registry, provider bridge, membership API, metering, and crawler features. A local demonstration alone does not prove edge behavior.
- Save fixtures covering every configured segment, raw-response assertions, warm/cold cache results, purge results, and crawler structured-data validation. Keep the benchmark workload, baseline hit rate, observed hit rate, and environment/version information with the results.
- Maintain a leak-surface matrix for page render, REST, feeds, oEmbed, AMP, head metadata, sitemaps, listing teasers, and cached objects. Each row records the test, result, and either a fix or an explicit known limitation. Page render, REST, and feeds require zero restricted bytes; gaps on those surfaces cannot be deferred. Newspack's prior leak incidents make REST and feeds release requirements rather than optional checks.
- The adversarial cache-correctness suite must pass. Account for every triage finding and carry the accepted cookie-forgery and user-agent-spoofing limitations from [Segment Admin](segment-admin.md) into release documentation; do not claim this is a tamper-proof entitlement system.

## Temporary No-Go

- Adding segment settings directly to block types other than Group is deferred. Editors wrap content in a Group to assign a segment.
- Complex gate/segment configuration is deferred. The editor selects active segment records by key; it does not create or edit Newspack-style gate policies, priorities, layouts, or per-segment settings.
- A new visual, no-code interface for gating template parts, global patterns, or widget areas is outside this slice. Developers can gate those surfaces through the public `is_reader_in_segment( $segment_id )` PHP check.
- A fill endpoint or fragment hydration after the initial response belongs to the separate Content Fill Endpoint work. Do not add editor behavior that requires it for this release.
- Do not expand this slice into content gifting, premium newsletters, group subscriptions, or identity, registration, and verification changes.
- Do not treat integration discovery as complete until the required Newspack hooks have a concrete patch opened against `newspack-workspace`, dependency isolation is demonstrated, and cache-group registration and assignment timing before `send_headers` is validated. Likely patch requirements are suppression of client-side metering reveal and a segment-to-restriction bridge; their exact implementation remains open.

## Permanent No-Go

These are invariants of this editor's content-gating contract, rather than features deferred to another release.

- Never deliver restricted content to a non-entitled anonymous reader and rely on CSS, hidden markup, or JavaScript to conceal it.
- Never treat an editor assignment, login alone, or a preview of content as a grant of segment membership. Identity-based eligibility remains the entitlement provider's responsibility.
- Never make a reader's gated view depend on PHP running on every request or an external entitlement API call on every page request. Assignments must work with edge-cache hits and the cache-personalization contract.
