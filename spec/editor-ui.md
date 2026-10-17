# Editor UI

Source: [Content Gating PRD](https://linear.app/a8c/project/cms-content-gating-cache-aware-anonymous-gating-7ee422c04ec1/overview), especially “What's in this version,” “Out of Scope,” and “Success and Measurement.” The [companion PRD](https://linear.app/a8c/document/prd-detail-discovery-plan-competition-risks-ownership-e2a33a453bdb) adds discovery criteria and accepted trade-offs.

This spec covers assigning content to reader segments in the editor and verifying that those assignments affect delivery. For this slice, a segment is a custom post type record with a name, stable post ID, and active/inactive status. Segments can be deactivated but cannot be deleted. Membership comes from an entitlement provider, except for anonymous metering and crawler classification.

The block UI adds a **Segment** dropdown to the settings for Group blocks (`core/group`). It lists the active segments on the current site by name and stores the selected segment's post ID as a block attribute. Each Group can select one segment, applying the restriction to the Group and its contents. A **No segment** choice is the default and clears the Group's own segment assignment.

## Use Cases

- As an editor, I can select a Group block and choose one of the site's active segments from a dropdown in its block settings. Segments are managed in the [Segment Admin](segment-admin.md) list editor at **Settings → Content Gates** in `wp-admin`.
- As an editor, I can group the blocks I want to restrict and assign a segment to that Group. I can change the selected segment or choose **No segment** to clear that Group's assignment.
- As an editor, I can save content assignments and reopen the content with those assignments intact. The editor assigns content to a segment; it does not decide whether an individual reader qualifies for membership.
- As an editor, I can still identify existing assignments to a deactivated segment, marked inactive. The segment is excluded from new assignment choices, and its existing references are preserved.
- As a non-entitled anonymous reader, I receive the public content and applicable gate, with restricted content omitted from the server response. Disabling JavaScript, inspecting source, or scraping the response does not reveal the restricted content.
- As an entitled reader, including an authenticated subscriber with a valid entitlement, I receive the content allowed by my segment memberships. Existing Newspack assignment and restriction semantics are preserved.
- As a publisher using anonymous metering, my content assignments work with the edge-managed `anon` / `m1` / `m2` / `m3` / `wall` sequence. A first-time reader sees the gate one request earlier than in Newspack today: granting metered access requires a round trip. The editor must not depend on a client-side reveal to restore withheld content.
- As a search or AI crawler, I receive the separate crawler variant with accurate paywall structured data. The editor's content assignments must remain compatible with identifying the gated portions in that markup.

## Server-Side Rendering

### Block visibility and rendering hooks

- WordPress core's block visibility support omits a block's HTML when `$block['attrs']['metadata']['blockVisibility']` is boolean `false`. Viewport-based visibility instead adds CSS and leaves the content in the response, so it must not be used for access enforcement. See the [core visibility implementation](https://github.com/WordPress/wordpress-develop/blob/trunk/src/wp-includes/block-supports/block-visibility.php) and [visibility documentation](https://make.wordpress.org/core/2026/03/15/block-visibility-in-wordpress-7-0/).
- Segment membership is our PHP decision; core visibility does not supply that decision. Persist only the segment ID on the Group, with the attribute registered on both the server and in the editor. Do not persist a particular reader's visibility result in the saved post.
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

    $segment_id = $block['attrs']['contentGateSegmentId'] ?? null;

    if ( null !== $segment_id && content_gates_should_withhold_segment( $segment_id ) ) {
        return '';
    }

    return $pre_render;
}, 10, 2 );
```

The predicate validates the segment reference and uses `is_reader_in_segment( $segment_id )` to decide access for an active segment. Inactive-segment behavior remains the explicit open decision in [Segment Admin](segment-admin.md). Invalid references must not be silently treated as **No segment**. Clearing the dropdown removes the attribute, represented as `null` in this example.

### Delivery contexts

- Apply enforcement to public server-rendered content, including REST and feed paths that render blocks. Do not exempt a request merely because `REST_REQUEST` or `is_admin()` is true. Any authoring-preview exception must check permission to edit the relevant content.
- A block render filter does not protect delivery paths that return raw saved content or generate excerpts without rendering blocks. Those paths need the same restriction decision before exposing restricted content.
- Resolve membership and cache personalization before rendering. The resulting HTML is specific to the reader's full membership combination; edge-cache hits must select that variant without requiring this PHP callback to run again.

## Finish Line / Verification

### Editor behavior and dependency isolation

- On a VIP test application, create active and inactive segments through **Settings → Content Gates**. Select a Group block and verify that its **Segment** dropdown offers only the current site's active segments by name, plus **No segment**. With no active segments, **No segment** remains available.
- Select a segment, save, and reopen. Confirm that its post ID is persisted in the Group's block attributes and used by the restriction check for the Group and its contents. Change the selection and clear it with **No segment**; verify that each change survives saving and reopening. A new Group starts with no segment assigned.
- Rename, deactivate, and reactivate an assigned segment. Confirm that its ID and existing assignments remain intact, inactive assignments are marked as such, and only active segments are offered for new assignments. Verify runtime behavior against the deactivation semantics once resolved in the Segment Admin spec; do not silently discard assignments.
- Exercise unassigned and restricted Groups within the same post, different Groups assigned to different segments, and readers with different combinations of memberships. Verify that a non-entitled reader receives none of the restricted Group's content. The dropdown selects one segment per Group; it does not expose AND/OR or priority rules.
- Demonstrate that editor content assignment and the associated `Content_Gate`, `Block_Visibility`, and `Access_Rules` restriction checks function with Reader Activation and WooCommerce absent or stubbed out. This is a discovery requirement, not an already-proven compatibility claim.

### Delivery and cache correctness

- For every configured segment, test entitled and non-entitled requests against the same content. Inspect raw responses, including with JavaScript disabled. Non-entitled anonymous page renders contain zero gated bytes; entitled requests retain the expected full content.
- Include nested Groups and a dynamic child block in a restricted Group. With the preferred pre-render approach, verify that a denied Group produces no wrapper or child HTML and its child render callback does not execute. A permitted outer Group must still allow a restricted inner Group to enforce its own segment check.
- Verify that rendering for different readers does not change saved block attributes, and that an entitled reader still respects an author's core visibility settings. Exercise rendered REST/feed responses and paths that expose raw content or derive excerpts separately.
- Repeat against cold origin responses and warm edge-cache responses, including alternating readers with different membership combinations. A cached entitled response must not be served to a non-entitled reader. Each segment is a `Vary_Cache` group, with a variant for the full membership combination encoded by the reader's cookie.
- Verify that the Newspack integration suppresses its client-side metering reveal and connects segment membership to the restriction check. Rendering gated content in hidden HTML is a failure even if the visible page looks correct.
- Edit gated content or its assignments, purge, and verify all affected segmented and metered variants, including the first response after the purge. Do not assume a URL purge has cleared every variant without observing it on the VIP test application.
- Verify zero restricted-content leaks through anonymous REST and RSS/feed responses as well as page HTML. Triage oEmbed, AMP, head metadata, sitemaps, listing teasers, and cached objects; record a definitive result for each. Other surfaces may ship with an explicit known limitation; page render, REST, and feeds must meet the zero-leak requirement.
- On the crawler variant, verify accurate `isAccessibleForFree` and `hasPart` / `cssSelector` paywall markup and acceptance by Google's Rich Results Test. Crawler classification belongs to the edge integration, not a new editor eligibility control.
- Include these scenarios in the project's adversarial cache-correctness suite. On representative gated URLs and realistic segment combinations, the edge hit rate must stay within 5 percentage points of the baseline for the same URLs. Coordinate this measurement with the cache implementation.

## Temporary No-Go

- Adding segment settings directly to block types other than Group is deferred. Editors wrap content in a Group to assign a segment.
- Complex gate/segment configuration is deferred. The editor selects active segment records by ID; it does not create or edit Newspack-style gate policies, priorities, layouts, or per-segment settings.
- A new visual, no-code interface for gating template parts, global patterns, or widget areas is outside this slice. Developers can gate those surfaces through the public `is_reader_in_segment( $segment_id )` PHP check.
- A fill endpoint or fragment hydration after the initial response belongs to the separate Content Fill Endpoint work. Do not add editor behavior that requires it for this release.
- Do not expand this slice into content gifting, premium newsletters, group subscriptions, or identity, registration, and verification changes.
- Do not treat integration discovery as complete until the required Newspack hooks have a concrete patch opened against `newspack-workspace`, dependency isolation is demonstrated, and cache-group registration and assignment timing before `send_headers` is validated. The PRD identifies suppression of client-side reveal and a segment-to-restriction bridge as likely patch requirements; their exact implementation remains open.

## Permanent No-Go

These are invariants of this editor's content-gating contract, rather than features deferred to another release.

- Never deliver restricted content to a non-entitled anonymous reader and rely on CSS, hidden markup, or JavaScript to conceal it.
- Never treat an editor assignment, login alone, or a preview of content as a grant of segment membership. Identity-based eligibility remains the entitlement provider's responsibility.
- Never make a reader's gated view depend on PHP running on every request or an external entitlement API call on every page request. Assignments must work with edge-cache hits and the cache-personalization contract.
