# Engagement/Outcome Decoupling Detection — Design Spec

**Status:** Approved by user, ready for implementation planning.
**Platform:** Dot.Dopemine
**Date:** 2026-08-09

## Context

`Dot.Brain/platforms/dot-dopemine.md`'s Autonomy Classification audit (§Level 1, §Level 2,
2026-08-08) confirmed this platform has zero automation today — no `app/Console/Commands`, no
`Schedule::` entries, no jobs, no notifications, no CI. Every mutation (certify, decertify, deploy,
retire) is a direct human click gated by `MechanicCatalog::canGovern()`.

The audit names the platform's own natural Level 1/Level 2 candidate: a scheduled job computing
`engagement.outcome_coupling_rate` (§11: "Deployments where outcome metric moved with engagement /
all active deployments"), extended to detect engagement-up/outcome-flat decoupling and write a
flagged "retirement candidate" record for admin approval. But this candidate is unusual relative
to every other platform's Level 1/2 gap closed so far this program (Auction, Billing, Central,
Design): those all detected/gated something computed from data that **already exists**. Here, the
underlying data does not exist. `wiki.md` §4.1 states explicitly: *"The MVP domain layer implements
three of the five entities above as real Eloquent models. The other two — Wellbeing observation and
Mechanic outcome (the paired engagement/outcome ledger) — remain design intent."*

Presented to the user as an explicit choice (skip honestly / build the minimal data model + gate
anyway / find something narrower). **The user chose to build the minimal data model and the
Level 1/2 gate on top of it** — acknowledged as a genuinely new, larger feature rather than a
narrow gap-closer.

## Goal

Implement wiki.md §4's two missing domain entities (Wellbeing observation, Mechanic outcome) as
real Eloquent models with the same kind of structural ethics enforcement already used elsewhere in
this codebase, then build the platform's first-ever scheduled job on top: Level 1 computes
`engagement.outcome_coupling_rate` per certified mechanic; Level 2 detects decoupling and raises a
retirement-candidate proposal for `canGovern()`-gated human review.

## 1. Data Model

### `MechanicOutcome` (natural key: deployment + period — wiki.md §4)

Table `mechanic_outcomes`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `deployment_id` | FK → `mechanic_deployments` | cascade on delete |
| `period_start` | date | |
| `period_end` | date | |
| `engagement_movement` | decimal(8,4) | signed; NOT NULL |
| `outcome_movement` | decimal(8,4) | signed; NOT NULL |
| `recorded_by` | FK → `users` | |
| `notes` | text, nullable | |
| timestamps | | |

Unique index on `(deployment_id, period_start, period_end)`.

`engagement_movement` and `outcome_movement` are both required, non-nullable columns — there is no
schema-level way to record one without the other. This is the literal implementation of the
Roadmap's "reject-at-ingestion for unpaired engagement metrics" (wiki.md §9): the rejection happens
because the column doesn't accept a null, not because a validator can be routed around.

Model `App\Models\MechanicOutcome`: `belongsTo(MechanicDeployment::class, 'deployment_id')`,
`belongsTo(User::class, 'recorded_by')`. No `team_id` of its own — always created/queried through an
already-resolved, already-team-scoped `MechanicDeployment` (see §2), so a redundant scope column
would duplicate authority that already exists one hop away.

### `WellbeingObservation` (natural key: mechanic + cohort + window — wiki.md §4)

Table `wellbeing_observations`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `mechanic_id` | FK → `mechanics` | cascade on delete |
| `cohort` | string(255) | free-text label, e.g. "Dot.Projects — pilot team" |
| `window_start` | date | |
| `window_end` | date | |
| `cohort_size` | unsigned int | |
| `wellbeing_movement` | decimal(8,4) | signed |
| `recorded_by` | FK → `users` | |
| `notes` | text, nullable | |
| timestamps | | |

Unique index on `(mechanic_id, cohort, window_start, window_end)`.

Model `App\Models\WellbeingObservation`: `belongsTo(Mechanic::class)`, `belongsTo(User::class,
'recorded_by')`. `booted()` registers a `static::saving()` listener:

```php
protected static function booted(): void
{
    static::saving(function (WellbeingObservation $observation) {
        if ($observation->cohort_size < 50) {
            throw new RuntimeException(
                "Wellbeing observations must be aggregate, n ≥ 50 — never individual-level (wiki.md §4)."
            );
        }
    });
}
```

This is the fourth layer of this codebase's existing structural ethics gate (alongside the
`MechanicCategory` enum, `Mechanic::booted()`'s acid-test check, and
`MechanicDeployment::booted()`'s certified-only check) — enforcing wiki.md §4's "Aggregate only, n ≥
50 — never individual-level" rule at the model layer, not just in a form.

