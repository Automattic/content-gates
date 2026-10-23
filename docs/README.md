# Content Gates documentation

Content Gates is a WordPress plugin for cache-aware content gating on WPVIP.
External providers own entitlement decisions. This plugin maps those
decisions onto cache segments, removes restricted content from page, REST,
and feed responses at origin, and tells the edge exactly which segments each
URL may vary on.

This is a prototype. Everything about the WPVIP edge is designed and
simulated locally; nothing has run on a VIP application yet. See
[known-limitations.md](known-limitations.md) before drawing platform
conclusions.

| Page | For | What it covers |
| --- | --- | --- |
| [local-development.md](local-development.md) | Humans and agents | One-command setup, demo flows, where to look when something is off |
| [architecture.md](architecture.md) | Engineers | Components, request flows, why the declared set exists |
| [api.md](api.md) | Theme and provider developers | The public PHP functions, hooks, errors, and timing window |
| [providers.md](providers.md) | Provider integrators | How a provider assigns membership; the Newspack bridge |
| [editor.md](editor.md) | Editors and engineers | The Segments control on Group blocks |
| [edge-worker.md](edge-worker.md) | Edge engineers | The VIP Edge Worker, its simulator, and their variances |
| [leak-surfaces.md](leak-surfaces.md) | Reviewers | Every delivery surface, its test, and its result |
| [known-limitations.md](known-limitations.md) | Everyone | Accepted risks, open platform questions, deferred work |

The product specification lives in `spec/`. Where this prototype departs
from the spec for a factual reason, the spec was corrected and the decision
recorded in `spec/decisions.md`. Proposed changes that were reviewed and
not adopted are in `spec/open-questions.md`, with what would reopen them.

## Hook reference

Every hook is prefixed `content_gates_`. Hooks are versioned with the
plugin; a hook is deprecated with `_deprecated_hook()` for two minor
releases before removal.

### Actions

| Hook | Arguments | When |
| --- | --- | --- |
| `content_gates_loaded` | `Plugin $plugin` | Once, after the plugin registered its hooks. Providers hook here. |
| `content_gates_declared` | `string[] $keys` | After the declared set is final and its header was sent. Sorted segment keys. |
| `content_gates_rest_headers_sent` | none | After the declared header went out on a REST response. The membership layer sends `Vary` and the cookie here. |
| `content_gates_segment_changed` | `Segment $segment, string $change` | After a segment was created, activated, deactivated, or reconfigured. |
| `content_gates_crawlers_synced` | `string[] $changed` | After crawler records were synchronized with `config/crawlers.json`. |
| `content_gates_purge_all` | `string $reason` | A site-wide purge is needed. The default listener calls WPVIP's site purge; the local fixture forwards it to the edge simulator. |
| `content_gates_newspack_rule_missing` | `string $slug` | A mapped Newspack rule is not registered, so nothing was assigned for it. |

### Filters

| Hook | Arguments | Purpose |
| --- | --- | --- |
| `content_gates_declared_segments` | `string[] $segments, WP_Query $query` | Add segment keys to the declared set. Keys from block assignments and earlier declarations cannot be removed. Unknown and inactive keys are dropped. |
| `content_gates_cookie_lifetime` | `int $seconds` | Lifetime of the `vip-go-seg` cookie. Default one day; `Vary_Cache` refuses less than one hour. |
| `content_gates_newspack_rules` | `array $mappings` | Newspack rule to segment mappings for the bridge. See [providers.md](providers.md). |

### Response headers

| Header | Sent when | Value |
| --- | --- | --- |
| `X-WPVIP-Content-Gates-Declared` | Every page, feed, and REST response that passed the plugin's finalize step | Sorted `Vary_Cache` group names, comma separated; empty when none |
| `X-WPVIP-Content-Gates-Meter` | The declared set of a singular page contains a meter; once per meter | `{group}; id={post_id}; limit={n}; period={seconds}` |
| `X-WPVIP-Content-Gates-View-As` | A logged-in editor is using the View As switcher | The chosen membership. Such responses are also `Cache-Control: no-store` |

The Edge Worker sends `X-WPVIP-Content-Gates-Edge: {version}` to origin on
every request; the plugin compares it with its own `config/crawlers.json`
version and shows an admin notice on a mismatch.
