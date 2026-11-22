# Importer definitions

Import definitions are registered in `smart-import.importers` and are used by the Hub and Laravel services. The target model and field list are application code; spreadsheet contents cannot choose a model, relationship method, or database column.

## Resolving a belongs-to reference

Add an optional `RelationshipLookup` to the `ImportField` that represents the target model's foreign-key column:

```php
use SmartImport\Data\ImportField;
use SmartImport\Data\RelationshipLookup;

new ImportField(
    'customer_id',
    'Customer',
    type: 'integer',
    required: true,
    aliases: ['Customer email'],
    transformers: ['trim'],
    relationshipLookup: new RelationshipLookup(
        relationMethod: 'customer',
        lookupColumn: 'email',
    ),
)
```

The target model must define a public Eloquent `belongsTo` method named `customer`, and its foreign key must be `customer_id`. The lookup column is a single, simple column identifier in the related model. The importer registry validates the relation and foreign-key pairing when it registers the definition.

The mapped source value remains available in `mapped_values`. After resolution, `normalized_values['customer_id']` contains the related model's owner key. A missing reference produces `relationship_not_found`; multiple matches produce `relationship_ambiguous`. Both errors include the source row and a safe message without echoing the source value. A failed reference is omitted from normalized values, so an invalid row cannot accidentally write the original lookup text into the foreign key.

The resolver uses the Eloquent relation query, including its normal global scopes and relation constraints, and compares returned lookup values exactly. It processes each configured relation once per staging batch using a bounded `where in` query; the configured reader chunk size defaults to 250 rows. Use a lookup column whose stored value has the same textual form as the source. For tenant isolation, prefer related-model global scopes or relation constraints that do not depend on unsaved attributes of a new target model.

If the source field is blank or null, no relationship query runs. Required-field and other importer validation rules still apply. Relationship descriptors are optional; existing definitions without them keep their current behavior.

## Existing target records

`ImportDefinition::uniqueKeyFields()` declares the allowlisted target key used for matching existing records. An empty list disables database matching. A non-empty list may contain one field or a composite key; every field must be defined by the importer and use a simple target column name. The match uses all key fields together and the target model's Eloquent query, so its global scopes (including host tenant scopes) apply.

During staging, complete keys are classified as `new` when there is no match, `matched` when exactly one target row exists, or `ambiguous` when multiple target rows exist. A matched row stores its target model type and ID for later preview. An ambiguous match is invalid and receives a safe field error. These are detection results; the later import mode decides whether a unique match will be updated, skipped, or handled another way. Incomplete keys remain unresolved and do not trigger a database query. Matching reruns when an operator edits a staged key. The execution stage will recheck the target before writing, because target data can change after staging.

Add a database index or unique constraint for commonly matched target keys in the host application. A package lookup cannot prevent another transaction from changing the target after staging, so execution must recheck the match before applying a write.

## Write mode and conflict behavior

Each importer returns an explicit `ImportWritePolicy`. Its mode and any relevant conflict behavior are part of the importer definition, so increment `version()` when they change.

```php
use SmartImport\Data\ImportWritePolicy;
use SmartImport\Enums\ImportMode;
use SmartImport\Enums\WriteConflictPolicy;

public function writePolicy(): ImportWritePolicy
{
    return new ImportWritePolicy(
        ImportMode::Upsert,
    );
}
```

`upsert` updates one matched record and creates a complete new key. `insert` creates a record; when a configured key matches, `insertConflict` must explicitly choose `Skip` or `Fail`. `update` requires a unique key and `missingUpdateTarget` must explicitly choose `Skip` or `Fail` when a complete key has no match. `skip` creates a new key and skips an existing match. `update`, `upsert`, and `skip` require a configured unique key; ambiguous or unavailable matches fail closed for those modes. Insert mode can run without a matching key, but only the target database's constraints can detect a conflict in that case.

The write policy resolves the staged match classification into a proposed `create`, `update`, `skip`, or `fail` action and a reason code. It does not write target records. The preview and transactional execution stages consume the same policy; execution must recheck the target match to catch changes after staging.

