# Provider contract 1

This is an independent **local prototype**, not production-ready cache integration. Definition creation and login never grant membership. Providers own eligibility. The platform adapter uses the genuine `Automattic\VIP\Cache\Vary_Cache`; without it, grants return `content_gates_unavailable` and gated output is withheld. There is no production fake or alternative cookie.

## PHP

```php
add_action( 'content_gates_provider_ready', static function () use ( $segment_id, $provider ) {
    // Your provider authenticates the reader and decides eligibility.
    $result = content_gates_assign_reader( $segment_id, $provider->is_entitled(), 1 );
    // Handle WP_Error; never report a successful grant/revoke on error.
} );

if ( content_gates_is_reader_in_segment( $segment_id ) ) {
    // Render entitled content. The read records a dependency even when false.
}
```

`content_gates_assign_reader(int $segment_id, bool $member = true, int $version = 1): true|WP_Error` changes the current reader, not a supplied WordPress user. `false` means removal, never a serialized `none` cookie value. `content_gates_is_reader_in_segment(int $segment_id): bool` is the only rendering read API. IDs are positive, site-local segment post IDs; wire identity is `cg_<blog_id>_<id>`. Names can change without changing this identity.

Registry loads at `init:5`, VIP parses at `init:10`, provider-ready fires at `init:20`. A new definition cannot be granted until the next request. Mutations close at `send_headers:0`, before VIP sends at `PHP_INT_MAX`; also reject committed PHP headers, post-finalization mutations and changes after a recorded read. Template membership reads do not create definitions.

Errors: `content_gates_version`, `content_gates_not_ready`, `content_gates_too_late`, `content_gates_invalid_segment`, `content_gates_inactive_segment`, `content_gates_unavailable`, `content_gates_not_registered`, `content_gates_already_read`, `content_gates_transport_error`, and `content_gates_final_revocation_unsupported`.

**Known upstream blocker:** the pinned VIP writer returns without deleting the browser cookie when its last nonempty group is removed. The adapter refuses that operation with `content_gates_final_revocation_unsupported`; it does not claim success, write another cookie, or silently leave a stale browser grant. Removal while another nonempty group remains works. Complete revocation requires an upstream cookie-writer fix before release.

Inactive references fail closed, new grants fail, and reactivation preserves identity. This is an explicit prototype policy, not a settled product decision. Cross-blog switching resets request state and fails closed; returning to the original blog does not recover membership automatically in this slice.

## HTTP

`POST /wp-json/content-gates/v1/membership`

```json
{ "segment_id": 123, "member": true, "version": 1 }
```

The endpoint requires `manage_options` and normal WordPress REST authentication (cookie authentication also requires a REST nonce). It is an administrator/provider integration exercise, **not a public reader self-grant endpoint**. Success is `{ "version":1,"segment_id":123,"member":true }` plus VIP's real Set-Cookie. Contract errors return 409; malformed REST arguments return 400, unauthenticated requests 401. For real third-party reader refresh, authenticate and evaluate eligibility in a provider-owned endpoint that calls the PHP contract; do not give readers administrator credentials.

This REST route explicitly calls the genuine VIP sender because REST does not execute the normal `send_headers` action. Retain the returned cookie and make a new GET; there is no fill endpoint or client reveal. The integration suite exercises an HTTP client retaining the actual cookie and then retrieving page, REST and feeds without WordPress login. This is not a real MCP integration or deployed entitlement provider.

## Reports and invalidation

* `content_gates_consulted_segments(array $groups): array`: may add registered dependencies; cannot remove observed reads. Invalid output is rejected and diagnosed. IDs are stable wire names, not labels.
* `content_gates_finalize_variance(array $groups, array $projection)`: runs once after rendering. Values are exactly `1` or `none`. Late reads deny access and record a lifecycle error.
* `content_gates_invalidation_requested(int $post_id, string $scope)`: signals `global` for definition changes, `resource` for post saves. This is **not a purge**. External provider-rule/configuration changes must request global invalidation in their integration. No automatic reverse dependency catalog or live purge is implemented.

The plural hook prefix deliberately replaces the draft's singular `content_gate_*` names before release to follow repository prefix standards. Contract version is independent of plugin version and VIP's `vc-v1`. There is no compatibility promise for unreleased names; once released, breaking changes require a new contract version with a documented migration/deprecation window.

Every HTTP response is `Cache-Control: private, no-store, max-age=0`. With `WP_DEBUG`, `X-Content-Gate-Projection` reports consulted names/values after rendering (oversized reports are omitted), and `X-Content-Gate-Cache` says `unavailable-no-store`. These are diagnostic headers, **not supported edge cache instructions**. Existing Vary/Set-Cookie headers are preserved. Full-response buffering is a prototype cost; flushes can make the report incomplete, so no-store is installed before output and late reads fail closed.

Never read VIP directly outside the adapter or cache rendered fragments: both hide dependencies. Third-party direct reads cannot be intercepted reliably without upstream instrumentation. The accepted VIP cookie-forgery and user-agent-spoofing risks remain; plaintext/encrypted transport is not claimed to be tamper-proof.
