# Leak-surface matrix

Required result: zero restricted bytes in page, REST, and feed responses for
non-entitled requests. Other surfaces may ship with an explicit known
limitation. Each row names the test that proves the result. "Origin" rows
were observed on wp-env; "edge" rows on the simulator only. Nothing has been
observed on a VIP application yet.

| Surface | Result | Evidence | Notes |
| --- | --- | --- | --- |
| Singular page render | No gated bytes, no wrapper markup | `Test_Block_Gate`, `gating.spec.ts` "anonymous page render" | `pre_render_block` returns `''`; nested Groups and dynamic children covered |
| Nested Groups | Inner gate enforced inside allowed outer; inner key declared even when outer denies | `Test_Block_Gate::test_nested_groups`, `gating.spec.ts` "nested Groups" | |
| Dynamic child blocks | Render callback does not run when denied | `Test_Block_Gate::test_dynamic_child_callback_does_not_run_when_denied` | |
| Author-hidden Groups | Stay hidden for entitled readers | `Test_Block_Gate::test_author_hidden_group_stays_hidden_for_members` | Core visibility is applied after rendering; a denied Group never reaches it |
| REST `content.rendered` | No gated bytes; declared header; `Vary` sent | `Test_REST`, `gating.spec.ts` "feeds and REST" | `rest_pre_echo_response` covers `_embed` |
| REST `excerpt.rendered` | No gated bytes | `Test_REST::test_anonymous_post_response_withholds_and_declares` | Through the excerpt guard |
| REST `content.raw` | Not reachable anonymously | Core: `context=edit` requires `edit_post` | Entitled editors see raw content by design |
| REST `POST` with unknown key | Rejected with 400 | `Test_Content_Meta::test_rest_save_rejects_unknown_key`, `editor.spec.ts` | |
| RSS/Atom full content | No gated bytes | `Test_Excerpt_Guard::test_feed_paths_omit_gated_content`, `gating.spec.ts` | `the_content` runs `do_blocks` |
| RSS/Atom summaries | No gated bytes | same | Through the excerpt guard |
| Auto-excerpts (archives, search, embeds) | No gated bytes; fully gated post yields `''` | `Test_Excerpt_Guard` | Core's `excerpt_remove_blocks()` unwraps Groups; the guard replaces its output |
| Manual excerpts | Untouched | `Test_Excerpt_Guard::test_manual_excerpt_is_untouched` | Public editorial text, the author's responsibility |
| `/embed/` template | No gated bytes | `gating.spec.ts` "feeds and REST" | Singular path plus the excerpt guard |
| oEmbed REST (`/oembed/1.0/embed`) | Known limitation, low | not tested | Returns the title, author, and an iframe to `/embed/`; no content |
| Search | Known limitation | `gating.spec.ts` asserts no bytes in results | A search for a gated phrase still matches the post (existence, not bytes). Not addressed |
| Head metadata (`og:description`, meta description) | Covered when derived from `get_the_excerpt()` | `Test_Excerpt_Guard` | SEO plugins that read `post_content` directly are outside the plugin |
| Sitemaps | No content | core sitemaps list URLs only | |
| Listing teasers (`the_content()` on archives) | No gated bytes, for everyone | by design: archives declare nothing, reads fail closed | A theme that shows full content on archives must declare |
| Template parts, shortcodes | Reads fail closed unless declared | `Test_Membership::test_undeclared_read_fails_closed` | |
| Object cache, transients, fragment caches | Not used for rendered gated HTML | by design | The plugin caches only the segment list |
| Cross-variant cache leak | Entitled copy never served to a non-entitled reader | `edge-cache.spec.ts`, `edge/tests` | Simulator only; coverage check refuses too-narrow keys |
| Forged crawler cookie | Gains nothing | `edge-cache.spec.ts` "a search crawler ...", `Test_Structured_Data::test_crawler_value_is_not_read_on_archives` | The worker strips crawler groups from incoming cookies; origin reads them only on gated singular posts |
| Forged `vip-go-seg` membership | **Accepted risk** | spec/segment-admin.md | Plaintext cookie; a reader who writes `subscriber_--_1` is a subscriber. Recorded, not mitigated |
| Spoofed crawler user agent | **Accepted risk** | spec/page-cache.md | Receives the crawler variant |
| View As switcher | Never cached | `Test_View_As::test_switched_response_is_no_store`, `gating.spec.ts` | `no-store` on every switched response; logged-in requests bypass the VIP cache anyway |
| Demo provider box (`?content_gates_demo=1`) | Never cached | fixture sends `no-store` | Local only |
| Previews | Obey membership; unsaved assignments fail closed | by design | Post meta is computed on save, not on autosave |
| Admin-ajax | Not handled | known limitation | No admin-ajax path renders gated content in this plugin |
