# Example provider: pick your own segments

A toy membership provider for Content Gates. One page, `/segments/`, lists
every active reader segment by key, with a checkbox, in the same order as the
settings page. Any reader ticks the segments
they want to be in and saves. No login, no entitlement check: the point is
to show the integration, not to protect anything.

**Never deploy this plugin.** Anyone can grant themselves any membership.
It is loaded only by the repository's wp-env configs.

## What it shows

A provider owns the answer to "who is in a segment". Content Gates owns
"what may this reader see". The plugin's public API joins the two:

| Step | Hook | Call |
| --- | --- | --- |
| Read the submitted form and write membership | `init`, priority 20 | `assign_reader_to_segment()`, `remove_reader_from_segment()` |
| Redirect once the cookie is written | `vip_vary_cache_did_send_headers` | `wp_safe_redirect()` |
| Make the page readable | `wp`, priority 10 | `declare_segment()` per listed segment |
| Show the reader's membership | `template_redirect` | `is_reader_in_segment()`, with `nocache_headers()` |

Priority 20 on `init` is inside the plugin's write window: after the
platform parsed the cookie at priority 10, before `send_headers` writes it.
The redirect is the post-redirect-get pattern from `spec/decisions.md`: the
reader lands on a GET that already carries the cookie.

Meter segments are shown but cannot be chosen; only the Edge Worker writes
meter values. Crawler segments are not shown; only the Edge Worker
classifies crawlers, and it strips crawler values a browser sends.

## Try it

```bash
npm run setup                 # dev site on :8888 with demo segments
open http://localhost:8888/segments/
```

Or with curl:

```bash
nonce=$(curl -s -c jar http://localhost:8888/segments/ | grep -o 'name="_wpnonce" value="[^"]*"' | cut -d'"' -f4)
curl -s -b jar -c jar -D - -o /dev/null \
  -d "_wpnonce=$nonce&content_gates_provider_action=save&content_gates_segments[]=subscriber" \
  http://localhost:8888/segments/            # 303, Set-Cookie: vip-go-seg=...subscriber...
curl -s -b jar http://localhost:8888/gated-article/ | grep -c SUBSCRIBER-ONLY   # 1
```

The nonce is CSRF protection for the form, not authentication. Anonymous
readers share one nonce per tick.

The page needs no rewrite rule: the plugin matches its path in the
`request` filter, so it works in a fresh container with no flush.
`/?content_gates_provider=1` reaches the same page.
