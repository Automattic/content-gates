# Content Gates prototype comparison

October 20, 2026. A comparison of four prototype branches of [`Automattic/content-gates`](https://github.com/Automattic/content-gates/branches/active), written so it can be re-run on new prototype branches later. The method and checklist are in "How to re-run" at the end. Detailed per-pair reviews are in `prototype-review.md` (the two `agent/` branches) and `prototype-review-glitch.md` (the two `glitch/` branches).

All findings come from reading code. Nothing was run, and nothing has run on a VIP application.

## Suggested action

**Build on `proto-7c1a`.** It has the most complete feature set, by far the most tests, and the most honest docs. Before adding features, fix three defects, mostly by porting code that already exists in the other branches:

1. **Fail closed when VIP's `Vary_Cache` isn't loaded.** On VIP, `Vary_Cache` is opt-in: the plugin has to load it itself. Without that, `proto-7c1a` falls back to a plaintext cookie adapter that can serve a subscriber's full page to anonymous readers. Fix: require the platform file when it exists, and use `prototype-1018`'s rule that the local adapter runs only in a `local` environment.
2. **Make revocation work.** Removing a reader's last segment doesn't clear their cookie, so lapsed subscribers keep access. Port `prototype-1018`'s cookie expiry.
3. **Port Astra's request rules.** Deny membership reads after the consulted set is frozen, refuse to assign a segment the same request already read, and let the consulted-set filter add segments but never remove them.

Then the smaller items: reject assignments that come too early (from `prototype-1018`), drop the `function_exists()` guards on public functions, and decide whether `GET /membership` should list segments to anonymous callers.

**In parallel, start the platform conversation.** No branch can deliver narrow cache keys on VIP, because `Vary_Cache` adds a broad `Vary` header to every response once any segment exists. That needs an upstream change and an edge decision, and it decides whether the product's core claim is achievable. After that, validate on a VIP test application.

## Branches compared

| Branch | Author, date | Commit | Plugin code | Tests | Verdict |
|---|---|---|---|---|---|
| [`agent/20261018-content-gates-proto-7c1a`](https://github.com/Automattic/content-gates/tree/agent/20261018-content-gates-proto-7c1a) (`proto-7c1a`) | Chris Zarate, October 18 | `1f2c8ec` | ~4,400 lines | 174 PHPUnit, 28 Playwright | Best base. Most complete, best documented, three fixable defects. |
| [`agent/content-gates-prototype-1018`](https://github.com/Automattic/content-gates/tree/agent/content-gates-prototype-1018) (`prototype-1018`) | Chris Zarate, October 18 | `29f431c` | ~2,400 lines | 47 PHPUnit, 7 Playwright | Smaller, but the most correct platform handling. Source of fixes. |
| [`glitch/astra-content-gates-prototype`](https://github.com/Automattic/content-gates/tree/glitch/astra-content-gates-prototype) (Astra) | Max Schmeling, October 17 | `9bb0267` | ~1,100 lines | 19 PHPUnit, 4 Playwright | Most safety-minded, but turns off page caching sitewide. Source of request rules. |
| [`glitch/gpt5.6-sol-content-gates-prototype`](https://github.com/Automattic/content-gates/tree/glitch/gpt5.6-sol-content-gates-prototype) (Sol) | Max Schmeling, October 17 | `53d2f64` | ~1,300 lines | 8 PHPUnit, 3 Playwright | Most literal reading of the spec, thin, two VIP-only bugs. Little to port. |

All four branch from `trunk` at `927595e`. `prototype-1018` also carries a ~5k-line `factory/` folder of agent tooling unrelated to the plugin.

## Scorecard

Yes, partial, or no against each criterion, with a short note. "VIP" means behavior on a real VIP application, inferred from platform source.

| Criterion | `proto-7c1a` | `prototype-1018` | Astra | Sol |
|---|---|---|---|---|
| **Segment admin** (post type, no delete, inactive status) | Yes. `cg_segment`, inactive is `draft` | Yes. `content_gate_seg`, custom `cg_inactive` | Yes. `content_gate_segment`, `draft` | Yes. `content_gate_segment`, `draft` |
| **Group block segment dropdown** | Yes | Yes | Yes | Yes |
| **Server-side withholding** (`pre_render_block`) | Yes, plus a second check on `render_block` | Yes | Yes | Yes |
| **Excerpts don't leak** | Yes, built from a copy with gated blocks removed | Yes | Yes, but replaces core's excerpt function sitewide | Partial. Overwrites hand-written excerpts |
| **Consulted set reported on page, feed and REST** | Yes, including `_embed` | Partial. Misses `_embed` reads | Debug header only | Yes |
| **Consulted set can't be under-reported** | Partial | Partial. Buffer callback can freeze early | Yes. Late reads denied, filter can only add | Partial. Same buffer weakness |
| **Assignment timing checks** | Late only | Late and early | Late and early | Late only |
| **REST assignment writes the cookie on VIP** | Yes | Yes | Yes | **No** |
| **Removing the last segment revokes access on VIP** | **No** | Yes | Refuses with an error | **No** |
| **Safe when `Vary_Cache` isn't loaded** | **No. Leaks** | Yes, fails closed | Yes, fails closed | Yes, fails closed by default |
| **Pages stay cacheable** | Yes | Yes | **No.** `no-store` on everything | Yes |
| **Crawler variant and paywall structured data** | Yes | No | No | No |
| **Ready for metering** (stepped values) | Yes | No | No | No |
| **Public API** | Spec names, ID or string key, global with `function_exists()` guards | Namespaced, IDs only | Prefixed, IDs only, versioned | Spec names, IDs only, global |
| **Docs say what isn't proven** | Yes, in detail (leak matrix, known limitations) | Brief | Yes (evidence ledger, Newspack notes) | Brief |

## Key findings

Most severe first.

1. **`proto-7c1a` can leak gated content on VIP.** When `Vary_Cache` isn't loaded (the default on VIP), it uses a plaintext cookie adapter that never sends the `Vary` header the edge relies on. The edge then stores one copy per URL for everyone. See `inc/class-plugin.php` and `inc/membership/class-local-cookie-adapter.php`.
2. **Lapsed subscribers keep access in `proto-7c1a` and Sol.** `Vary_Cache` never clears the cookie when the last membership is removed. Both report success anyway. Astra refuses with an error; `prototype-1018` works around it (`finalize_membership_removal()` in `inc/membership/class-vary-cache-adapter.php`).
3. **No branch narrows cache keys on VIP.** `Vary_Cache` sends `Vary: X-VIP-Go-Segmentation` whenever any segment is registered, and nothing on the platform reads the prototypes' own consulted-set header. So the whole site keys on each reader's full cookie. This doesn't leak, but it is the duplication the spec exists to fix.
4. **Sol's REST membership endpoint does nothing on VIP.** It returns 200, but REST requests finish before the hook where `Vary_Cache` writes cookies, and Sol doesn't trigger the write itself (`inc/class-rest-api.php`).
5. **Astra turns off page caching for the whole site.** It's safe, but hit rate can't be measured and the core claim is never exercised (`inc/class-response.php`).
6. **Inactive segments in `proto-7c1a` refuse removal**, so readers who lapsed while a segment was off get access back when it's reactivated.
7. **Unprefixed global functions in `proto-7c1a` sit behind `function_exists()`**, so a function with the same name from another plugin would silently replace an access check.
8. **Crawler forgery in `proto-7c1a`.** A `crawler_--_1` cookie unlocks every segment's content. Cookie forgery is an accepted risk, but this widens it.
9. **Newspack finding (Astra docs).** Newspack's `evaluate_rule()` returns true for an unregistered rule, so a failed bridge registration would open the gate. Any bridge needs to guard against it.

## What no branch does

- Suppress the broad `Vary` header, or provide an edge component that reads the consulted set.
- Anonymous metering, edge crawler classification, purging, or a working Newspack `register_rule()` bridge.
- A static-analysis rule against direct `Vary_Cache` reads.
- Any evidence from a VIP application: hit rate, variant counts, purge behavior, or Google Rich Results acceptance.

## How to re-run

### Setup

```sh
git clone https://github.com/Automattic/content-gates.git "$TMPDIR/content-gates"
git -C "$TMPDIR/content-gates" fetch origin
git -C "$TMPDIR/content-gates" diff --stat origin/trunk...origin/<branch>
git -C "$TMPDIR/content-gates" show "origin/<branch>:inc/<file>.php"
```

- In zsh, write `"${B}:path"`, not `$B:path`. Otherwise `:t` and `:f` are read as shell modifiers.
- Exclude agent tooling folders such as `factory/` from size and diff counts.
- Record each branch's commit, and the `trunk` commit it branches from, in the "Branches compared" table.
- For platform facts, clone `vip-go-mu-plugins` (`git clone --depth 1 https://github.com/Automattic/vip-go-mu-plugins.git`) and read `cache/class-vary-cache.php`. Record the commit; this run used `31fbdf3`.

### Order of work

1. Read `trunk`'s `AGENTS.md` and the three specs in `spec/` first. They are the yardstick.
2. For each branch, read `content-gates.php`, `inc/class-plugin.php` (boot order) and `inc/functions.php` (public API), then membership, rendering, REST, editor and admin code, then docs. Skim tests for counts and coverage, not detail.
3. Score each branch against the checklist below.
4. Re-check the platform facts below against current `vip-go-mu-plugins`. If any has changed, findings that depend on it may no longer hold.
5. Treat a branch's own docs as claims to verify, not evidence.

### Checklist

| Criterion | What to look for |
|---|---|
| Segment admin | Custom post type, `pre_trash_post` and `pre_delete_post` blocks, `delete_post` mapped to `do_not_allow`, inactive status, stable ID that survives renames |
| Editor | Group block attribute registered on server and client; dropdown shows active segments; inactive or missing references stay visible |
| Withholding | `pre_render_block` returns `''` for denied Groups; invalid references withhold rather than count as "No segment"; preview exception checks `edit_post` |
| Excerpts and teasers | Automatic excerpts can't include gated text; hand-written excerpts untouched; core excerpt filters still apply |
| Signal coverage | Consulted set finalized after render on pages, feeds, REST and REST `_embed`; what happens on `ob_flush()`, `ob_clean()`, `wp_die()` and early exit |
| Signal integrity | Every read recorded, including false; reads after freeze; whether the filter can remove segments; invalid IDs kept out |
| Assignment timing | Errors before the cookie is read (`init` 10) and after `send_headers` |
| REST membership | Permission default; whether it calls `Vary_Cache::send_headers()`; no-cache headers |
| Revocation | What happens when the last segment is removed, and whether inactive segments allow removal |
| Platform loading | Whether the plugin loads `Vary_Cache` itself, and what it falls back to when it isn't loaded, by environment |
| Cache posture | Whether pages stay cacheable, which headers are sent, and whether the broad `Vary` header is addressed |
| Crawler and metering | Crawler variant, `isAccessibleForFree` and `hasPart` markup, stepped values |
| Public API | Names against the spec, prefixing, ID or key, version, `WP_Error` codes |
| Tests and docs | Counts; whether the docs separate local evidence from VIP evidence |

### Platform facts to re-verify

From `vip-go-mu-plugins` `31fbdf3`, `cache/class-vary-cache.php`:

- Nothing loads the file; only the README example requires it. Requiring it calls `Vary_Cache::load()`, which hooks `parse_cookies` on `init` 10 and `send_headers` on `send_headers` at `PHP_INT_MAX`.
- `register_groups()` sets each group to `''`, overwriting a value already read from the cookie.
- `send_vary_headers()` sends `Vary: X-VIP-Go-Segmentation` whenever any group is stored, even with an empty value.
- `set_group_cookie()` returns early when the serialized groups are empty, so removing the last membership doesn't clear the cookie.
- `vip_vary_cache_did_send_headers` fires whenever a `Vary` header or cookie is sent. Providers following the README's post-redirect-get example must add their redirect only after they assign.