## Optional lifecycle hooks

Definitions can opt into `ImportHooks` and use `HasImportHooks` to supply no-op defaults for hooks they do not need:

```php
use SmartImport\Contracts\ImportHooks;
use SmartImport\Data\ImportRowContext;
use SmartImport\Models\ImportSession;
use SmartImport\Traits\HasImportHooks;

final class ContactImport implements ImportDefinition, ImportHooks
{
    use HasImportHooks;

    public function beforeRowPersist(ImportRowContext $context): void
    {
        // Perform deterministic, same-database checks; throw to abort the row transaction.
    }

    public function afterRowPersist(ImportRowContext $context): void
    {
        // Dispatch an idempotent application job using the session/revision/row IDs.
    }
}
```

`beforeImport` and `afterImport` receive the session. The shared chunk lifecycle invokes `beforeImport` once when the first job moves the session from `queued` to `importing`; `afterImport` runs after the single terminal transition commits. `beforeImport` and `beforeRowPersist` run inside database transactions and should use only deterministic, same-connection database work. Do not send mail, call a remote API, or perform another external side effect from these hooks. Throwing from a before hook rolls back its state/row transaction so the queue layer can retry.

`afterRowPersist` is scheduled with Laravel's connection `afterCommit` callback and runs only for a committed create/update, not skip/fail rows or a repeated delivery. It receives an immutable `ImportRowContext` with identifiers, action, attempt ID, and normalized allowlisted values; it does not receive an Eloquent target instance. External effects cannot participate in the SQL transaction. Dispatch an idempotent queued job from this hook rather than performing network work directly, using `ImportRowContext::idempotencyKey()` (`sessionId:attemptId:rowId`) to distinguish a controlled retry from duplicate delivery. If the callback throws, the row remains committed; the package logs the exception class and identifiers, and does not mark the row failed or invoke the hook again during row redelivery. The host application owns durable side-effect retry and deduplication.

Lifecycle hooks are trusted application code. Keep them deterministic before commit, avoid logging row values, and never use them to assign fields outside the importer allowlist.

### Importer-defined operator choices

An importer can optionally implement `ImportOptionProvider` to offer a small allowlist of safe, non-secret choices in the shared mapping page and CLI. Each `ImportOption` declares a stable key, label, allowed string values, and default. Existing `ImportDefinition` classes do not need to change.

```php
use SmartImport\Contracts\ImportOptionProvider;
use SmartImport\Data\ImportOption;

final class ContactImportDefinition implements ImportDefinition, ImportOptionProvider
{
    public function importOptions(): array
    {
        return [new ImportOption(
            'source_label',
            'Source label',
            ['partner' => 'Partner feed', 'staff' => 'Staff upload'],
            'partner',
        )];
    }
}
```

The package validates selections against the registered importer, saves them with the staged revision, and validates them again when staging begins. The optional value is available to importer lifecycle hooks through `ImportSession::$options['importer']` and, for row hooks, `ImportRowContext::$importerOptions`. Use these choices for application-owned hook behavior; they do not change the importer's write mode, key fields, field allowlist, or blank-cell policy. Do not declare secrets or credentials as presettable options.

On each terminal state transition, the package emits a Laravel `SmartImport\Events\ImportFinished` event after commit with the session/attempt IDs, terminal status, and committed result counters. It remains a transition event and may report a technical failure that recovery later reopens. Listener exceptions are logged without changing persisted import state. Use the attempt ID for listener idempotency. For durable package-owned database notifications and a stable external delivery key, use the outbox described in [terminal notifications](notifications.md); outbox delivery does not emit `ImportFinished` again.

## Missing, blank, and explicit null values

An unmapped field or a missing source column is omitted from `normalized_values`. For a mapped blank cell (`null`, an empty string, or whitespace), `ImportField` defaults to `BlankCellPolicy::Preserve`, which also omits the field so an update leaves the stored value unchanged. Set `BlankCellPolicy::SetEmptyString` or `BlankCellPolicy::SetNull` when a blank should overwrite an existing value. This blank policy is applied before field transformers; non-blank values continue through the configured transformer list.

