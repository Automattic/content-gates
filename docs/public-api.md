# Public API

The prototype provider contract is version `1.0`. Function names and error
codes are public for this prototype. A future incompatible contract must use a
new version.

## PHP membership functions

```php
use function ContentGates\content_gates_assign_reader_to_segment;
use function ContentGates\content_gates_is_reader_in_segment;
use function ContentGates\content_gates_remove_reader_from_segment;

$result = content_gates_assign_reader_to_segment( 42 );

if ( true === $result && content_gates_is_reader_in_segment( 42 ) ) {
	// Render content allowed for segment 42.
}

content_gates_remove_reader_from_segment( 42 );
```

Assignment and removal return `true` or `WP_Error`. A membership read returns a
boolean and records the segment as a dependency of the current response.

| Error code | Meaning |
|---|---|
| `content_gates_invalid_segment` | The ID is not a saved segment. |
| `content_gates_inactive_segment` | New membership cannot be assigned to an inactive segment. |
| `content_gates_assignment_too_early` | Cache cookies have not been parsed yet. |
| `content_gates_assignment_too_late` | Headers were already sent. |
| `content_gates_provider_unavailable` | `Vary_Cache` is unavailable outside a local environment. |
| `content_gates_group_not_registered` | The local adapter did not register the cache group. |

Call assignment at `init` priority 10 or later in the request, and before
`send_headers`. This window ensures that WPVIP has parsed existing membership
before a change. On an edge cache hit, PHP does not run, so a provider must
assign membership through an origin request. The normal flow is an uncacheable
POST followed by a GET.

## Prototype REST endpoint

`POST /wp-json/content-gates/v1/memberships/<segment-id>` accepts:

```json
{ "member": true }
```

Set `member` to `false` to remove membership. The endpoint requires
`manage_options` by default and returns `Cache-Control: no-store, private`.
External providers can authenticate a caller and filter
`content_gates_rest_membership_permission`. This endpoint is provisional and
does not define the final third-party authentication scheme. The endpoint
flushes the queued WPVIP membership cookie before returning; this is required
because WordPress REST dispatch exits before the normal `send_headers` action.

## Hooks

### `content_gates_loaded`

Action. Fires after all plugin components register their hooks.

### `content_gate_consulted_segments`

Filter. Receives the sorted cache group keys consulted during the current
render. It runs once when the response set freezes.

### `content_gate_finalize_variance`

Action. Receives the final sorted cache group keys.

### `content_gates_cache_registration_error`

Action. Receives a `WP_Error` and segment ID when the cache adapter cannot
register a segment.

### `content_gates_rest_membership_permission`

Filter. Receives the default permission result and `WP_REST_Request`. A
provider must authenticate its caller before returning true.
