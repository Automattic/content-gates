---
name: before-and-after
description: Captures before/after screenshots of web pages or elements for visual comparison. Use when user says "take before and after", "screenshot comparison", "visual diff", "PR screenshots", "compare old and new", or needs to document UI changes. Accepts two URLs (file://, http://, https://) or two image paths.
allowed-tools:
  - Bash(npx @vercel/before-and-after *)
  - Bash(before-and-after *)
  - Bash(which before-and-after)
  - Bash(npm install -g @vercel/before-and-after)
  - Bash(*/upload-and-copy.sh *)
  - Bash(gh pr view *)
  - Bash(gh pr edit *)
  - Bash(which gh)
---

# Before-After Screenshot Skill

> **Package:** `@vercel/before-and-after`
> Never use `before-and-after` (wrong package).

## Data handling (read first)

Screenshots of VIP Dashboard, Admin Console, or any GOOP/Parker-backed page can contain customer names, site URLs, emails, and integration config. Therefore:

- Capture only against **local dev data** (`localhost` with the dev fixtures). Never screenshot production or staging.
- Upload only to the internal endpoint set in `BLOB_UPLOAD_URL`. The public adapters (0x0.st, GitHub Gist) have been removed from this skill. **Do not add them back or pass `--upload-url` to the CLI.**
- If `BLOB_UPLOAD_URL` is not set, do not upload anywhere. Attach the PNGs through the PR comment box in an authenticated browser instead, which keeps them in GitHub's repo-scoped attachment storage.
- Before uploading or attaching, open each PNG and confirm it shows no real customer data, secrets, or internal-only URLs. If it does, do not upload; report it.

## Agent Behavior Rules

**DO NOT:**
- Switch git branches, stash changes, start dev servers, or assume what "before" is
  (Under `/factory`, the orchestrator starts the "before" and "after" servers in step 10b and passes both URLs; this skill only captures.)
- Use `--full` unless user explicitly asks for full page / full scroll capture
- Pass `--markdown` to the `before-and-after` CLI itself (it uploads to 0x0.st internally). Use the CLI only to produce PNGs; use `scripts/upload-and-copy.sh` for the upload.

**DO:**
- Use `--mobile` / `--tablet` if user mentions phone, mobile, tablet, responsive, etc.
- Assume current state is **After**
- If only one URL is given or the request says "PR screenshots" without URLs, **ASK**: "What URL should I use for the 'before' state? (another local port, or an existing image)"

## Execution Order (MUST follow)

1. **Pre-flight** — `which before-and-after || npm install -g @vercel/before-and-after`
2. **Capture** — `before-and-after "<before-url>" "<after-url>" -o <output-dir>` (PNGs only, no `--markdown`)
3. **Inspect** — open both PNGs; confirm no customer data, secrets, or internal URLs are visible
4. **Upload** — `./scripts/upload-and-copy.sh <before.png> <after.png> --markdown` (requires `BLOB_UPLOAD_URL`), or attach via the PR comment box if no internal endpoint is configured
5. **PR integration** — optionally `gh pr edit` to append the markdown table to a PR the factory opened

## Quick Reference

```bash
# Basic usage
before-and-after <before-url> <after-url> -o .artifacts/<task>

# With selector
before-and-after url1 url2 ".hero-section" -o .artifacts/<task>

# Different selectors for each
before-and-after url1 url2 ".old-card" ".new-card" -o .artifacts/<task>

# Viewports
before-and-after url1 url2 --mobile    # 375x812
before-and-after url1 url2 --tablet    # 768x1024
before-and-after url1 url2 --full      # full scroll

# Via npx (use full package name!)
npx @vercel/before-and-after url1 url2 -o .artifacts/<task>
```

| Flag | Description |
|------|-------------|
| `-m, --mobile` | Mobile viewport (375x812) |
| `-t, --tablet` | Tablet viewport (768x1024) |
| `--size <WxH>` | Custom viewport |
| `-f, --full` | Full scrollable page |
| `-s, --selector` | CSS selector to capture |
| `-o, --output` | Output directory. Always set this to the gitignored `.artifacts/` dir |

Do **not** use `--markdown` or `--upload-url` on the CLI.

## Image Upload

```bash
BLOB_UPLOAD_URL=https://<internal-endpoint>/upload \
  ./scripts/upload-and-copy.sh before.png after.png --markdown
```

The script refuses to run without `BLOB_UPLOAD_URL` and refuses any adapter other than `blob`.

## PR Integration

```bash
which gh
gh pr view --json number,body
gh pr edit <number> --body "<existing-body>

## Before and After
<generated-markdown>"
```

Only edit PRs the factory opened. If no `gh` CLI: output the markdown and hand it to the requester.

## Error Reference

| Error | Fix |
|-------|-----|
| `command not found` | `npm install -g @vercel/before-and-after` |
| `could not determine executable` | Use `npx @vercel/before-and-after` (full name) |
| `BLOB_UPLOAD_URL is not set` | Configure the internal endpoint, or attach via the PR comment box |
| Element not found | Verify selector exists on page |