To deliberately clear a field while keeping accidental blanks unchanged, declare an exact marker on that field:

```php
use SmartImport\Data\ImportField;
use SmartImport\Enums\BlankCellPolicy;

new ImportField('phone', 'Phone', blankCellPolicy: BlankCellPolicy::SetNull);
new ImportField('notes', 'Notes', rules: ['nullable'], nullMarkers: ['[NULL]']);
```

The marker is recognized exactly before transformers. `mapped_values` retains the source marker for audit, while `normalized_values` contains the field with a `null` value. This is distinct from a blank preserved as an omitted field. Required validation still applies to missing, blank, and explicit-null values; optional fields that accept null should include Laravel's `nullable` rule.

## Database change preview

After staging reaches `review_ready`, call `ImportPreviewService::generate($sessionId, $actor)`. The Laravel-only core rechecks target matches in bounded batches, applies the declared write policy, builds before/after changes for the allowlisted fields, and persists each row's action, reason, target identifier, and changes. It returns counts for `create`, `update`, `skip`, and `fail`. Preview generation does not write target models. It is an estimate; target records can change afterward and execution must recheck them.

Read preview rows through `ImportPreviewService::rows($sessionId, $actor, page: 1, perPage: 50)`. It returns a paginated projection without raw or mapped spreadsheet values. Editing a staged row clears all saved previews, and a preview from an older mapping or staging revision cannot be read.

After reviewing a complete preview, call `ImportPreviewService::confirm($sessionId, $actor, $expectedStagingRevision)`. The actor must pass the `queue` Gate for that import session. Confirmation stores `confirmed_revision`, sets the session to `queued`, and freezes importer, mapping, sheet, staging, row input values, and reviewed row actions. It dispatches bounded execution jobs containing row IDs and the frozen revision. Preview rows remain readable while queued; target writes happen only in the asynchronous queue worker.

For unchanged detection, fields declared with `type: 'bool'` or `type: 'boolean'` use a narrow boolean comparison: `true`, `1`, and `'1'` are equivalent, as are `false`, `0`, and `'0'`. A target model's native Eloquent `bool`/`boolean` cast also enables this rule for that field. Import field metadata takes precedence when it explicitly declares a boolean; other field types keep the existing strict comparison. Values such as `'false'`, `null`, and `''` are not coerced by truthiness and remain distinct. Preview diffs and execution's unchanged check share this comparator. Target snapshot verification remains based on the exact stored target values. A preview already confirmed as an update keeps that reviewed action; this comparison only affects newly generated previews.

The shared `ImportExecutionService::processRow($sessionId, $rowId, $confirmedRevision)` operation executes one confirmed row. It reruns the shared mapping/transform/relationship/validation logic and rechecks duplicate keys and target matches before persisting. It writes only fields declared by the importer, and target changes, row outcome/history, and session counters commit in one transaction. Package session/row tables and the target model must use the same Laravel database connection. A mismatch is rejected before target writes because atomic commits cannot span connections. Queue adapters should dispatch identifiers and the confirmed revision, never model instances or row payloads.

Mark a field as sensitive to protect its before/after values in preview storage and responses:

```php
new ImportField('api_token', 'API token', sensitive: true);
```

Sensitive preview values are encrypted in the row's preview JSON using Laravel's configured encrypter. They are revealed only when the actor passes the optional `viewSensitiveImportFields` Gate ability with the import session and field as arguments. If that ability is absent or denies access, values are returned as `[redacted]`. Do not expose stored preview JSON directly; use the preview service so the field authorization is applied.

For example, a host can allow only operators with a dedicated ability to see the token field:

```php
Gate::define('viewSensitiveImportFields', function (User $user, ImportSession $session, ImportField $field): bool {
    return $field->name !== 'api_token' || $user->can('view-api-tokens');
});
```
