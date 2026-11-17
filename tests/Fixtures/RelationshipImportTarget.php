<?php

namespace SmartImport\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class RelationshipImportTarget extends Model
{
    protected $table = 'relationship_import_targets';

    public function customer(): BelongsTo
    {
        return $this->belongsTo(RelationshipCustomer::class, 'customer_id')->where('active', true);
    }

    public function customers(): HasMany
    {
        return $this->hasMany(RelationshipCustomer::class, 'customer_id');
    }
}
