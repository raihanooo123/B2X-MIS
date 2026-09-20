# Real-Time Gateway

**B2B Wholesale & Distribution Platform**

| | |
|---|---|
| Document | 11 — Real-Time Gateway |
| Status | Complete |
| Depends on | 02 (entities), 04 (inventory events), 07 (NFRs) |
| Feeds into | 06 — API Contract §1 (consumers) |

---

## 1. Purpose

Push server-initiated updates (stock level changes, order status, pick-list assignment)
to connected browsers without polling. This is a separate Node.js process, not a Laravel
queue worker, because holding thousands of open WebSocket connections inside PHP-FPM
workers does not scale the same way and would compete with request handling for the
same process pool.

## 2. Position in the architecture

```
Laravel (PHP)                         Node.js gateway (TS)
─────────────                         ────────────────────
domain event fires
  ↓ DB::afterCommit()
PUBLISH redis channel  ─────────────▶  SUBSCRIBE redis channel
"realtime-events"                      │
                                        ▼
                                   io.to(room).emit(event, payload)
                                        │
                                        ▼
                                   browser (Inertia + React,
                                   socket.io-client)
```

The gateway is **stateless and holds no domain data.** It never reads from or writes to
PostgreSQL, and never calls back into the Laravel API. Its only inputs are Redis
messages; its only output is a Socket.io emit. If it crashes and restarts, it loses
nothing — clients simply reconnect and re-join their rooms.

## 3. Publish contract

Laravel publishes a JSON envelope to the `REDIS_REALTIME_CHANNEL` (default
`realtime-events`) **after** the owning transaction commits — never from inside an
`AllocationService`/`DeallocationService` transaction, per CLAUDE.md invariant 6 (no
external call while holding `stock_levels` locks).

```json
{
  "room": "company:01J8QK...",
  "event": "stock_level.changed",
  "payload": {
    "sku_id": "01J8QK...",
    "location_id": "01J8QK...",
    "available_base_qty": 288
  }
}
```

- `room` — a Socket.io room name. Convention: `{tenant-scope}:{id}`, e.g.
  `company:<ulid>` for a trade account, `warehouse:<location_id>` for warehouse staff.
  Rooms are how tenancy is enforced on the way out — the gateway never filters payload
  contents per-recipient, it only routes to the room the recipient joined.
- `event` — a stable string, mirrored 1:1 to the Socket.io event name delivered to
  the browser. Same naming convention as domain events (`{aggregate}.{action}`).
- `payload` — arbitrary JSON. Follows the same money/quantity suffix rules as the
  REST API (06 §3): `_e4`, `_minor`, `_base_qty` fields are never floats.

Publishing is fire-and-forget from Laravel's side. A dropped message (gateway
briefly down, Redis reconnect) means a client's view is stale until its next full
page load or an explicit refetch via TanStack Query — never a correctness gap, because
the gateway only ever pushes read-model conveniences. No client is allowed to treat a
Socket.io event as the record of truth for an action it initiated; the HTTP response
to that action is.

## 4. Subscribe / room contract

- A client joins a room by emitting `join` with the room name once it has resolved,
  from its own Inertia page props, which rooms it's entitled to (its own `company:<id>`,
  its assigned `warehouse:<location_id>`, etc.). The gateway does not authorize room
  membership — see §6.
- A client emits `leave` on unmount/navigation away from a view that needed that room.
- The gateway does not track which user is in which room beyond Socket.io's own
  connection state; there is no separate presence store.

## 5. Redis Pub/Sub, not Redis Streams

Pub/Sub is chosen deliberately over Streams or a queue: messages are UI conveniences,
not work items. There is nothing to acknowledge, retry, or replay — a missed message
is superseded by the next state-changing event anyway. If a future requirement needs
guaranteed delivery or replay (e.g. an audit trail of pushes), that is a different,
additive mechanism, not a change to this one.

## 6. Authentication and authorisation

**Open question, blocking before this ships to any client carrying real tenant data.**
The gateway process has no access to Laravel's session store or Sanctum tokens today.
Candidate approach: the browser fetches a short-lived, room-scoped signed token from a
Laravel endpoint (reusing existing Policy checks) and presents it on Socket.io connect;
the gateway verifies the signature and the room claim, never re-deriving authorisation
itself. Not yet implemented — do not join a client to a room carrying another tenant's
data until this is resolved.

## 7. Deployment

- Runs as its own process (`services/realtime-gateway/`), independently deployable and
  scalable from the Laravel app. Horizontal scaling requires either Socket.io's Redis
  adapter (sticky sessions become unnecessary) or sticky load balancing — not yet
  decided, tracked as an open question alongside §6.
- `GET /health` returns `{"status":"ok"}` for load balancer / orchestrator health checks.
- Environment: `PORT`, `REDIS_URL`, `REDIS_REALTIME_CHANNEL`, `SOCKET_CORS_ORIGIN`.

## 8. Open questions

| # | Question | Blocking? |
|---|---|---|
| 1 | Room-join authorisation (§6) | **Yes** — before any real tenant data flows through a room |
| 2 | Horizontal scaling strategy (Redis adapter vs. sticky sessions) | No — single instance sufficient at launch volume |
| 3 | Whether any event ever needs guaranteed delivery (→ Streams instead of Pub/Sub) | No, none identified yet |
