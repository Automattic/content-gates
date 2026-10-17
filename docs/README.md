# Content Gates documentation

Content Gates is a WordPress plugin for cache-aware content gating on WPVIP.
External providers own entitlement decisions. This plugin maps those decisions
onto stable segments and removes restricted Group block content from page, REST,
feed, and generated-excerpt responses.

## Prototype status

The prototype implements the segment-administration and Group-assignment slice:

- Segments are `content_gate_segment` custom posts.
- `publish` is active and `draft` is inactive. New segments are active.
- A segment's post ID is its stable public ID. Its cache group is `cg-<ID>`.
- Inactive and invalid assignments fail closed. Existing attributes retain the
  ID and the editor marks an inactive selection as inactive.
- Segment records cannot be trashed or permanently deleted.
- The Group block's `contentGateSegmentId` attribute stores one segment ID.
- `pre_render_block` withholds a denied Group before nested blocks render.
- The request reports consulted cache groups after rendering through
  `X-Content-Gates-Vary`. This header is a prototype edge signal; VIP support
  is still a platform validation item.

WPVIP's `Automattic\VIP\Cache\Vary_Cache` is the production membership
adapter. When it is unavailable, a compatibility adapter is enabled only when
`WP_ENVIRONMENT_TYPE` is `local` (or when a test explicitly defines
`CONTENT_GATES_ENABLE_LOCAL_VARY_CACHE`). It mirrors the `vip-go-seg`
boolean-group format so local tests can exercise later-request behavior. It also
sets a local-only `wordpress_content_gates_local_membership` cookie for members,
which makes the bundled Batcache send those requests to PHP; Batcache cannot
honor the prototype's late consulted-set signal. The local adapter is not
evidence that the VIP edge honors that signal.

## Publisher workflow

1. Open **Settings → Content Gates**.
2. Add a segment. It starts active.
3. Select a Group block and choose the segment in the **Content Gates** panel.
4. Save the post. Readers outside the segment do not receive the Group or any of
   its nested markup.
5. Deactivate a retired segment instead of deleting it. Existing assignments
   remain visible and fail closed until the same segment is reactivated.

The plugin does not decide subscription, purchase, email-domain, institution,
or other entitlement rules. A provider calls the membership API after making
that decision.

## Public PHP API

The provider contract is version `1`. Public functions keep their signatures
for that contract version. A future incompatible signature, cookie meaning, or
edge signal requires a new contract version and a deprecation period.

### `is_reader_in_segment( int $segment_id ): bool`

Returns whether the current request belongs to an active segment. Every call
records the segment in the consulted set, including denied, inactive, and
invalid lookups. It is safe to call from templates, shortcodes, headers,
footers, and widgets.

### `assign_reader_to_segment( int $segment_id ): true|WP_Error`

Assigns the current reader to an active segment. Call before `send_headers`,
then redirect or make a later request. Errors:

- `content_gates_assignment_too_late`
- `content_gates_inactive_segment`
- `content_gates_segment_not_registered`
- `content_gates_cache_api_unavailable`
- `content_gates_assignment_failed`

### `remove_reader_from_segment( int $segment_id ): true|WP_Error`

Removes the current reader from a persisted segment before `send_headers`.
Removal is allowed for inactive segments so providers can clear stale
membership. Errors use the same timing and adapter codes, plus
`content_gates_invalid_segment`.

### `content_gates_get_consulted_segments(): int[]`

Returns sorted segment post IDs consulted in the current request.

## REST assignment contract

`POST /wp-json/content-gates/v1/memberships/<segment-id>`

Request body:

```json
{ "active": true }
```

`active: true` assigns and `active: false` removes. The response includes
`contract_version`, `segment_id`, `active`, and
`applies_on: "next_request"`.

The default permission requires `manage_options`. A provider can replace or
narrow that check with `content_gates_can_assign_membership`. Do not make the
route anonymous without an authenticated provider protocol and abuse controls.

## Public hooks

### `content_gates_loaded`

Action. Fires once after the plugin has registered its hooks.

| Argument | Type | Description |
|---|---|---|
| `$plugin` | `ContentGates\Plugin` | The plugin instance. |

### `content_gates_can_assign_membership`

Filter. Controls whether the current REST caller can assign membership.

| Argument | Type | Description |
|---|---|---|
| `$allowed` | `bool` | Defaults to `current_user_can( 'manage_options' )`. |

### `content_gate_consulted_segments`

Filter. Receives the final list of consulted cache-group identifiers after the
response body has rendered and before the prototype signal header is written.

### `content_gate_finalize_variance`

Action. Fires after the consulted set is frozen.

## Known prototype boundaries

This branch does not claim validation of VIP edge-cache behavior, broad-header
suppression in upstream `Vary_Cache`, cache hit-rate targets, anonymous
metering, crawler classification/structured data, full purge behavior, or a
Newspack `register_rule()` bridge. Those remain release/discovery work in
`spec/`. The local tests prove origin withholding and membership transitions;
they do not substitute for a VIP test application.
