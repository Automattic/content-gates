# Moona Software Factory

Moona takes a rough feature request for WordPress VIP and turns it into a reviewed, tested pull request. You describe what you want, approve the story and the technical brief when asked, review the finished diff, and say "open the PR". Seven specialised agents do the rest, in a fixed order, with a human gate before anything leaves your machine.

It runs inside Claude Code. The only command you need is `/factory`.

## What you need before the first run

- **Claude Code** installed and signed in.
- **GitHub access** to the VIP repos you want to work on, with the GitHub CLI authenticated: `gh auth login`. The factory clones, pushes, and opens PRs as you.
- **Node, Docker, and the repo's local stack** for whichever repo you target. For `vip-go-api` that means MySQL and Redis containers and the GOOP app running locally; its `docs/SETUP.md` is the guide. The factory refuses to call tests "passed" without actually running them, so an environment that doesn't come up stops the run.
- Optional: an internal upload endpoint in `BLOB_UPLOAD_URL` for screenshots and recordings. Without it, evidence is attached through the PR comment box instead. Public image hosts are disabled on purpose.

Clone this repository, open it in Claude Code, and you're ready.

## How to use it

Start a feature:

```
/factory Add a "Download logs" button to the site Dashboard page that fetches the last 24h of logs via Parker
```

The factory will:

1. Research the relevant code and tell you what it found.
2. Write a user story with acceptance criteria and **ask you to approve it**.
3. Write a technical brief and **ask you to approve it**.
4. Create an isolated worktree and branch, build the backend, then the frontend, each with tests.
5. Write acceptance tests against the story and run an independent validation pass.
6. Show you the verdict, the test coverage table, the residual risks, and the diff summary, and **ask whether to open the PR**.
7. Push, open a draft PR with before/after evidence, and give you the PR URL and a run report.

A full run with no revisions takes somewhere between a long coffee and an afternoon, depending on the feature and how long the test suites take. You don't need to watch it; it stops and waits whenever it needs you.

Other modes:

```
/factory research How does integration status get enforced for log shipping?
/factory validate 20261008-download-logs-a3f2
/factory resume 20261008-download-logs-a3f2
```

`research` answers a codebase question and stops. `validate` runs the independent review against an existing run. `resume` continues a run that was paused at a gate or blocked, even in a new session.

## What you'll be asked

**At the story gate**: read the story. Does it describe the right user, the right behaviour, and the right outcome? Are the acceptance criteria what you'd test? Anything the writer couldn't determine is listed as an open question; answer those in your reply. Then say one of:

- *approve*
- *changes requested* followed by what to change (you can do this three times before the factory asks whether to keep going)
- *reject* (the run stops; you get a summary of what was explored)

**At the brief gate**: same three answers, against the technical brief. The brief names the files that will change, the data model, the API, and two sections worth reading closely: tenant isolation and timezone handling. If the brief has blocking questions (for example, a schema change that needs SysEng coordination, or a permission the design seems to need), they're listed first; answer them and the answers go into the brief.

**At the final gate**: you see the validator's verdict (`READY FOR PR`, `FIX THEN PR`, or `NOT READY`), the counts of critical / important / minor findings, the acceptance-test coverage table, the residual risks no local test can cover, and `git diff --stat`. Say *open the PR*, *changes requested* with details, or *stop*.

**When something is missing**: the factory asks once, clearly, and waits. The common cases:

- A repo it needs isn't cloned under `workspaces/` or your account can't read it. Clone it or grant access, then `resume`.
- An agent hit a question only a human can answer (product intent, a rule not in the brief, an ambiguity that changes the design). It gives you the question and its recommended answer.
- The local environment won't come up. It tells you what it tried.
- A loop cap was reached: three story revisions, three brief revisions, or two validator fix cycles. It asks whether to continue, change approach, or stop.

## What it will never do

