# Segment administration and Group assignments

Open **Settings → Content Gates** as an administrator. Add a name and choose
Active (the default) or Inactive. Each existing segment has its own Save segment
button. Its displayed numeric ID remains stable when its name or status changes.
There is no delete control. The segment repository also rejects standard WordPress
deletion paths; the UI is not that protection boundary.

Users with `manage_options` can create or update records through the nonce-protected
`admin-post.php` action `content_gates_save_segment`. Records use the
`content_gate_segment` type, `publish` for active and `draft` for inactive. Creating
a segment does not grant anyone membership.

In the block editor, select a **Group** and open **Content Gates → Segment** in
its block settings. Choose an active segment or **No segment**. Save the post
normally. The numeric `contentGateSegmentId` lives in the block comment attributes;
clearing the selection removes the attribute. No membership or visibility decision
is persisted in the editor.

Existing inactive assignments remain visible, labeled inactive, and disabled as
new choices. Missing records retain their ID and display an unknown-segment label.
Neither case is silently cleared. If loading segments fails, the existing
assignment remains unchanged. Reopen the editor to retry.

## Editor metadata endpoint

`GET /wp-json/content-gates/v1/segments` is a version-1 metadata endpoint requiring
`edit_posts`. Browser requests use WordPress REST nonce authentication. It returns
an array of `{ "id": 123, "name": "Subscribers", "active": true }`, including
inactive records so existing references remain identifiable. It returns no reader
membership data and sets `Cache-Control: private, no-store`. This is not the
provider membership-assignment API.

## Assets and verification

Run `npm ci && npm run build` to generate `build/index.js` and its WordPress
script-dependency manifest. The generated files ship with the plugin; all CI jobs
build them before runtime checks. The browser bundle uses WordPress-provided
packages, not a separate React runtime.

Host verification covers the build, JS lint, PHPCS and PHPStan. Unit tests in
`tests/unit/test-editor.php` cover the Group schema, metadata permissions and
inactive records, and administrator UI permissions/nonces.

On 2026-10-17, the isolated local WordPress test site at `http://localhost:8891`
passed the real Chromium lifecycle test in `tests/e2e/editor.spec.ts` (1 test,
14.9 seconds):

- Create, rename, deactivate and reactivate through the administrator UI, retaining
  the same segment ID and persisted name/status.
- Select a segment in the real Group dropdown, save and reopen; verify the ID in
  the saved raw post content returned by permissioned REST.
- Preserve the inactive assignment and show its disabled inactive label.
- Clear, save and reopen; verify the attribute is absent and the inactive segment
  is not offered as a new assignment.
- Reactivate; restore the available choice without reassigning the cleared Group.

Reproduce with:

```bash
WP_ENV_PORT=8891 WP_BASE_URL=http://localhost:8891 npm run test:e2e -- editor.spec.ts
```

Run only after the PHP suite releases this test database. The test uses REST
query parameters separately from its route so it also works with plain permalinks.
Targeted JS lint passed after the browser test. Local UI checks do not prove VIP
caching or purge behavior.
