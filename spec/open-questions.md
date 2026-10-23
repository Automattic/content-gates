# Open questions

Proposed changes to the declared-set protocol that were reviewed on
2026-10-23 and **not adopted** for the prototype, with the reasoning and
the evidence that would reopen each one. The confirmed defects from the
same review were fixed and are recorded in [decisions.md](decisions.md),
"Corrections (2026-10-23)".

The source was a proposed "Declared-Set Narrowing Protocol" revision. Its
fail-safe rules for phase 3, its membership adapter requirements, its
phase 4 cookie precedence, its authorization requirements, and its
tooling fixes were adopted. The three items below were disputed.

## 1. Declared-set fingerprint (`cg-set`)

**Proposed.** Phase 1 adds a synthetic group `cg-set_--_<fingerprint>` to
`X-VIP-Go-Segmentation`, where the fingerprint is the first 32 hex
characters of `sha256("v1:" + sorted declared names)`. The name `cg-set`
is reserved; the worker strips it from every browser-supplied value and
from every cookie it writes. Two rules follow from it: a discovery
response (full lookup) is never stored, because its key lacks the
fingerprint, and a narrowed response is covered only when the record
equals the declared set exactly.

**Why it was proposed.** Two declared-set generations can give one reader
the same narrowed key. An anonymous reader has the empty key under
`{donor}` and under `{subscriber}`. If a purge is late or incomplete, a
new-generation lookup could hit an old-generation copy.

**Why it is not adopted now.** Work the collision through. For a reader
to hit an old-generation copy, the old key must equal the reader's new
narrowed key. The old copy was rendered for a reader who held exactly the
groups in that key, so the new reader holds at least those memberships.
The old copy can be **stale**, but it never contains bytes gated to a
group the reader lacks: Groups gated "in X" rendered only if the old
reader was in X, and X is in the key. The fingerprint therefore buys
freshness during a delayed URL purge, not leak protection, and the URL
purge on save is already confirmed by VIP.

The costs are real:

| Cost | Effect |
| --- | --- |
| Reserved name, stripping in phases 1 and 4 | More protocol surface, one more forgery check |
| Never store the discovery response | One extra origin hit per URL per KV learn or eviction |
| Exact equality instead of subset coverage | See question 2 |
| Fingerprint on the full-lookup key is absent | The discovery copy is a dead variant |

Without the fingerprint, storing the discovery response is correct (the
full key covers every group), and for an anonymous reader it is the very
copy the later narrowed lookups hit.

**What would reopen it.** Live scenarios "declared set grows after a
content save and purge" and "declared set shrinks after a content save
and purge" showing that the URL purge misses variants, or a measured
purge delay long enough to matter to editors. If adopted, the cache
cardinality argument in the proposal holds: the fingerprint is constant
within a generation and does not multiply reader variants.

## 2. Exact-equality coverage instead of subset coverage

**Proposed.** A narrowed response is covered only when the record's group
set equals the declared set exactly. A wider record wastes variants; a
changed record needs a new generation.

**Why it is not adopted now.** A wider record is safe: every declared
group is in the key. Phase 3 already replaces the record on any
inequality, so the record converges on the next request either way.
Refusing to store a safe response is pure cost: one more origin hit, and
the response that follows is stored under the same keys the subset rule
would have accepted. The waste a wide record causes is bounded to the one
request between learning and replacement.

The current rule stays: **store when declared ⊆ record; replace the
record whenever the sets differ.** [page-cache.md](page-cache.md),
"Phase 3", and `edge-workers/lib/declared.ts` implement it.

**What would reopen it.** Adopting the fingerprint (question 1), which
makes a changed record a new generation by construction.

## 3. Meter cookie v2 with a per-meter expiry

**Proposed.** `vip-go-meter` version 2 stores `expires` per record. The
cookie `Max-Age` is the longest remaining period across records, so a
visit to a short-period meter cannot shorten a long-period meter's record.
Version 1 records are upgraded or dropped once.

**The defect is real.** Today the cookie's `Max-Age` is the longest period
among the meters on *this response*. A reader who holds a 30-day meter
record and then views a page with a 1-day meter gets a cookie that
expires in one day, taking the 30-day record with it.

**Why it is deferred.** The consequence is an allowance reset in the
reader's favor, not a leak and not a cache problem. Metering is not a
prerequisite for narrowing, and the state machine has not yet been
observed on the platform. Changing the cookie format before that
observation risks changing it twice. The v1-to-v2 migration rule is moot:
no v1 cookie has ever been deployed.

**What would reopen it.** Completion of the live metering scenarios
("provider assigns membership on a metered miss", "two meters with
different periods survive alternating visits"). Then implement v2
directly, without a migration path.

## Agreed but not yet done

- **`segment-max-age` on the meter header.** The plugin's cookie lifetime
  is filterable (`content_gates_cookie_lifetime`); the worker's is a
  constant equal to the default. Carrying the effective lifetime in the
  meter header keeps the two writers consistent on cache hits without an
  edge deployment setting. Cheap; schedule with the next worker change.
- **Explicit `no-store` when origin changed `vip-go-seg`.** Platform
  caches already refuse responses with `Set-Cookie`, and the simulator
  mirrors that. Add the rule to phase 3 as belt-and-braces once the live
  storage-control checks confirm the baseline, so the worker never
  depends on it silently.
- **Crawler responses that carry an origin `vip-go-seg`.** Phase 1
  forwards the worker's crawler classification in the origin-bound cookie,
  so a provider that assigns during a crawler request makes `Vary_Cache`
  write a cookie that includes crawler groups. Phase 4 skips crawlers and
  leaves it in place. Harmless for the cache (the response is not stored)
  and a crawler does not keep cookies, but the rule "crawler responses
  receive no segmentation cookie" is not enforced at the edge.

## Order of work

The proposal placed eight local steps before the first live check, yet
its own blocking condition is a platform fact: whether phase 1 can rewrite
the key the cache uses and whether phase 3 can stop a store. Those checks,
plus the request snapshot and KV checks, need no new code. Run them on a
VIP development application with the current worker and plugin first, and
decide questions 1 to 3 from that evidence.
[known-limitations.md](../docs/known-limitations.md) lists the platform
questions.
