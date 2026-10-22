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
| `$plugin` | `Automattic\VIP\ContentGates\Plugin` | The plugin instance. |

Since 0.1.0.
