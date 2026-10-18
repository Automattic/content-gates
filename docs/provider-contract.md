# Provider contract (version 1)

This is the versioned contract that entitlement providers and template
code build against. It covers the five public PHP functions, their error
codes, when they may be called, how membership reaches the edge cache, and
the REST endpoint for callers that cannot run PHP.

Contract version: `CONTENT_GATES_CONTRACT_VERSION` is `'1'`
(`content-gates.php`). The REST endpoint reports it as `"contract": "1"`.
The contract version is separate from the plugin version (`0.1.0`) and
from the cookie format version (`vc-v1`).

Status: prototype. Verified locally with PHPUnit against the plugin's own
local cookie adapter and a fake `Vary_Cache`. Not yet exercised on a WPVIP
application.

## Vocabulary

- A **segment** is a group of readers. It is a `Vary_Cache` group. Its
  **key** is the cache group name and appears in the cookie. Keys match
  `/^[A-Za-z0-9_-]+$/`.
- A **CPT segment** is a `cg_segment` post managed at Settings > Content
  Gates. It has a stable post ID, a renamable name, an immutable key, and
  an active or inactive status.
- A **code segment** is registered with `register_segment()`. It has no
  post ID and is always active. It lives in memory for one request, so it
  must be registered on every request.
- **Membership** is every segment value the reader holds, stored in the
  cookie.
- The **consulted set** is the segment keys one response read while
  rendering.

## Segment references

Every public function accepts `int|string`:

- `int`: the post ID of a CPT segment.
- `string`: a key. Code segments and CPT segments share one key space.
  The registry prevents collisions in both directions: creating a CPT
  segment whose generated key matches a code key appends a numeric
  suffix, and `register_segment()` refuses a key that an existing CPT
  segment already uses. The lookup rule "a code segment wins over a CPT
  segment with the same key" exists as a defensive default only.

## The five functions

All five live in `inc/functions.php` in the global namespace, wrapped in
`function_exists()` guards. Their names are deliberately unprefixed; the
spec fixes them. Each is a thin wrapper over
`ContentGates\Membership\Membership`. Each carries `@since 0.1.0` and
`@contract 1`.

### `register_segment( string $key ): true|WP_Error`

Registers a code segment. Call it on `content_gates_loaded`, or in any
case before `init` priority 1, which is when the plugin registers every
known key as a cache group.

| Error code | Meaning |
| --- | --- |
| `content_gates_not_booted` | Called before `ContentGates\Plugin::boot()` ran. Hook `content_gates_loaded` instead. |
| `content_gates_invalid_segment_key` | The key has characters outside `A-Z a-z 0-9 _ -`. |
| `content_gates_duplicate_segment_key` | A code or CPT segment with this key already exists. Registering the same key twice in one request returns this error; ignore it when a second boot is possible. |

