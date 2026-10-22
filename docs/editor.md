# Editor: the Segments control

Content Gates adds one control to the block editor: a **Segments** panel in
the settings sidebar of every Group block (`core/group`). Editors use it to
say which reader segments receive a Group. The server withholds the Group
from everyone else. No other block type gets the control; wrap blocks in a
Group to gate them.

## What the control does

Select a Group block and open the **Segments** panel in the block settings
sidebar. The panel has three parts:

1. A one-line help text that states the current effect of the selection.
2. A checkbox per segment, labeled with the segment's name. Tick one or more.
   Crawler segments (the ones that classify search and AI crawlers) carry a
   short hint below the label. They are assignable like any other segment;
   that is how crawlers receive gated content for paywall structured data.
3. A **Show to** choice with two options:
   - **Readers in any selected segment** (the default).
   - **Readers in none of the selected segments**.

A Group with no segment selected is unrestricted. That is the default for
every new Group. Clearing every checkbox removes the restriction and resets
**Show to** to the default.

When the site has no active segment, the panel says
"No active segments. Create one under Settings → Content Gates." Segments are
managed there, not in the editor.

The segment list is fetched once per editor session from
`GET /content-gates/v1/segments` (authenticated, `edit_posts`). While it
loads the panel shows a spinner; if the request fails the panel shows the
error and retries the next time a Group is selected.

## How the assignment is stored

The editor writes two block attributes on the Group. The server registers the
same two attributes (`inc/rendering/class-block-gate.php`) and is the only
place that enforces them.

| Attribute              | Type       | Default | Meaning                                      |
| ---------------------- | ---------- | ------- | -------------------------------------------- |
| `contentGatesSegments` | `string[]` | `[]`    | Segment keys. Empty means unrestricted.      |
| `contentGatesMode`     | `string`   | `"in"`  | `"in"` or `"not-in"`; see **Show to** above. |

The editor stores segment keys, not names or IDs, so renaming a segment does
not change saved content. Example of a serialized Group assigned to the
`subscriber` and `crawlers-search` segments:

```html
<!-- wp:group {"contentGatesSegments":["subscriber","crawlers-search"],"layout":{"type":"constrained"}} -->
<div class="wp-block-group">
<!-- wp:paragraph -->
<p>Only subscribers and search crawlers receive this paragraph.</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
```

`contentGatesMode` is absent above because it equals its default (`"in"`);
WordPress omits default values from the block comment. A subscribe prompt
shown to everyone *except* subscribers serializes as:

```html
<!-- wp:group {"contentGatesSegments":["subscriber"],"contentGatesMode":"not-in"} -->
<div class="wp-block-group"><!-- wp:paragraph --><p>Subscribe to keep reading.</p><!-- /wp:paragraph --></div>
<!-- /wp:group -->
```

A Group whose checkboxes were all cleared carries neither attribute.

## What happens on the front end

Enforcement runs on the server in `pre_render_block`, so it applies to page
HTML, REST responses, and feeds alike. A withheld Group leaves no markup
behind: no wrapper, no inner blocks, and no child render callbacks run.
Nothing is hidden with CSS or JavaScript.

| **Show to**                                 | Reader in at least one selected segment | Reader in none of them |
| ------------------------------------------- | --------------------------------------- | ---------------------- |
| Readers in any selected segment (`in`)      | Group renders                           | Group withheld         |
| Readers in none of the selected segments (`not-in`) | Group withheld                  | Group renders          |

Several segments on one Group always combine with OR: "in" means in any of
them, "not in" means in none of them. There is no AND rule or priority. Every
listed segment, in either mode, joins the post's declared set so the edge
cache keys the response on it (see `spec/page-cache.md`).

Membership is decided by the entitlement provider, not by the editor. A
reader who is logged in but not entitled is still "not in" the segment.
Previews obey the same rule; there is no bypass for administrators.

## Inactive segments

A segment can be deactivated under **Settings → Content Gates**, but never
deleted. In the editor:

- Inactive segments are not offered for new assignment. They do not appear in
  the list.
- A Group that already references an inactive segment still shows it,
  checked, labeled "Name (inactive)", so the editor can recognize the
  assignment and clear it. Saving the post keeps the key unless the editor
  unchecks it.
- A referenced key the site does not know at all is shown the same way,
  labeled "key (not found)".

On the front end an inactive segment leaves the declared set, so a membership
read of it returns `false`. A Group assigned only to an inactive segment in
`in` mode is therefore withheld from everyone; in `not-in` mode it renders
for everyone. Reactivating the segment restores the original behavior
without editing content.

## Building the script

Source lives in `src/` (TypeScript and TSX). WordPress serves the
`@wordpress/*` packages and the React JSX runtime as browser globals, so
`wp-scripts` rewrites those imports and lists them as script dependencies in
`build/index.asset.php`. `src/types/` holds small type declarations for the
surface the script uses; the shims and the `paths` entries in
`tsconfig.json` can be deleted once the packages are installed as
dependencies.

```bash
npm run build          # one-off production build into build/
npm run start          # rebuild on change while developing
npm run lint:js        # ESLint (wp-scripts flat config + eslint.config.cjs)
npx tsc --noEmit       # type check src/
```

`build/` is gitignored. The PHP side (`inc/editor/class-editor-assets.php`)
enqueues `build/index.js` with the dependencies and version from
`build/index.asset.php`. When the build is missing the plugin still gates
content; it only lacks the editor control and shows an admin notice that
says so.
