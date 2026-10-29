# Providers

An entitlement provider decides who is entitled. This plugin never does.
The provider's job is small: when a reader becomes entitled, assign them
to the segment once; when the entitlement lapses, remove them. Ordinary
page views never write membership.

## The contract

- Call `assign_reader_to_segment()` and `remove_reader_from_segment()`
  after `init` priority 10 and before `send_headers`. `init` priority 20 is
  a good default.
- Write on a request the edge forwards with the full cookie: a POST, an
  authenticated or logged-in request, or a URL the edge has no record for.
  On a request the Edge Worker narrowed, both calls return
  `content_gates_narrowed_request` and change nothing. In practice: assign
  from your login, checkout, or redemption flow; remove from logout or
  cancellation; and act on logged-in requests if you like, since those are
  never cached. Never evaluate entitlement on anonymous page views.
- The cookie lifetime (`content_gates_cookie_lifetime`, one day by default)
  is the only bound on a lapsed entitlement for a reader who never makes
  another full-cookie request, and it is also how often a valid reader has
  to be re-set. Choose it for your product. When the cookie expires or is
  cleared, nothing restores it on a page view: logged-in readers get it
  back on their next request if you act there; anonymous entitled readers
  need your endpoint and a small client nudge on gated pages.
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
- Removing a reader's last segment expires the cookie. `Vary_Cache` does
  it since vip-go-mu-plugins #7454. An older `Vary_Cache` skips the write
  when the serialized value is empty, so with one the plugin expires the
  cookie itself on `vip_vary_cache_did_send_headers`. A provider needs
  nothing extra.
- Membership on a cache HIT cannot change. Nothing a provider does at origin
  reaches a reader whose request never left the edge, until their cookie
  changes or their copy misses.

## A minimal provider

`examples/segment-provider/` is the smallest complete provider: a form POST
on `init` 20, the redirect once the cookie is written, and a declaration on
`wp` so the page can read what it shows. It grants anyone who asks, so it is
never deployed. The local fixture `tests/fixtures/mu-plugins/content-gates-dev.php`
shows the REST path; its demo box is a diagnostic, not provider code.

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