- Push, open a PR, or post anywhere before you say "open the PR" at the final gate.
- Merge, deploy, release, or trigger CI.
- Add database migrations. Schema changes go in `schemas/` for local use, and the PR notes that production needs SysEng.
- Widen a role, capability, or service-account permission to make a feature work.
- Call production or staging APIs with anything but reads, or touch real customer data.
- Commit secrets or local config. Staging is guarded; `git add -A` is banned.
- Resolve a human's review comments.
- Upload screenshots or recordings to public hosts.

If a rule would have to be broken to finish, the run stops as `FAILED` and tells you why.

## Where things end up

Each run writes to `runs/<run-id>/`, which is gitignored:

| File | What it is |
|---|---|
| `state.md` | Where the run is and what it's waiting for. `resume` reads this. |
| `research.md`, `story.md`, `spec.md` | The research, the approved story, the approved brief. Earlier versions kept as `-v<n>`. |
| `backend-summary.md`, `frontend-summary.md` | What each builder changed, reused, and left unverified. |
| `acceptance-report.md` | One test per acceptance criterion, with pass / fail / not covered. |
| `validation-report.md` | Findings by severity with file and line, what was checked clean, residual risk. |
| `report.md` | The final summary: PR URL, verdict, suggested additions to `CLAUDE.md`. |

Target repos are cloned under `workspaces/<repo>/` (also gitignored). Those clones are caches; every run fetches and branches from `origin/<default>`, so an old clone is never a problem. Work happens in a worktree on a branch named `agent/<run-id>`, and the default branch is never touched.

## How it's put together

```
CLAUDE.md                      the rules every run follows
.claude/commands/factory.md    the /factory orchestrator
.claude/agents/                seven agents, one job each
<skill>/SKILL.md               reusable procedures the agents and orchestrator run
knowledge/repos.md             which VIP repos the factory may touch, and how they relate
```

The agents, in the order they run:

| Agent | Does | Can't |
|---|---|---|
| `codebase-researcher` | Explains how an area works today | Edit anything |
| `story-writer` | Turns the idea into a story with testable criteria | Invent product rules |
| `spec-writer` | Turns the story into a technical brief | Edit anything |
| `backend-builder` | Implements API, services, data, jobs, and their tests | Touch frontend files, push, open PRs |
| `frontend-builder` | Implements UI and its tests against the API as built | Touch backend files, invent endpoints |
| `test-verifier` | Writes acceptance tests, one per criterion, and runs them | Fix the feature, edit non-test files |
| `implementation-validator` | Independent review with file:line findings | Fix anything |

Skills used along the way: `new-feature` (isolated worktree), `build-with-tests` (how code and tests get written), `evidence-driven-testing` and `before-and-after` (proof it works), `unslop` (plain language in everything a person reads). The `greploop` / `greploop-apps` skills are kept for manual use but are not part of the factory, since VIP repos don't run Greptile.

## Adding a repo

The factory only works on repos listed in `knowledge/repos.md`. To add one, add a row with its URL, what it is, and how it relates to the others; leave the "confirm on clone" columns alone until the first run against it fills them in. Adding a repo is a human decision, not something a run does for itself.

## When something goes wrong

Read `runs/<run-id>/state.md` first; it says which step the run is on, what it's waiting for, and which agent last reported what. Then `report.md` if the run ended. Every stop names the artifact to look at and how to resume.

If an agent or skill misbehaved (wrong command, wrong assumption, edited outside its scope), the run report records it under defects. Fix the agent or skill file, not the run.

## Contributing

`CLAUDE.md` is the constitution: facts about the platform and rules that never bend. `.claude/commands/factory.md` owns order, gates, and recovery. Agents own one role each. Skills own procedures. When you change behaviour, change it in the layer that owns it, and keep `CLAUDE.md` short; it is loaded into every session.

Nothing request-specific is committed: `runs/`, `workspaces/`, `.artifacts/`, and local config are all gitignored.
