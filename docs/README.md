# Content Gates documentation

Content Gates is a WordPress plugin for cache-aware content gating on WPVIP.
External providers own entitlement decisions. This plugin maps those
decisions onto cache segments and removes restricted content from page,
REST, and feed responses.

## Status

Prototype. The plugin provides segment administration, Group block assignment,
server-side withholding, a versioned membership API, and consulted-segment
reporting. The edge signal, cache hit rate, metering, crawler classification,
and Newspack bridge still need validation or implementation outside this
repository. See [architecture.md](architecture.md) and
[known-limitations.md](known-limitations.md).

## Public hooks

### `content_gates_loaded`

Action. Fires once after the plugin has registered its hooks. Providers
should hook here to register themselves.

| Argument | Type | Description |
|---|---|---|
| `$plugin` | `ContentGates\Plugin` | The plugin instance. |

Since 0.1.0.

See [public-api.md](public-api.md) for the membership contract and extension
hooks.