## 2. Recording

Neither entity has a live telemetry source — nothing in this codebase (or the wider ecosystem, per
every other platform audited this program) pipes real cross-platform metrics into Dot.Dopemine.
Both are human-entered, matching every other manually-logged ledger built this program (Central's
`DispatchDecision`, Auction's reserve proposals).

**`MechanicOutcome`** is recorded from `MechanicDeployments` (`/mechanics/deployments`, the current
team's deployment list). A "Record Outcome" form is added per active deployment row. Authority: any
member of the deployment's own team — the same bar the existing `retire()` action on this component
already uses (`MechanicDeployment::HasTeamScope` already restricts the underlying query to the
current team; no new role invented). This matches wiki.md §3's architecture diagram: *"Consuming
platforms → paired engagement + outcome data → [Ledger]"* — the deploying team self-reports its own
metrics.

Fields on the form: `period_start`, `period_end`, `engagement_movement`, `outcome_movement`,
`notes`. Validation: both movement fields required numeric, `period_end >= period_start`.

**`WellbeingObservation`** is recorded from `MechanicCatalog` (`/mechanics`, the global catalog). A
"Record Wellbeing Observation" form is added per mechanic. Authority: `canGovern()` — the same
team-`admin` bar already gating `certify`/`startDecertify`/`confirmDecertify` on this component. A
wellbeing judgment call is a governance act, not a per-deployment usage metric, so it reuses the
certification authority rather than the deployment-team authority.

Fields on the form: `cohort`, `window_start`, `window_end`, `cohort_size`, `wellbeing_movement`,
`notes`. Validation: `cohort_size` integer ≥ 50 (mirrored client-side; the model's `saving` listener
is the real backstop), `window_end >= window_start`.

## 3. Level 1 — `dopemine:scan-decoupling`

This platform's first-ever scheduled command (`app/Console/Commands/ScanMechanicDecoupling.php`,
`dopemine:scan-decoupling`), scheduled `->daily()` in `routes/console.php` — matching the daily
cadence already established for this kind of low-frequency ethics-review job in Dot.Design's
`ScanTokenDrift`.

For each `Mechanic` where `status = certified`:

1. Collect `MechanicOutcome` records across the mechanic's active deployments
   (`$mechanic->activeDeployments`) where `period_end >= now()->subMonths(3)`.
2. A record is **coupled** unless `engagement_movement > 0 AND outcome_movement <= 0` — the exact
   "engagement-up/outcome-flat" pattern named throughout wiki.md (§2, §10, §11) and the audit. Any
   other combination (engagement flat or down, or engagement and outcome both up) counts as coupled.
3. `coupling_rate = count(coupled) / count(total)`, matching §11's definition ("outcome metric moved
   with engagement / all active deployments") applied at the per-mechanic, per-outcome-record
   granularity available from this MVP ledger.
4. Write `coupling_rate` (decimal(5,4), nullable) and `coupling_rate_computed_at` (datetime,
   nullable) onto the `Mechanic` row — two new columns via migration. If there are zero outcome
   records in the window, leave both null (no data, not a 0% rate — avoids a false "fully
   decoupled" reading for a mechanic nobody has reported on yet).

This step performs no mutation to certification status and requires no human step — pure computed,
persisted reporting output, matching the audit's own description of the Level 1 candidate.

`MechanicCatalog`'s view displays the coupling rate as a badge per mechanic row when
`coupling_rate_computed_at` is not null (e.g. "Coupling: 82% (11 records)").

## 4. Level 2 — retirement-candidate gate

Same command, same per-mechanic loop, immediately after step 4 above:

5. If the mechanic has **at least 2** outcome records in the 3-month window **and**
   `coupling_rate < 0.5` (a minority of recorded periods stayed coupled), write or refresh a
   `MechanicRetirementCandidate`:
   - If an `open` candidate already exists for this mechanic, refresh its `coupling_rate`,
     `sample_size`, keep its original `detected_at` (mirrors Dot.Design's `ScanTokenDrift`
     refresh-not-duplicate logic — avoids spamming a new proposal every day the condition holds).
   - Otherwise create one with `detected_at = now()`.
6. If the mechanic no longer meets the threshold (coupling_rate rose to ≥ 0.5, or fewer than 2
   records remain) and an `open` candidate exists, leave it as-is — a human already needs to look at
   it either way, and auto-clearing an open governance flag without a human decision would undercut
   the point of the gate. (Contrast with Design's drift notices, which auto-clear on resync because
   token drift has no governance stakes; a decoupling finding does.)

Table `mechanic_retirement_candidates`:

| Column | Type | Notes |
|---|---|---|
| `id` | bigint pk | |
| `mechanic_id` | FK → `mechanics` | cascade on delete |
| `coupling_rate` | decimal(5,4) | snapshot at (re)detection |
| `sample_size` | unsigned int | outcome-record count considered |
| `status` | string, default `open` | `open` \| `confirmed` \| `dismissed` |
| `detected_at` | datetime | set once, preserved across refreshes |
| `reviewed_by` | FK → `users`, nullable | |
| `review_notes` | text, nullable | |
| `reviewed_at` | datetime, nullable | |
| timestamps | | |

Model `App\Models\MechanicRetirementCandidate`: `belongsTo(Mechanic::class)`,
`belongsTo(User::class, 'reviewed_by')`.

**Review UI**, added to `MechanicCatalog`, visible only when `canGovern()` — a "Retirement
Candidates" section listing every `open` candidate, mirroring the presentation of Central's "Awaiting
Review" banner and Auction's "Awaiting Your Decision" section. Each row shows the mechanic name,
`coupling_rate`, `sample_size`, `detected_at`, and — as read-only context, not part of the
decision logic — the mechanic's single most recent `WellbeingObservation` if one exists (cohort,
window, `wellbeing_movement`), giving the reviewing admin the same two signals wiki.md's own worked
example pairs ("engagement and outcome coupled, wellbeing guard flat").

Two actions on `MechanicCatalog`, both `abort_unless($this->canGovern(), 403)`:

- **`confirmRetirementCandidate(int $candidateId)`** — calls the existing
  `App\Actions\Dopemine\DecertifyMechanic::decertify()` with a reason string auto-filled from the
  finding (e.g. `"Decoupling finding: coupling rate {rate} across {n} outcome records over the last
  3 months (wiki.md §11)."`), then marks the candidate `confirmed`, sets `reviewed_by`/`reviewed_at`.
  Decertification itself is entirely the existing, unchanged action — this gate only ever proposes,
  never auto-executes.
- **`dismissRetirementCandidate(int $candidateId, string $notes)`** — marks `dismissed`, records
  `review_notes`/`reviewed_by`/`reviewed_at`. Mechanic stays certified. `notes` required
  (`max:2000`), matching `DecertifyMechanic`'s own `reason` validation shape.

## Out of Scope (explicitly, for this spec)

- No automated pipeline pulling real metrics from consuming platforms — both ledgers are hand-
  recorded, matching this program's established "MVP, human-logged" pattern for every other new
  ledger this session (Central's `DispatchDecision`, Auction's `ReserveNotMetProposal`).
- `WellbeingObservation` is not wired into the decoupling detection formula itself — it is recorded,
  structurally gated, and surfaced as review context only. Wiki.md's own worked example treats
  coupling and the wellbeing guard as two independent signals ("engagement and outcome coupled,
  wellbeing guard flat"), not one computed from the other; folding it into the threshold logic would
  invent a formula wiki.md never specifies.
- No dedicated Ethics Officer identity — both new authority checks reuse existing bars
  (`HasTeamScope` team membership for outcomes, `canGovern()` for wellbeing observations and
  retirement review), consistent with wiki.md §4.1's already-documented "Open implementation gap."
- No editing or deleting recorded outcomes/observations after creation — matches this program's
  precedent for hand-logged ledgers elsewhere (append-only).

## Testing Notes

- `MechanicOutcome`: unpaired-insert rejection (DB-level NOT NULL), unique-natural-key rejection,
  team-scoped-via-deployment recording (cross-team member cannot record against another team's
  deployment — `findOrFail` on a `HasTeamScope`-guarded deployment already throws
  `ModelNotFoundException` for this, same pattern confirmed in Central/Billing this program).
- `WellbeingObservation`: `cohort_size < 50` rejection (both the direct model-level `RuntimeException`
  and the form-level validation path), successful creation at exactly `cohort_size = 50`.
- `ScanMechanicDecoupling`: coupling_rate arithmetic (coupled vs. decoupled record classification),
  no-data mechanics left null rather than 0%, threshold crossing creates a candidate, an already-open
  candidate refreshes `coupling_rate`/`sample_size` but preserves `detected_at`, a mechanic that
  never crosses the threshold never gets a candidate.
- `MechanicCatalog::confirmRetirementCandidate`/`dismissRetirementCandidate`: `canGovern()`
  authorization (403 for non-admins, matching every other gated action on this component), confirm
  path actually decertifies via the existing action, dismiss path leaves certification untouched.
