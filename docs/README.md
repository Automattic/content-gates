# Content Gates documentation

Content Gates is a WordPress plugin for cache-aware content gating on WPVIP.
External providers own entitlement decisions. This plugin maps those
decisions onto cache segments and removes restricted content from page,
REST, and feed responses.

## Status

Scaffold only. No gating behavior is implemented yet. See `spec/` for the
product specification.

## Public hooks

### `content_gates_loaded`

Action. Fires once after the plugin has registered its hooks. Providers
should hook here to register themselves.

| Argument | Type | Description |
|---|---|---|
| `$plugin` | `ContentGates\Plugin` | The plugin instance. |

Since 0.1.0.

### `content_gates_rest_membership_permission`

Filter. The provider authorization hook for `POST /content-gates/v1/membership`.
The plugin never decides entitlement itself: the default is `false`, so
without a provider every write is refused with `content_gates_forbidden`
(HTTP 403).

| Argument | Type | Description |
|---|---|---|
| `$allowed` | `bool\|WP_Error` | Whether the request may change membership. Default `false`. |
| `$request` | `WP_REST_Request` | The request, with `segment`, `action` and `value` params already sanitized. |

Return `true` to allow. Return a `WP_Error` to refuse with your own code,
message and `status` (sent as-is). Any other value refuses with
`content_gates_forbidden`.

Since 0.1.0.

## REST API

### `content-gates/v1/membership`

The uncacheable assignment surface for browser scripts and MCP-style
callers. Every response from this route, errors included, carries
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and
`Vary: Cookie`. Neither method records a membership read, so responses
never carry `X-Content-Gates-Vary`.

**`GET`** (public) returns the reader's current membership and the segment
list:

```json
{
  "contract": "1",
  "membership": { "newsletter": "1" },
  "segments": [
    { "id": 12, "key": "subscribers", "name": "Subscribers", "active": true },
    { "id": null, "key": "newsletter", "name": "newsletter", "active": true }
  ]
}
```

**`POST`** (authorized by `content_gates_rest_membership_permission`)
changes membership and returns the cookie on its own response, so the
next page view already carries it.

| Param | Type | Description |
|---|---|---|
| `segment` | `int\|string` | Required. Post ID, or key. Numeric strings are treated as IDs. |
| `action` | `"assign"\|"remove"` | Default `assign`. |
| `value` | `string` | Default `"1"`. Only `A-Z a-z 0-9 _ -`. Ignored on `remove`. |

Success is HTTP 200 with the `GET` shape plus `"changed": true` and, under
the local cookie simulation only, `"cookie": { "name", "value" }` echoing
the `Set-Cookie` header. Failures are HTTP 400 with the membership
layer's error code in `code` (`content_gates_invalid_segment`,
`content_gates_inactive_segment`, `content_gates_invalid_value`,
`content_gates_too_late`, ...). One request carries one `Set-Cookie`:
after a successful write the request is too late for another.

Since 0.1.0 (contract 1).
