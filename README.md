# sqrs

`sqrs` is a Docker-only PHP 8.4 / Symfony 8 billing API module. It implements user registration, payment token storage, subscription lifecycle operations, webhook intake, async webhook processing, and user event history.

No local PHP, Composer, Symfony CLI, Redis, SQLite, or PHPUnit setup is required. Everything runs inside Docker.

All commands must be executed through Docker Compose or the Rakefile. Do not run PHP, Composer, Symfony, PHPUnit, Redis, or SQLite locally.

## Architecture

**Overview:** The module uses lightweight Event Sourcing + CQRS and intentionally keeps the architecture small and explicit.

**Event Sourcing:** Important business facts are stored as events: user registration, subscription lifecycle changes, payment outcomes, and webhook intake. Billing behavior needs a reliable timeline of what happened, so these facts are appended to `events` instead of being represented only by the latest row state.

**CQRS:** History and reads are separated. `events` stores the timeline, while `subscriptions` stores the current subscription projection for fast API responses. This keeps `GET /users/{id}/subscription` simple without losing the ability to inspect `GET /users/{id}/events`.

**SQLite:** SQLite is the application database. It stores users, subscription projections, and events in `var/data/app.db`, which keeps the assignment easy to run inside Docker and easy to inspect during review.

**Redis Queue:** Redis is the queue backend for async webhook processing. `POST /webhooks/billing` records the received event in SQLite, dispatches a Messenger message to the `events` queue, and returns `202` without running the payment lifecycle synchronously.

**UUIDs:** UUIDs are used for internal identifiers everywhere: users, subscriptions, events, and aggregate IDs. This removes autoincrement coupling, makes IDs safe to generate in application code, and keeps API payloads stable if the storage engine changes later. External billing event IDs come from the provider contract and are stored in `events.external_event_id`.

**Event Timeline:** The `events` table is the append-only history source and audit trail. Each important fact is stored once as a domain event. `GET /users/{id}/events` reads directly from `events` and returns a user-friendly timeline.

**Webhook Intake:** Webhook intake also uses `events`. When `POST /webhooks/billing` receives a new external event, it writes a `WebhookReceived` event and dispatches a Messenger message. If the same `external_event_id` is received again before a terminal result exists, the handler reloads the original `WebhookReceived` event and dispatches the original type, user, period, and occurred time again. Retry payload changes are ignored. If a terminal result already exists for that external event, the handler returns `202` with `ignored`.

**Async Idempotency:** Async payment handlers are idempotent as well. Before changing the subscription projection, the worker checks whether `PaymentSucceeded` or `PaymentFailed` already exists for the same `external_event_id`. If one exists, the worker returns before changing the subscription again. Database constraints prevent duplicate event types and prevent conflicting payment results for one external event.

**Webhook Recovery:** Pending webhook work can be recovered from `events`. The recovery command scans `WebhookReceived` events that do not have a payment result and dispatches their original stored payload again. `rake worker` runs this recovery before starting the Messenger consumer.

**Webhook Failures:** Business failures are recorded as events. For example, a failed-payment webhook for a user without a subscription creates `WebhookProcessingFailed`, which makes the failure visible in `GET /users/{id}/events`. This failure event is not terminal: if the same external event is retried after a subscription exists, the payment failure can still be processed.

**Subscription Projection:** `subscriptions` is a projection/read model. It stores the current subscription state for fast `GET /users/{id}/subscription` responses. Lifecycle facts such as `SubscriptionStarted`, `SubscriptionCanceled`, `PaymentSucceeded`, and `SubscriptionExpired` are written to `events`.

**Transactions:** Projection updates and event writes happen together in database transactions, keeping the read model and event history consistent.

**Thin Controllers:** Controllers stay thin. They parse HTTP input, call command handlers, and return JSON. Validation, business rules, transactions, projection updates, event writes, and message dispatch live in command handlers.

**Fake Payments:** Payment integrations are fake by design. `payment_token` and webhook payloads imitate provider data inside this local module.

**Minimal Security:** The module does not implement user authentication, sessions, JWT, OAuth, or roles. Webhook intake uses a simple shared secret via `X-Webhook-Secret`, and user IDs are validated as UUIDs before repository lookup. `payment_token` is stored but not returned by the registration response.

**Database Tables:**

- `users`
- `subscriptions`
- `events`

## Runtime

Services:

- `app`: PHP 8.4 CLI container with Composer and Symfony app code mounted at `/app`.
- `redis`: `redis:alpine` used by Messenger.

SQLite database paths:

- Main database: `var/data/app.db`
- Test database: `var/data/test.db`

## Commands

Preferred Rake commands:

```bash
rake up
rake serve
rake install
rake migrate
rake test
rake recover
rake worker
rake bash
rake logs
rake "cmd[php bin/console debug:router]"
rake down
```

Equivalent Docker Compose examples:

```bash
docker compose exec app composer install
docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction
docker compose exec app php bin/phpunit
docker compose exec app php bin/console messenger:consume async -vv
```

Do not run these locally:

```bash
composer install
php bin/console doctrine:migrations:migrate
php bin/phpunit
symfony server:start
```

## Setup

```bash
rake up
rake install
```

The API is exposed at:

```text
http://localhost:8080
```

Run the async worker:

```bash
rake worker
```

Recover pending stored webhook events without starting the worker:

```bash
rake recover
```

Run tests:

```bash
rake test
```

## API Examples

Register a user:

```bash
curl -X POST http://localhost:8080/users \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","payment_token":"tok_test_123"}'
```

Subscribe:

```bash
curl -X POST http://localhost:8080/subscribe \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"018fc8b7-4f25-7d16-b63b-7d983ab2f87d","plan":"monthly"}'
```

Cancel:

```bash
curl -X POST http://localhost:8080/cancel \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"018fc8b7-4f25-7d16-b63b-7d983ab2f87d"}'
```

Resume:

```bash
curl -X POST http://localhost:8080/resume \
  -H 'Content-Type: application/json' \
  -d '{"user_id":"018fc8b7-4f25-7d16-b63b-7d983ab2f87d"}'
```

Read current subscription:

```bash
curl http://localhost:8080/users/018fc8b7-4f25-7d16-b63b-7d983ab2f87d/subscription
```

Read event history:

```bash
curl http://localhost:8080/users/018fc8b7-4f25-7d16-b63b-7d983ab2f87d/events
```

Receive webhook:

```bash
curl -X POST http://localhost:8080/webhooks/billing \
  -H 'Content-Type: application/json' \
  -H 'X-Webhook-Secret: test_webhook_secret' \
  -d '{
    "external_event_id": "evt_123",
    "type": "payment_success",
    "user_id": "018fc8b7-4f25-7d16-b63b-7d983ab2f87d",
    "period": "monthly",
    "occurred_at": "2026-05-25T18:00:00+00:00"
  }'
```

## Endpoints

- `POST /users`
- `POST /subscribe`
- `POST /cancel`
- `POST /resume`
- `GET /users/{id}/subscription`
- `GET /users/{id}/events`
- `POST /webhooks/billing`

All responses are JSON.
