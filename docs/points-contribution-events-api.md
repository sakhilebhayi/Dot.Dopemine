# Dot Points contribution events (intake)

`POST /api/ingest/contributions`: a platform (today InfoDot) tells the points service what happened to a contribution.
The service decides what it is worth. Signing, the 300 s window and the per-platform secret are the same as the other
`/api/points` endpoints (see `points-redemption-api.md`). The platform is identified by the secret that signed the
request, never by a field in it.

## Envelope

| Field | Notes |
| --- | --- |
| `event_id` | string, max 120. Unique per event. A repeat is answered `200 {"status":"duplicate"}` and ignored. Also the ledger idempotency key. |
| `type` | `infodot.*`, see below |
| `occurred_at` | ISO 8601 |
| `user_uuid` | the contribution's AUTHOR. The first event for a `contribution_ref` fixes its author; a later event naming someone else is `rejected`. |
| `contribution_ref` | stable id, e.g. `infodot:question:123`, max 160 |
| `actor_uuid` | optional uuid: who accepted, verified or approved. Never the author where it matters. |
| `tenant_uuid`, `visibility` | `public` (default) or `company`. Company-only knowledge earns nothing unless `POINTS_REWARD_COMPANY_VISIBILITY` is on. |
| `facts` | optional object. Only the fields named below are read; anything else (voter ids, content) is ignored and never stored. |

## Responses

| HTTP | Body | Meaning | Sender should |
| --- | --- | --- | --- |
| 202 | `{"status": "<outcome>", "detail"?: "..."}` | processed; the outcome is final and recorded | treat as delivered |
| 200 | `{"status":"duplicate"}` | `event_id` seen before | treat as delivered |
| 409 | `{"code":"contribution_not_ready","message":"...","status":"not_ready"}` + `Retry-After: 60` | the event refers to a contribution this service has not been told about yet. **Nothing is recorded**, including the `event_id`, so the retry is processed afresh. | retry later (InfoDot retries 409/425/429/5xx and holds later events for that ref behind it) |
| 401 | | bad or stale signature | operator problem |
| 422 | | malformed envelope | park |

Outcomes under 202: `awarded`, `needs_approval`, `already_rewarded`, `already_approved`, `daily_cap`, `no_points`,
`rejected`, `ignored`, `reversed`, `flagged`, `unsupported`. Only `awarded` creates points. `rejected`/`no_points`/
`ignored` write nothing to the ledger.

## Rules shared by every award

- Paid by the versioned `reward_rules`; the ledger entry records the exact rule version.
- Once per (rule, `contribution_ref`) (`max_awards_per_contribution`), and once per `event_id`.
- Daily caps per author and rule; a capped event is `daily_cap` and pays nothing (an approval stays waiting).
- Company-only knowledge: `no_points` unless the policy is on. A self-answer earns nothing (`no_points`).
- A flagged or reversed contribution earns nothing new (`no_points`).
- A range rule (`min_points`..`max_points`) pays `facts.points` clamped into the range, or the rule's base value when
  `facts.points` is absent.
- Reversal: `infodot.contribution.reward_reversed` (below) takes back the unspent part of any award, approved ones included.

## Approval: a moderator's approval is the approval

Rules with `requires_approval` pay only when somebody who is **not the author** approved. There is one mechanism:

1. The typed event itself is the approval. `infodot.tutorial.approved`, `infodot.troubleshooting.approved`,
   `infodot.knowledge.corrected`, `infodot.knowledge.adopted` (and the multi-user bonus) arrive already approved and
   must name the approver in `actor_uuid`. Missing, or equal to `user_uuid`: `rejected`, nothing written.
2. Alternatively an event may register the contribution **unapproved** with `facts.approved = false`. It is recorded as
   `pending_approval` (outcome `needs_approval`, no points; `facts.points` is remembered). The later
   `infodot.contribution.approved` pays it.
3. A Dopemine administrator may approve from the back-office (`/admin/points/approvals`). It runs the same code path,
   is audited in `admin_audits` (who, when, why) and is idempotent.

`facts.approved` is otherwise not consulted. Dot.Brain takes no part in the decision.

## Event types

### `infodot.question.published`
`ref` e.g. `infodot:question:ID`. `facts.approved` `false` (the normal case): contribution recorded as pending,
`needs_approval`. `facts.approved = true` (older senders): pays the rule at once; if `actor_uuid` is present and equals
the author: `rejected`. Re-sent for an already approved question: `already_rewarded`.

### `infodot.contribution.approved`
`user_uuid` = author, `actor_uuid` = approving moderator (MUST differ from the author, else `rejected`, nothing written).
`facts.kind` is informational. Pays the approval-gated rule the contribution is waiting on, once.
- contribution unknown, or recorded but not yet published: **409 `contribution_not_ready`** (retryable, see above)
- author named does not match: `rejected`
- already paid or already approved (any event id): `already_approved`, no-op
- nothing is waiting on it (e.g. an answer): `ignored`
- flagged or reversed: `no_points`; rule switched off: `no_points` (stays pending); cap reached: `daily_cap` (stays pending)
- company-only: `no_points`, and it leaves the waiting list
- otherwise `awarded`

### `infodot.answer.published`, `infodot.answer.accepted`
Unchanged. Accepted needs `actor_uuid` different from the author.

### `infodot.solution.verified`
`user_uuid` = author, `actor_uuid` = verifier (MUST differ from the author, else `rejected`), e.g.
`infodot:solution:ID`, `facts.kind = "solution"`. May be the first the service hears of the contribution (it is then
recorded as kind `solution`). Pays `infodot.solution.verified` once. A question is `ignored`.
Unverifying: send `infodot.contribution.reward_reversed` with `facts.rule_key = "infodot.solution.verified"`.

### `infodot.tutorial.approved`, `infodot.troubleshooting.approved`, `infodot.knowledge.corrected`, `infodot.knowledge.adopted`
Rule of the same name (all `requires_approval`). The event is the approval: `actor_uuid` required and not the author.
`facts.points` clamped into the rule range (tutorial 10-20, troubleshooting 10-25, correction 3-10, adoption 10-25).
May be the first event for the ref (kind `tutorial`/`troubleshooting`/`correction`/`adoption`). `facts.approved=false`
registers it pending instead (see above).

### `infodot.contribution.helpful`
A threshold crossing computed by InfoDot. `facts.helpful_count` (integer, stored), optional `facts.points` clamped to 1-3.
Never send voter ids; they are not read or stored. The contribution must already be known, else **409
`contribution_not_ready`**. Pays once per contribution (a later, higher threshold is `already_rewarded`).
The multi-user bonus is the same event with `facts.rule_key = "infodot.solution.multi_user_bonus"` (5-20, approval
gated: `actor_uuid` required and not the author). A `rule_key` that does not belong to the event type is `rejected`.

### `infodot.contribution.flagged`
No actor needed. Holds the contribution; takes no points back.

### `infodot.contribution.reward_reversed`
`facts.rule_key` (optional: all awards of the contribution when absent), `facts.reason`. Takes back the unspent part;
the rest is reported in `detail` and the balance never goes below zero. A reversed approved award is not paid again by a
later approval.

## Contribution states

`published` -> `pending_approval` -> `rewarded` (or `approved` when company-only and nothing will be paid); also
`accepted`, `verified`, `flagged`, `reversed`. A contribution has at most one approval waiting at a time.
