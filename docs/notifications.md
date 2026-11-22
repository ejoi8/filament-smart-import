# Terminal notifications and outbox delivery

Smart Import records terminal delivery evidence in the same database transaction as the import status. This covers completed and cancelled imports, and final failures after the recovery service has decided not to reopen the attempt.

## Delivery guarantees

- The database outbox record is durable. Its after-commit queue job is a prompt-delivery optimization; the relay command can find pending records if dispatch or a worker was interrupted.
- One internal database notification is stored per outbox event, recipient, and channel. A unique database constraint prevents duplicate notification rows during redelivery.
- External `ImportOutboxAvailable` listeners receive a stable `eventKey` and a status/counter payload. Delivery is **at least once**: listeners must deduplicate side effects using that key.
- A worker failure that recovery may retry creates a deferred failure record. Recovery supersedes that record when it reopens the attempt. If recovery gives up, it releases the deferred record. A later attempt has its own event identity.
- The outbox relay only delivers notifications; it never runs staged row writes.
- Notification payloads contain no spreadsheet cells, source paths, raw exceptions, or target field values.

`ImportFinished` remains a compatibility event for each persisted terminal transition, including an intermediate technical failure later recovered. Existing listeners are not called again by outbox delivery. Treat it as a transition event, and use the outbox when durable delivery and a stable event key are required.

## Configure recipients

The default `InitiatingActorNotificationRecipientResolver` creates a database notification only when the initiating actor is an existing Eloquent authenticatable model. For team or tenant recipients, configure a class implementing `ImportNotificationRecipientResolver`:

```php
'notifications' => [
    'recipient_resolver' => App\SmartImport\TeamImportRecipients::class,
    'lease_seconds' => 300,
    'max_attempts' => 8,
    'backoff_seconds' => [30, 120, 300],
],
```

The resolver receives the persisted `ImportSession` inside the configured import execution context. Return `ImportNotificationRecipient` values from trusted application state; do not use a recipient type or ID from spreadsheet input. Tenant-aware applications must configure the same execution context for web and queue processes.

The package does not send email or provide a notification bell. Hosts can read and mark the internal records through `ImportNotificationService`:

```php
$notifications = app(ImportNotificationService::class)->latestFor($authenticatedActor);
app(ImportNotificationService::class)->markRead($notificationId, $authenticatedActor);
```

Both methods constrain the record query to that actor. Use each notification's `import_id` with the application's normal authorized Hub route; do not link directly to a session without checking its session scope and `view` permission.

## Run delivery

The after-commit job is sent to the configured Smart Import queue. Run a normal Laravel worker as described in [queue worker operation](queue-workers.md). Also run a bounded outbox scan periodically so an event remains deliverable after a process stops between its database commit and queue dispatch:

```bash
php artisan smart-import:deliver-outbox --limit=100
```

Schedule the command in the host application if unattended relay recovery is required. `--limit` accepts 1–500. The command reports the number dispatched and the current deferred/failed counts as JSON. It does not deliver inline; a queue worker must process the resulting jobs.

After fixing a persistent listener or queue configuration error, an operator can reset a bounded page of failed deliveries:

```bash
php artisan smart-import:deliver-outbox --limit=100 --retry-failed
```

This retries notification delivery only. It does not reset an import session or rerun target writes. Monitor the reported failed count and application logs; logs identify the import/event and exception class without logging spreadsheet values.
