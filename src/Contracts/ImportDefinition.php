<?php

namespace SmartImport\Contracts;

use Illuminate\Database\Eloquent\Model;
use SmartImport\Data\ImportField;
use SmartImport\Data\ImportWritePolicy;

interface ImportDefinition
{
    /**
     * Return the stable, application-defined key used by every package adapter.
     */
    public function key(): string;

    /**
     * Return a stable version for mapping and processing compatibility.
     * Increment it when fields, aliases, validation, transforms or write semantics change.
     */
    public function version(): string;

    /** Return the human-readable name shown for this importer. */
    public function label(): string;

    /**
     * Return the concrete Eloquent target model.
     *
     * @return class-string<Model>
     */
    public function targetModel(): string;

    /**
     * Return the complete allowlist of fields this importer may write.
     *
     * @return list<ImportField>
     */
    public function fields(): array;

    /**
     * Return the optional composite key used to flag duplicate rows and later match target records.
     * Return an empty list when this importer has no configured unique key.
     *
     * @return list<string> Canonical field names, in key order.
     */
    public function uniqueKeyFields(): array;

    /** Return the explicit write mode and conflict behavior for this importer. */
    public function writePolicy(): ImportWritePolicy;
}
