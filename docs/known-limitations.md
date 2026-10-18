# Prototype limitations and validation status

This repository proves origin rendering and the WordPress integration. It does
not prove production edge behavior.

## Delivery surfaces

| Surface | Prototype status |
|---|---|
| Page HTML | Server-side withholding implemented and tested locally. |
| REST `context=view` | Rendered content uses server-side withholding and is tested locally. |
| RSS and Atom feeds | Block rendering uses the same withholding path and raw RSS response bytes are tested locally. |
| Automatic excerpts and listing teasers | The plugin rebuilds automatic excerpts through access-controlled block rendering before trimming. Manual excerpts remain author-controlled public text. |
| oEmbed | No dedicated integration. Record as unverified. |
| AMP | No AMP integration is installed. Record as not applicable locally and unverified on a target site. |
| Head metadata and structured data | No generated paywall metadata in this prototype. |
| Sitemaps | No dedicated integration. Record as unverified. |
| Object and fragment caches | The plugin does not cache rendered gated HTML. Third-party fragment caches remain an audit requirement. |

## Live-WPVIP evidence still required

- Confirm that VIP accepts the candidate `X-Content-Gate-Vary` signal and keys
  each stored response on its own consulted set.
- Suppress or supersede the broad `Vary: X-VIP-Go-Segmentation` behavior
  without cross-reader leakage.
- Alternate entitled and non-entitled readers on cold and warm edge responses.
- Measure stored variant counts and confirm that unrelated membership does not
  create copies.
- Measure cache hit rate against the same URL workload and keep it within five
  percentage points of baseline.
- Measure output-buffer memory and origin time to first byte.
- Purge all variants after content, assignment, segment, and provider changes.
- Validate registration and assignment timing on page, REST, feed, admin-ajax,
  and cron paths.
- Validate encrypted segmentation and edge cookie-writer compatibility.

## Prototype diagnostics still required

- Add debug-only output for the projected variant key. The prototype reports
  the consulted group keys but does not report their current values.
- Add an automated static rule that rejects direct `Vary_Cache` membership
  reads outside the adapter. The current source audit finds no direct reads,
  and runtime tests cover calls before cookie parsing and after headers.
- Warn providers before the serialized membership cookie approaches the 4 KB
  browser limit. The prototype does not yet estimate encrypted-cookie overhead.

## Work outside this repository

The prototype does not implement the VIP Edge Worker for anonymous metering or
crawler classification. It does not include the Newspack `register_rule()`
bridge or the upstream Newspack hooks. It does not generate crawler paywall
structured data or claim Google Rich Results acceptance. These items need work
and evidence in their owning systems.

The documented platform cookie-forgery and user-agent-spoofing limitations
remain accepted risks for this prototype. Do not describe the contract as
tamper-proof.
