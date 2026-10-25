# Dot Points expiry warnings API

Each lot of Dot Points lapses 12 months after it is granted. About 30 days before that, Dot.Dopemine records one
warning per lot (`expiry_warnings`). Dopemine holds no email address, so **InfoDot pulls the warnings that are due,
emails the person itself, and reports the result per warning.** Dopemine never receives an address.

## Delivery mode

`POINTS_EXPIRY_DELIVERY` (config `points.expiry_delivery`) picks the one path that is active:

| Value | Behaviour |
| --- | --- |
| `infodot` (default) | The endpoints below serve warnings. The scheduled `points:warn-expiring` Notify command does nothing (it logs one info line per run). |
| `notify` | The Dot.Notify push (`points:warn-expiring`, needs a recipient lookup) runs as before. `GET` returns `[]`. |
| `none` | Nobody delivers. `GET` returns `[]`; the Notify command does nothing. |

No other environment variable is needed; the signing secret is the same `DOPEMINE_INGEST_SECRET_INFODOT` as the other
`/api/points/*` calls.

## Signing

Identical to [the redemption API](points-redemption-api.md#signing-same-as-the-intake-and-the-points-summary):
`X-InfoDot-Timestamp` (unix seconds) and `X-InfoDot-Signature` = `sha256=` + hex `HMAC-SHA256(secret, "{timestamp}.{raw body}")`,
300 s window. A `GET` is signed over the empty body (`"{timestamp}."`). Failure:

```
401 {"code": "invalid_signature", "message": "Invalid signature."}
```

All errors have the shape `{"code": "...", "message": "..."}` (a `422` adds `errors`). Both routes share a rate limit
of 120 requests per minute (separate from the other points endpoints).

## `GET /api/points/expiry-warnings?limit=100`

`limit`: integer 1..200, default 100 (otherwise `422 validation_failed`).

Returns the warnings that are due to be sent, oldest first: not delivered, not failed, not moot, and not waiting out a
retry delay. Before answering, Dopemine queues a warning for any lot that now falls inside the 30-day window (one per
lot, idempotent), and re-reads each lot: if it was spent or lapsed meanwhile, the warning is closed as `moot` and left
out. `points` is what is left in the lot right now. Each element has exactly these four fields, nothing else:

```json
[
  {"warning_id": 41, "user_uuid": "0b1f2a3c-9d4e-4f60-8a71-5b2c3d4e5f60", "points": 15, "expires_on": "2026-11-14"}
]
```

An empty list means nothing is due. Outside `infodot` mode the answer is always `[]`. Listing does not change a
warning's delivery state: if InfoDot never reports back, the same warning is listed again on the next pull.

## `POST /api/points/expiry-warnings/{warning_id}/result`

Body (signed over the raw JSON):

```json
{"outcome": "delivered"}
```

| `outcome` | Effect |
| --- | --- |
| `delivered` | The email went out. State becomes `delivered`. |
| `retry` | Transient failure. Attempts +1 and the next attempt is scheduled; the warning is not listed again until then. The wait grows 1 h, 2 h, 4 h, 8 h, 16 h; the sixth failed attempt marks it `failed`. |
| `permanent_failure` | Cannot be delivered (for example no usable address). State becomes `failed` now. |

`200 {"warning_id": 41, "state": "delivered"}` where `state` is `pending`, `delivered`, `failed` or `moot`.

| Status | `code` | Meaning |
| --- | --- | --- |
| 401 | `invalid_signature` | Missing, wrong or stale signature |
| 404 | `warning_not_found` | No such warning |
| 422 | `validation_failed` | `outcome` missing or not one of the three values |

Idempotent and monotonic:

- A row that is `delivered`, `failed` or `moot` never changes again; any later report is a no-op that returns `200`
  with the current state.
- Repeating `delivered` or `permanent_failure` is a no-op. A `retry` counts only while the warning is due; repeating
  it during its retry delay is a no-op (`pending`), so a duplicated request never burns two attempts.
- `delivered` after a `retry` still delivers.

## Example

```
GET /api/points/expiry-warnings?limit=50
X-InfoDot-Timestamp: 1792580000
X-InfoDot-Signature: sha256=<hmac of "1792580000.">

200 [{"warning_id": 41, "user_uuid": "0b1f2a3c-9d4e-4f60-8a71-5b2c3d4e5f60", "points": 15, "expires_on": "2026-11-14"}]

POST /api/points/expiry-warnings/41/result      body {"outcome":"retry"}
200 {"warning_id": 41, "state": "pending"}
```