The plugin registers one code segment itself: `crawler` (see
[The crawler segment](#the-crawler-segment)).

### `assign_reader_to_segment( int|string $segment, string $value = '1' ): true|WP_Error`

Puts the current reader into a segment. The change is written to the
cookie later in the same request and takes effect from the next request
that carries the cookie. The value defaults to `'1'`; any other value
matching `/^[A-Za-z0-9_-]+$/` is allowed (stepped segments such as
metering levels are a future use).

Errors are checked in this order and the first one wins:

| Error code | Meaning |
| --- | --- |
| `content_gates_not_booted` | Called before boot. |
| `content_gates_too_late` | The plugin's `send_headers` callback already ran, or the cookie was already flushed (REST). See [Timing](#timing-rules). |
| `content_gates_invalid_segment` | The reference matches no segment. |
| `content_gates_inactive_segment` | The segment is deactivated. Assignments to inactive segments are refused. |
| `content_gates_invalid_value` | The value has characters outside `A-Z a-z 0-9 _ -`. The adapter can return the same code. |
| `content_gates_invalid_segment_key` | From the adapter: the group name is invalid. Cannot normally happen for a resolved segment. |
| `content_gates_not_registered` | From the adapter: the group was never registered with the cache layer. The plugin registers a group on first assignment if `init` 1 missed it, so this indicates a cache-layer problem. |
| `content_gates_cache_error` | From the `Vary_Cache` adapter only: `Vary_Cache` returned an error code this plugin does not map. The original code is in the error data under `vary_cache_code`. |

### `remove_reader_from_segment( int|string $segment ): true|WP_Error`

Takes the current reader out of a segment by writing an empty value. The
same checks and codes as `assign_reader_to_segment()` apply, except
`content_gates_invalid_value`. Removal from an inactive segment is refused
with `content_gates_inactive_segment`.

### `is_reader_in_segment( int|string $segment, ?string $value = null ): bool`

Whether the current reader is in a segment. When `$value` is given, the
reader's stored value must equal it exactly.

This is a **recorded read**: for every resolvable segment the key is added
to the consulted set, whether the answer is `true` or `false`, and whether
or not anything is rendered as a result. The response is then keyed on
this segment at the edge.

Returns `false` and records nothing when:

- the reference matches no segment (also raises `_doing_it_wrong()`
  under `WP_DEBUG`), or
- the plugin is not booted.

Returns `false` and **does** record the read when the segment is
inactive. Inactive segments fail closed for every reader, including
readers whose cookie still carries a value for it.

### `get_reader_segment_value( int|string $segment ): ?string`

The reader's stored value for a segment, or `null` when the reader is not
in it. Same recording and failure rules as `is_reader_in_segment()`:
unknown references and the not-booted state return `null` without
recording; inactive segments return `null` and record.

### Helper: `content_gates_membership(): Membership|WP_Error`

Not one of the five, and prefixed. Returns the booted membership layer or
a `WP_Error` with code `content_gates_not_booted`. Use it for
`$membership->membership()` (the raw cookie state, **not** recorded) and
`$membership->consulted()` (the keys read so far). The test provider uses
it to show membership in its demo box without adding to the consulted set.

## Timing rules

### Register before `init` priority 1

At `init` priority 1 the plugin registers every known segment key (CPT
and code) as a group with the cache adapter. At `init` priority 10 the
adapter parses the incoming cookie. `Vary_Cache` uses the same priorities.
A segment registered after `init` 1 is still registered with the adapter on
its first assignment, but the recommended place is `content_gates_loaded`.

### Assign after `init` priority 10 and before `send_headers`

Writes before the cookie is parsed can be overwritten: the local adapter's
parser writes every group found in the cookie over the current state, so a
removal made at `init` 5 is undone at `init` 10. Write at `init` priority
11 or later. The test provider uses priority 20.

The last moment for a write is the plugin's own `send_headers` callback,
hooked at priority `PHP_INT_MAX - 1`. After it runs, every write returns
`content_gates_too_late`. `Vary_Cache` writes the cookie one step later,
at priority `PHP_INT_MAX`, and the local adapter writes at the same
priority. So `content_gates_too_late` appears at the moment `Vary_Cache`
would start refusing writes.

### A cache HIT never runs PHP

On WPVIP an edge HIT is served without origin. A provider that assigns
membership during an ordinary cacheable GET will see nothing happen on a
HIT. The reliable patterns are:

1. **Post-redirect-get** in PHP. Handle a POST on `init`, assign, then
   redirect once the cookie is written. The redirect lands on a GET that
   carries the new cookie.
2. **The REST endpoint**, for browser scripts and MCP-style callers.

### Post-redirect-get example

This follows the shape of the test provider
(`tests/e2e/fixtures/mu-plugins/content-gates-test-provider.php`) with a
nonce added. The test provider has no nonce on purpose, so `curl` can
drive it; a real provider must verify one.

```php
add_action( 'init', function () {
	if ( 'POST' !== ( $_SERVER['REQUEST_METHOD'] ?? '' ) || ! isset( $_POST['my_provider_join'] ) ) {
		return;
	}

	check_admin_referer( 'my_provider_join', '_my_provider_nonce' );

	// Your entitlement check goes here. The plugin does not do it for you.
	if ( ! my_provider_reader_is_entitled() ) {
		return;
	}

	$result = assign_reader_to_segment( 'subscriber' );
	$code   = is_wp_error( $result ) ? $result->get_error_code() : 'ok';

	$redirect = function () use ( $code ) {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;
		wp_safe_redirect( add_query_arg( 'joined', rawurlencode( $code ) ) );
		exit;
	};

	// Vary_Cache (and the local adapter) fire this once the cookie is written.
	add_action( 'vip_vary_cache_did_send_headers', $redirect );
	// Fallback for the case where nothing changed and no cookie was sent.
	add_action( 'send_headers', $redirect, PHP_INT_MAX );
}, 20 );
```

Both hooks are added during `init`, after the plugin added its own
`send_headers` callbacks, so at equal priority they run after the cookie
write.

### The REST path

`send_headers` never fires for a REST request. The membership endpoint
calls the adapter's `flush()` explicitly after a successful write, so the
`Set-Cookie` header is on the POST response itself. After that flush the
request is too late for any further write: one response carries one
`Set-Cookie`.

## Cookie mechanism

| Property | Value |
| --- | --- |
| Name | `vip-go-seg` |
| Format | `vc-v1__<group>_--_<value>---__<group>_--_<value>` |
| Order | Groups sorted by name (`ksort`, string sort). |
| Removal | Empty values are dropped from the serialization. Removing a reader from a segment means writing `''`. |
| Allowed characters | `A-Z a-z 0-9 _ -` in both names and values. A name or value may not contain the delimiters `_--_` or `---__`. |
| Lifetime | `MONTH_IN_SECONDS` by default. |
| Path, domain | `COOKIEPATH`, `COOKIE_DOMAIN`. |

**Who writes it.** On WPVIP, `Vary_Cache` writes the cookie at
`send_headers` priority `PHP_INT_MAX`; this plugin only forwards
`register_group()` and `set_group_for_user()` calls to it. On a site
without `Vary_Cache` (local wp-env, CI), the plugin's `Local_Cookie_Adapter`
writes the same cookie with `setrawcookie()` at the same priority, guarded
by `headers_sent()`. The adapter is chosen at boot by
`class_exists( '\Automattic\VIP\Cache\Vary_Cache' )`.

**How it reaches origin.** The VIP CDN turns the cookie into the
`X-VIP-Go-Segmentation` request header. The local adapter reads that
header first (`HTTP_X_VIP_GO_SEGMENTATION`) and falls back to the cookie,
so the edge simulator and the PHPUnit tests can present a reader the way
the edge does.

**Local simulation versus `Vary_Cache`.** The local adapter reproduces
the format byte for byte (PHPUnit:
`Test_Local_Cookie_Adapter::test_serialize_matches_vary_cache_format`).
Known differences, listed in the adapter's class docblock:

- It never sends `Vary: X-VIP-Go-Segmentation`.
- It sends `X-Content-Gates-Simulation: local` on every response it
  handles.
- When every group is removed it expires the cookie. `Vary_Cache` leaves
  the stale cookie in place.
- A late `register_group()` keeps a value already parsed from the cookie.
  `Vary_Cache` resets it to empty.
- It does not implement encrypted mode (`vip-go-auth`).

**Size.** Under `WP_DEBUG`, the plugin raises an `E_USER_WARNING` when
the serialized cookie exceeds 3072 bytes (`Membership::COOKIE_WARN_BYTES`).
Roughly 20 bytes per boolean segment means about 150 segments reach that
line.

## The REST endpoint

Route: `content-gates/v1/membership`, so `/wp-json/content-gates/v1/membership`
with pretty permalinks or `/?rest_route=/content-gates/v1/membership`
without.

Every response from this route, errors included, carries
`Cache-Control: no-store, no-cache, must-revalidate, max-age=0` and
`Vary: Cookie`. The headers are attached on `rest_request_after_callbacks`,
so a 403 from the permission check or a 400 from argument validation is
as uncacheable as a success. Neither method records a membership read, so
responses never carry `X-Content-Gates-Vary`.

### `GET`

Public. Returns the reader's current membership and the segment list.
Membership is the raw cookie state, including values for inactive
segments.

```json
{
  "contract": "1",
  "membership": { "newsletter": "1" },
  "segments": [
    { "id": 12, "key": "subscribers", "name": "Subscribers", "active": true },
    { "id": null, "key": "crawler", "name": "crawler", "active": true },
    { "id": null, "key": "newsletter", "name": "newsletter", "active": true }
  ]
}
```

### `POST`

Authorized by the `content_gates_rest_membership_permission` filter.
Changes membership and returns the cookie on its own response, so the next
page view already carries it.

| Param | Type | Description |
| --- | --- | --- |
| `segment` | `int\|string` | Required. Post ID, or key. Numeric strings are converted to integers before the permission check. |
| `action` | `"assign"\|"remove"` | Default `assign`. |
| `value` | `string` | Default `"1"`. Only `A-Z a-z 0-9 _ -`. Ignored on `remove`. |

Success is HTTP 200 with the `GET` shape plus `"changed": true` and,
under the local cookie simulation only, `"cookie": { "name", "value" }`
echoing the `Set-Cookie` header. Locally the response also carries
`X-Content-Gates-Simulation: local`.

Failures:

| HTTP | `code` | When |
| --- | --- | --- |
| 403 | `content_gates_forbidden` | No provider returned `true` from the permission filter. |
| 403 or provider's own | provider's own code | The provider returned a `WP_Error`; it is sent as-is. |
| 400 | core's schema error codes | Missing `segment`, or `action` outside the enum. |
| 400 | `content_gates_invalid_segment`, `content_gates_inactive_segment`, `content_gates_invalid_value`, `content_gates_too_late`, `content_gates_not_registered`, `content_gates_cache_error` | The membership layer refused the write. Same meanings as for the PHP functions. |

### Example for an MCP-style caller

The plugin does not authenticate the caller. The provider's permission
filter decides, and it can use anything on the request: a WordPress user
established by an application password, a bearer token the provider
verifies, or a signed parameter. The example below assumes the provider
grants requests that carry a valid application password.

```bash
# 1. Assign. The response carries Set-Cookie: vip-go-seg=...
curl -i -X POST 'https://example.test/wp-json/content-gates/v1/membership' \
  -u 'agent:xxxx xxxx xxxx xxxx xxxx xxxx' \
  -H 'Content-Type: application/json' \
  -d '{"segment":"subscriber","action":"assign"}' \
  -c cookies.txt

# 2. Read the entitled view on a later request, as an anonymous reader
#    carrying only the segmentation cookie.
curl -s 'https://example.test/gated-article/' -b cookies.txt | grep -c 'wp-block-group'
```

A browser script does the same with `fetch()` and `credentials: 'include'`;
a logged-in cookie session also needs core's `X-WP-Nonce` header.

Note that on WPVIP, logged-in requests bypass the page cache. The second
request above must be anonymous (only the `vip-go-seg` cookie) to prove
edge behavior.

## The crawler segment

The plugin registers one code segment, `crawler`, in the segment
registry's constructor. It is reserved for the edge: a VIP Edge Worker is
expected to classify search and AI crawlers and write `crawler_--_1` into
the same cookie. That worker is not part of this repository and the plugin
performs no user-agent detection. Locally, a tester presents a crawler by
sending the cookie or the `X-VIP-Go-Segmentation` header with
`crawler_--_1`.

When a reader is in `crawler` and a gated Group denies them, the Group
renders in full wrapped in `<div class="content-gates-paywall">`, and
`wp_head` on a singular post with a gated Group emits JSON-LD with
`isAccessibleForFree: false` and a `hasPart` pointing at
`.content-gates-paywall`. The `crawler` read is recorded only after a
denial, so pages without a denied gate do not vary on it.

The `crawler` segment has no post ID, so it cannot be assigned to a Group
block. Providers may assign it through the contract like any code
segment; whether they should is a product decision.

## Deprecation policy

Rules from `spec/content-gating-api.md`, restated as the contract:

- The contract version bumps when the cookie format, the meaning of
  `none` in the variant key, or the response signal
  (`X-Content-Gates-Vary` and its format) changes.
- The five function names are the contract. They can change before the
  first release, not after. A rename after release means a contract bump
  and a deprecation period where both names work.
- Proposed, for stakeholder confirmation: adding an error code, adding
  an optional parameter, or adding a REST response field does not bump
  the contract. Removing or renaming any of them, or changing the meaning
  of an existing error code or HTTP status, does.
- The cookie format version (`vc-v1`) belongs to `Vary_Cache`. A change
  there forces a contract bump here.

## What the contract does not do

- **No entitlement decisions.** The plugin never decides who is
  entitled. Without a provider, nobody is in any segment and every
  `POST /membership` is refused.
- **No billing, subscriptions, or identity.** Those belong to the
  provider and its commerce stack.
- **No tamper-proof membership.** The cookie is forgeable. On WPVIP the
  accepted risk from `spec/segment-admin.md` applies: `vip-go-auth`
  encryption has no integrity check and plaintext `vip-go-seg` may be
  accepted alongside it. Locally there is no encryption at all. A reader
  who writes `subscriber_--_1` (or `crawler_--_1`) into the cookie
  receives that variant. Do not describe this as a secure entitlement
  system.
- **No reads outside the contract.** Code that checks membership another
  way (for example `Vary_Cache::is_user_in_group()` directly) is
  invisible to the consulted set, and the edge may then serve one reader's
  page to another.
- **No membership changes on a cache HIT.** A HIT never reaches origin.
