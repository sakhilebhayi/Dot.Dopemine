# Dot Points redemption API

Server-to-server endpoints a platform (today: InfoDot) uses to show the reward catalogue and let a signed-in person
spend Dot Points. The platform's browser never talks to Dot.Dopemine; the platform's server does, on behalf of the
person it has authenticated, naming that person's `user_uuid`. All money logic (balance check, oldest-lot-first spend,
stock claim, refunds) stays in `RewardRedemptions` / `PointsLedger`; these endpoints only translate it to HTTP.

## Signing (same as the intake and the points summary)

Every request carries:

| Header | Value |
| --- | --- |
| `X-InfoDot-Timestamp` | Unix seconds when the request was made |
| `X-InfoDot-Signature` | `sha256=` + hex `HMAC-SHA256(secret, "{timestamp}.{raw body}")` |

The secret is the per-platform `DOPEMINE_INGEST_SECRET_INFODOT`. A request more than 300 s from the server clock
(either direction), without a valid signature, or from an unconfigured platform gets:

```
401 {"code": "invalid_signature", "message": "Invalid signature."}
```

A `GET` has no body, so it is signed over the empty string: `HMAC(secret, "{timestamp}.")`. This is exactly how the
summary call is signed. The query string (`user_uuid`) is therefore not covered by a GET signature, as with the
summary's path uuid; TLS is what protects it in transit.

Errors always have the shape `{"code": "...", "message": "..."}` (plus extra fields where stated).

## `GET /api/points/rewards`

The catalogue: active rewards of a redeemable kind (`billing_credit`, `capacity`, `marketplace`), cheapest first.
Recognition items never appear; retired rewards never appear. A reward that is out of stock or at its monthly limit is
listed with `available: false`. Stock numbers are never exposed.

```json
[
  {"id": 3, "name": "R50 billing credit", "kind": "billing_credit", "description": "Off your next invoice.", "cost_points": 20, "available": true}
]
```

## `POST /api/points/redemptions`

Request (signed over the raw JSON body):

```json
{"user_uuid": "0b1f2a3c-...-lowercase-uuid", "reward_id": 3, "idempotency_key": "a-unique-id-per-click-8-to-64-chars"}
```

`idempotency_key`: 8 to 64 characters of `A-Za-z0-9._:-`, generated once per user action and re-sent on retries. It is
scoped to `user_uuid`, so one person's key can never replay or collide with another's.

`201 Created` (a new pending redemption; the points are already spent, the reward is delivered by an operator):

```json
{"redemption_id": "9b35d390-7031-4946-807e-784f03d09f42", "status": "pending",
 "reward": {"id": 3, "name": "R50 billing credit", "kind": "billing_credit"},
 "points_spent": 20, "balance_after": 30}
```

`200 OK`: the same `idempotency_key` was already used by this person for this reward. Nothing is spent again. The body
has the same shape; `status` and `balance_after` are the current values at replay time.

| Status | `code` | Meaning |
| --- | --- | --- |
| 401 | `invalid_signature` | Missing, wrong or stale signature |
| 404 | `reward_unavailable` | No such reward, retired, or not a redeemable kind |
| 409 | `insufficient_points` | Not enough unexpired points. Extra fields: `points_needed`, `balance` |
| 409 | `out_of_stock` | The last unit was taken |
| 409 | `monthly_limit_reached` | The reward's all-people monthly limit is used up |
| 409 | `idempotency_key_reused` | The key was already used by this person for a different reward |
| 422 | `validation_failed` | Bad body. Extra field: `errors` (field => messages) |
| 429 | `rate_limited` | More than 10 new redemption requests per person per hour (`POINTS_REDEMPTION_RATE_LIMIT`). Extra: `retry_after` seconds, and a `Retry-After` header |

Failure is all-or-nothing: no points leave, no stock is claimed, no redemption row is written. Replays are never
rate limited or counted.

## `GET /api/points/redemptions?user_uuid=...`

That person's redemptions, newest first (at most 100). Only rows of the given `user_uuid` are ever returned.
`422 validation_failed` when `user_uuid` is missing or not a lowercase uuid.

```json
[
  {"redemption_id": "9b35d390-7031-4946-807e-784f03d09f42",
   "reward": {"name": "R50 billing credit", "kind": "billing_credit"},
   "points_spent": 20, "status": "failed",
   "requested_at": "2026-10-21T10:00:00+00:00", "resolved_at": "2026-10-22T08:30:00+00:00",
   "points_returned": true, "message": "Your points were returned."}
]
```

`status` is `pending`, `fulfilled`, `failed` or `cancelled`. `resolved_at` is `null` while pending.
`points_returned` is `true` for `failed` and `cancelled` (the points come back as a new lot) and `message` then says
so; otherwise `message` is `null`. Operator notes are never returned.

## Summary addition

`GET /api/points/{user_uuid}` (unchanged otherwise) now also returns `next_expiry_points`: the points left in the lot
that lapses first (the one `next_expiry` is the date of), or `null` when the person holds no points.
`expiring_soon` still gives the total lapsing within 30 days.
