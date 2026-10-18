# Leak-surface matrix

One row per delivery surface. "Mechanism" is the code path that keeps
gated bytes out of the response for a non-entitled reader. "Evidence" is
the test that asserts it, or "not yet tested". "Result" is the status on
2026-10-18 against the local wp-env site. Nothing has run on WPVIP.

Evidence comes in two layers:

- **PHPUnit** (`tests/unit/`): function-level, against the plugin's own
  local cookie adapter. Readers are simulated through the
  `X-VIP-Go-Segmentation` header. Test names below are
  `Class::method`.
- **Playwright** (`tests/e2e/`): real HTTP responses from the wp-env tests
  site, with the test-only provider installed. Specs below are cited by
  file and test title. The docs writer did not run them; a pass is
  claimed only by the CI run or the orchestrator's report, not here.
  `cache-correctness.spec.ts` runs through the edge simulator, which is a
  test double, so it is correctness evidence for the keying logic, not
  platform evidence.

| Surface | Mechanism | Evidence | Result |
| --- | --- | --- | --- |
| Page render (`the_content`) | `Block_Enforcer` on `pre_render_block` returns `''` for a withheld Group; core skips the wrapper, inner blocks, and dynamic callbacks. | PHPUnit `Test_Block_Enforcer::test_denied_reader_gets_nothing`, `test_public_blocks_render_around_denied_group`, `test_dynamic_child_callback_does_not_run_when_denied`, `test_allowed_outer_denied_inner_withholds_only_inner`, `test_denied_outer_consults_only_outer`, `test_inactive_segment_withholds`, `test_invalid_references_withhold_with_incorrect_usage`. Playwright `leak-surfaces.spec.ts`: "anonymous page HTML has the public text, zero gated bytes, and the variance headers", "member (edge-written cookie) receives the gated bytes and a member variant key", "nested gates: each reader sees exactly their Groups and reports their own consulted set", "a member of a deactivated segment is withheld the Group". | Zero gated bytes asserted at both layers, with `X-Content-Gates-Vary` and `X-Content-Gates-Key` asserted on the HTTP response. |
| Page render, warm cache | Edge keys each stored copy on its own consulted set. | Playwright `cache-correctness.spec.ts`: "a non-member is never served the member copy, cold or warm", "worked example: readers who differ only in unconsulted segments share a copy", "nested gates produce a different consulted set per reader and never cross", "an ungated URL has one copy for every reader", "the anonymous copy is storable: MISS then HIT, no Set-Cookie". | Asserted against the edge simulator only. Not platform evidence. |
| REST `content.rendered` (`GET /wp/v2/posts/<id>`, `?slug=`, `?search=`) | Same enforcer: the REST controller runs `the_content`. | PHPUnit `Test_Excerpt_Guard::test_rest_response_omits_denied_group`. Playwright `leak-surfaces.spec.ts`: "anonymous REST content.rendered and excerpt.rendered have zero gated bytes" (single post, `context=view`, by slug, and a search for the secret itself; also asserts `X-Content-Gates-Vary` on each). | Zero gated bytes asserted at both layers. |
| REST `excerpt.rendered` | `Excerpt_Guard` on `get_the_excerpt` 9 trims a stripped clone. | Same two tests as the row above. | Zero gated bytes asserted at both layers. |
| REST `content.raw` (`context=edit`) | Core's permission model: `edit` context requires `edit_post` on the post, so an anonymous reader cannot request it. The plugin does not strip raw content; an editor with `edit_post` sees the saved blocks, which editing requires. Core was not reviewed in this run. | Not yet tested. | Not a leak surface for non-entitled anonymous readers if core's permission model holds; relies on core, not on this plugin. |
| RSS/Atom full content (`get_the_content_feed()`) | `the_content` runs `do_blocks()`, so the enforcer applies. Feeds pass `template_redirect`, so the finalizer buffers them. | PHPUnit `Test_Excerpt_Guard::test_feed_content_omits_denied_group` (`rss2`). Playwright `leak-surfaces.spec.ts`: "anonymous RSS feed has the public text and zero gated bytes" (`/feed/`, `/feed/atom/`, `/feed/rdf/`, and the post comment feed; asserts `X-Content-Gates-Vary` on `/feed/` and `/feed/atom/`). | Zero gated bytes asserted at both layers; signal header asserted on the two main feeds. |
| RSS summary (`the_excerpt_rss()`) | `Excerpt_Guard`. | PHPUnit `Test_Excerpt_Guard::test_feed_excerpt_omits_denied_group`. The Playwright feed test covers whichever mode the site is in (default is full content). | Zero gated bytes at the function level. Summary mode over HTTP: not separately asserted. |
| Search and archive teasers (`the_excerpt`, home, search) | `Excerpt_Guard`; manual excerpts are the editor's responsibility. | PHPUnit `Test_Excerpt_Guard::test_excerpt_omits_denied_group`, `test_excerpt_includes_group_for_member`, `test_manual_excerpt_is_untouched`, `test_core_excerpt_leaks_without_guard` (documents the core leak the guard closes). Playwright `leak-surfaces.spec.ts`: "listing teasers (home, search) have the public text and zero gated bytes" (home, a search for the public text, and a search for the secret; asserts the home page varies on the segment). | Zero gated bytes asserted at both layers. Note: a search for gated text still matches the post, so search is an oracle for whether a term exists in gated content. Not a byte leak; record as a limitation. |
| oEmbed (`/wp-json/oembed/1.0/embed`) | Nothing in this plugin targets the oEmbed response. Core's response carries embed markup and the title, not the content; core was not reviewed in this run. | Playwright `leak-surfaces.spec.ts`: "oEmbed JSON and the embed page have zero gated bytes" (asserts the raw JSON and its `html` field). | Zero gated bytes asserted over HTTP. No plugin code involved. |
| Embed template (`<permalink>embed/`) | The embed template reads `get_the_excerpt()` (per the `Excerpt_Guard` docblock), so the guard applies. | Same Playwright test as above (asserts public text present, secret absent, `X-Content-Gates-Vary` present). | Zero gated bytes asserted over HTTP. |
| AMP | Not applicable: no AMP plugin is installed or integrated. An AMP plugin that renders through `the_content` would get the enforcer; one that reads raw content would not. | Not applicable. | Not applicable. |
| Head metadata (`wp_head`: Open Graph, meta description, JSON-LD from SEO plugins) | Not covered. The plugin's own `wp_head` output is only the crawler JSON-LD, which contains no content. SEO plugins that derive descriptions from `post_content` may leak. The Playwright page tests assert the whole HTML body, which includes `<head>`, but the tests site has no SEO plugin, so this proves nothing about one. | Not yet tested with an SEO plugin. | Not covered. Known limitation. |
| Sitemaps (`/wp-sitemap.xml`) | Nothing in this plugin targets sitemaps. By core's documented behavior they list URLs and last-modified dates only; core was not reviewed in this run. | Not yet tested. | Not covered. Expected safe; not proven. |
| Listing teaser: `core/post-excerpt` | Reads `get_the_excerpt()`, so `Excerpt_Guard` applies. | PHPUnit `Test_Excerpt_Guard` (through `get_the_excerpt`); the Playwright home-page test exercises whatever the active theme (`twentytwentyfive`) renders in its listing. | Covered by the guard. |
| Listing teaser: `core/latest-posts` with post content | Not covered. The block has its own content path; nothing in `inc/` handles it. | Not yet tested. | Not covered. Known limitation. |
| Crawler variant | Reader in `crawler` receives gated Groups in full, wrapped in `.content-gates-paywall`, plus JSON-LD. This is by design and relies on the edge classifying crawlers. | PHPUnit `Test_Block_Enforcer::test_crawler_gets_wrapped_group`, `test_invalid_reference_withholds_from_crawler`, `Test_Crawler_Markup::test_crawler_on_gated_post_gets_json_ld`, `test_non_crawler_gets_no_json_ld`. Playwright `leak-surfaces.spec.ts`: "crawler variant renders the Group inside the paywall wrapper with JSON-LD" (asserts wrapper, `isAccessibleForFree: false`, `hasPart.cssSelector`, and no JSON-LD on an ungated post). | Full content to crawlers by design. Accepted risks: UA spoofing, cookie forgery. |
| Provider PRG assignment | Test provider handles the POST on `init` 20 and redirects from `vip_vary_cache_did_send_headers`. | Playwright `leak-surfaces.spec.ts`: "POST-redirect-GET assignment sets the cookie and unlocks the Group on the next request". | Asserted over HTTP against the local adapter. |
| REST membership endpoint | Returns membership and the segment list, never content. | PHPUnit `Test_Membership_Rest` (19 tests). Playwright `membership-api.spec.ts`: "GET returns contract 1, empty membership, and the segment list", "POST assign sets the cookie, a later GET and page view see the membership, remove clears it", "unknown and inactive segments are refused with 400 and the documented codes". | No content on this surface. Assignment taking effect on a later request is asserted over HTTP. |
| Object cache, transients, block render cache | Code review: the plugin stores no rendered HTML anywhere. `Segment_Registry` caches `Segment` value objects per request in memory only. `Block_Stripper` output is returned, not stored. | Code review only. | No gated HTML cached by this plugin. Third-party fragment caches are a known limitation. |

## Required-zero surfaces

The spec requires zero restricted bytes on page render, REST, and feeds.
All three have PHPUnit evidence and Playwright specs that assert the HTTP
response body. Evidence on a VIP application is the next step and is not
claimed here.
