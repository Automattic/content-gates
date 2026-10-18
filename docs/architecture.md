# Prototype architecture

Content Gates keeps segment definition, reader membership, rendering, and
cache signaling separate. This boundary lets an entitlement provider decide
membership without receiving post content or controlling rendering.

## Request flow

1. WordPress registers segment records at `init`. Each record maps to the
   stable cache group `cg_<site-id>_<post-id>`.
2. A provider assigns or removes membership before `send_headers`, through the
   PHP API or the protected prototype REST endpoint.
   The REST path explicitly flushes the queued platform cookie because core
   REST dispatch exits before WordPress runs its normal `send_headers` action.
3. A Group block with `contentGateSegmentId` calls the membership service.
   Every call records the cache group, including a call that returns false.
4. `pre_render_block` returns an empty string for a denied Group. WordPress
   does not render its wrapper or child blocks.
5. After rendering, the response finalizer freezes the consulted set and emits
   the candidate `X-Content-Gate-Vary` response header. REST responses use the
   same finalizer.

The cache adapter uses WPVIP `Vary_Cache` when it is available. A plaintext
adapter exists only for local WordPress environments so the prototype can be
tested without VIP MU plugins. Outside a local environment, missing
`Vary_Cache` makes assignments fail and membership reads return false.
The platform adapter also expires both platform cookie forms when removal
leaves no membership, because the current platform writer skips an empty
serialized group value.

## Prototype decisions

- Segment custom post type: `content_gate_seg`.
- Active status: `publish`; inactive status: `cg_inactive`.
- New segments start active.
- The public identifier is the site-local post ID.
- The cache group includes the site ID and post ID. Renames do not change it.
- Invalid and inactive restrictions fail closed.
- Inactive segments reject assignment. Removal remains available so providers
  can clear stale membership.
- REST assignment requires `manage_options` by default. An entitlement adapter
  can replace this decision with the documented permission filter.

These choices are provisional until stakeholder and live-WPVIP review.

## Content protection

Only Group blocks accept editor assignments. The selected post ID is saved in
block attributes. Reader-specific visibility is never saved. Anonymous public
rendering, rendered REST content, feeds, and automatic excerpts use the same
server-side block decision. Login alone does not grant membership.

Segment deletion is blocked by capabilities and the WordPress trash and delete
filters. Deactivation preserves the post ID and existing block references.
