# Dot Points digests API (weekly / monthly summaries)

A short, honest summary of what a person earned in the last closed week or month. Dopemine holds no email address, so
**InfoDot pulls the digests that are due, composes and sends the message itself under the person's own preferences, and
reports the result per digest.** Dopemine never receives an address, and a digest never carries contribution titles,
content or any internal id.

Principles: no streaks, no leaderboard, no "you are behind" framing. Only people who **earned** points in the closed
period get a digest (nobody with nothing earned is ever listed, so nobody is nagged), at most one per person per
period, and sending is the person's choice: **default is not to send** (InfoDot owns the preference and defaults it off;
it reports `skipped` for anyone who has not opted in).

The admin back-office needs no change for this.

## Switch

| Setting | Default | Meaning |
| --- | --- | --- |
| `POINTS_DIGESTS_ENABLED` (`points.digests.enabled`) | `false` | While false, `GET` answers `[]` and nothing is produced. Turn it on only when InfoDot's side exists. `POST .../result` still accepts reports for digests that already exist. |
| `POINTS_DIGEST_MAX_PER_PULL` (`points.digests.max_per_pull`) | `200` | Most digests one pull creates and returns; the rest follow on the next pull. |

The signing secret is the same `DOPEMINE_INGEST_SECRET_INFODOT` as the other `/api/points/*` calls.

## Signing

Identical to [the redemption API](points-redemption-api.md#signing-same-as-the-intake-and-the-points-summary):
`X-InfoDot-Timestamp` (unix seconds) and `X-InfoDot-Signature` = `sha256=` + hex `HMAC-SHA256(secret, "{timestamp}.{raw body}")`,
300 s window. A `GET` is signed over the empty body (`"{timestamp}."`). Failure:

```
401 {"code": "invalid_signature", "message": "Invalid signature."}
```

All errors have the shape `{"code": "...", "message": "..."}` (a `422` adds `errors`). Both routes share a rate limit
of 120 requests per minute (its own bucket, separate from the other points endpoints).

## `GET /api/points/digests?period=week|month&limit=100`

- `period`: required, `week` or `month` (otherwise `422 validation_failed`).
- `limit`: integer 1..200, default 100 (also capped by `POINTS_DIGEST_MAX_PER_PULL`).

The period is always the **most recent fully closed** one, in the application timezone (`config('app.timezone')`):

- `week`: the ISO week, Monday to Sunday. On Wednesday 21 Oct 2026 that is 2026-10-12 to 2026-10-18.
- `month`: the calendar month. On 21 Oct 2026 that is 2026-09-01 to 2026-09-30.

Entries are counted by the moment they were written to the ledger, from 00:00 on the first day to the end of the last
day. The digests are made from the ledger rows (`ledger_entries`) at the moment the row is first produced and are then
fixed: a re-pull, or a retry a day later, shows the person the same numbers. Before answering, Dopemine creates the
digest of every person who earned points in that period and has none yet (one row per person, period and period start,
enforced by a unique key, so creating is idempotent).

It returns the digests that are due: `pending` and not waiting out a retry delay, oldest first. Listing does not change
a digest's state: if InfoDot never reports back, the same digest is listed again on the next pull. Only the latest
closed period is listed; a digest that was never reported on when the next period closes is no longer offered.

```json
[
  {
    "digest_id": "5d0c8f0e-6a1b-4f0c-9d54-2f1f3a9d7b21",
    "user_uuid": "0b1f2a3c-9d4e-4f60-8a71-5b2c3d4e5f60",
    "period": "week",
    "period_start": "2026-10-12",
    "period_end": "2026-10-18",
    "points_earned": 20,
    "awards": [
      {"rule_key": "infodot.answer.accepted", "label": "Answer accepted as the solution by the asker or a moderator who is not the answerer.", "count": 1, "points": 10},
      {"rule_key": "infodot.answer.published", "label": "Substantive, original answer.", "count": 2, "points": 10}
    ],
    "points_reversed": 0,
    "balance": 25,
    "expiring_next_30_days": null
  }
]
```

| Field | Meaning |
| --- | --- |
| `digest_id` | Public id (uuid) of this digest; use it in the result call. |
| `user_uuid` | The person. |
| `period`, `period_start`, `period_end` | `week` or `month`, and the first and last day (`Y-m-d`, inclusive). |
| `points_earned` | Points awarded in the period (`award` entries). Always greater than 0 for a listed digest and never negative. A later reversal does not hide what was awarded. Refunds of a failed redemption are the person's own points handed back, so they are not counted as earned. |
| `awards` | Per reward rule: `rule_key`, `label`, `count` (awards) and `points`. Largest first, then by key. `label` is the human wording of the rule (the description the back-office shows for it; a readable form of the key if the rule has none). |
| `points_reversed` | Points the ledger took back in the period (invalid or duplicated awards), as a plain positive amount, shown beside `points_earned` and never netted into it. `0` when nothing was. Only the part that was still unspent is taken back. |
| `balance` | Points the person can spend, when the digest was produced. |
| `expiring_next_30_days` | `{"points": n, "first_at": ISO-8601}` for points that lapse within 30 days of that moment, or `null`. |

An empty list means nothing is due. `[]` is also the answer when the switch is off.

## `POST /api/points/digests/{digest_id}/result`

Body (signed over the raw JSON):

```json
{"outcome": "delivered"}
```

| `outcome` | Effect |
| --- | --- |
| `delivered` | The message went out. State becomes `delivered`. |
| `retry` | Transient failure. Attempts +1 and the next attempt is scheduled; the digest is not listed again until then. The wait grows 1 h, 2 h, 4 h, 8 h; the fifth failed attempt marks it `failed`. |
| `skipped` | The person has not opted in (or must not be sent this). State becomes `skipped` now and permanently: it is never listed or retried. |

`200 {"digest_id": "5d0c8f0e-6a1b-4f0c-9d54-2f1f3a9d7b21", "state": "delivered"}` where `state` is `pending`, `delivered`,
`skipped` or `failed`.

| Status | `code` | Meaning |
| --- | --- | --- |
| 401 | `invalid_signature` | Missing, wrong or stale signature |
| 404 | `digest_not_found` | No such digest (also for an id that is not a uuid) |
| 422 | `validation_failed` | `outcome` missing or not one of the three values |

Idempotent and monotonic:

- A digest that is `delivered`, `skipped` or `failed` never changes again; any later report is a no-op that returns
  `200` with the current state.
- Repeating `delivered` or `skipped` is a no-op. A `retry` counts only while the digest is due; repeating it during
  its retry delay is a no-op (`pending`), so a duplicated request never burns two attempts.
- `delivered` after a `retry` still delivers.

## Example

```
GET /api/points/digests?period=week&limit=50
X-InfoDot-Timestamp: 1792580000
X-InfoDot-Signature: sha256=<hmac of "1792580000.">

200 [{"digest_id": "5d0c8f0e-...", "user_uuid": "0b1f2a3c-...", "period": "week", "period_start": "2026-10-12",
      "period_end": "2026-10-18", "points_earned": 15, "awards": [...], "points_reversed": 0, "balance": 25,
      "expiring_next_30_days": null}]

POST /api/points/digests/5d0c8f0e-.../result      body {"outcome":"skipped"}
200 {"digest_id": "5d0c8f0e-...", "state": "skipped"}
```
