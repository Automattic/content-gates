# Providers

An entitlement provider decides who is entitled. This plugin never does.
The provider's job is small: on every origin request, assign the reader to
the segments they qualify for and remove them from the ones they do not,
inside the write window.

## The contract

- Call `assign_reader_to_segment()` and `remove_reader_from_segment()`
  after `init` priority 10 and before `send_headers`. `init` priority 20 is
  a good default.
- Assign on every origin request, for logged-in and anonymous readers
  alike. Login grants nothing by itself. The cookie lifetime (one day by
  default, `content_gates_cookie_lifetime`) bounds how long a lapsed reader
  keeps the entitled view, so no refresh endpoint or client script is
  needed.
- A provider that acts on a form POST redirects on WPVIP's
  `vip_vary_cache_did_send_headers( $sent_vary, $sent_cookie )` action,
  which fires after `Vary_Cache` wrote the cookie. The following GET carries
  it.
- A provider route on the REST API needs nothing extra: the plugin sends
  `Vary` and the cookie before the JSON is echoed. Assign before the
  response is served.
- The plugin ships no membership HTTP route. Only the provider can
  authenticate a reader who has no WordPress login, so the provider writes
  its own authenticated endpoint and calls the PHP functions.
- Removal of a reader's last segment leaves the stale cookie in place until
  it expires: `Vary_Cache` skips the cookie write when the serialized value
  is empty. This is an upstream gap; the one-day lifetime bounds it.
- Membership on a cache HIT cannot change. Nothing a provider does at origin
  reaches a reader whose request never left the edge, until their cookie
  changes or their copy misses.

## A minimal provider

The local fixture `tests/fixtures/mu-plugins/content-gates-dev.php` is a
complete example of both paths (form POST with redirect, and a REST route).
It grants anyone who asks, so it loads only when `WP_ENVIRONMENT_TYPE` is
`local`.

```php
add_action( 'init', function (): void {
	if ( my_provider_is_subscriber() ) {
		assign_reader_to_segment( 'subscriber' );
	} else {
		remove_reader_from_segment( 'subscriber' );
	}
}, 20 );
```

Providers call external systems over HTTP. The plugin does not cache those
calls; the provider does.

## The Newspack bridge

`Providers\Newspack_Bridge` is the only first-party adapter. It maps
Newspack access-rule evaluations to segments:

```php
add_filter( 'content_gates_newspack_rules', fn() => array(
	array( 'segment' => 'subscriber', 'rule' => 'subscription', 'value' => array( 123 ) ),
	array( 'segment' => 'donor', 'rule' => 'one_time_purchase', 'value' => array( 'products' => array( 45 ) ) ),
) );
```

On `init` 20 the bridge, for each mapping:

1. Checks `Newspack\Access_Rules::get_rule( $slug )`. Newspack's
   `evaluate_rule()` returns `true` for a rule that is not registered, which
   would open the gate for everyone, so a missing rule assigns nothing and
   fires `content_gates_newspack_rule_missing`.
2. Calls `evaluate_rule( $slug, $value, get_current_user_id() )` and assigns
   or removes the segment accordingly.

The bridge is inert when `Newspack\Access_Rules` is not loaded. It is tested
against a fake of that class (`tests/unit/fake-newspack-access-rules.php`)
that mirrors the three methods and the unregistered-rule behavior; it has
not run against a live Newspack install. The reverse direction (a Newspack
rule whose value is a segment, so Newspack gates can key on membership) is
not built: such a rule would read a segment during Newspack's evaluation,
which needs the segment declared per gate, and the gate configuration API
to do that was not verified. Newspack's client-side metering reveal and its
`Content_Gate` restriction are still to be bridged; see
[known-limitations.md](known-limitations.md).
