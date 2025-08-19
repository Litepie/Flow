<?php

namespace Litepie\Flow\Models;

use Illuminate\Database\Eloquent\Model;

class PendingTransition extends Model
{
    protected $table = 'flow_pending_transitions';

    protected $fillable = [
        'model_type',
        'model_id',
        'field', 
        'from',
        'to',
        'transition',
        'custom_properties',
        'causer_type',
        'causer_id',
        'apply_at',
        'applied_at'
    ];

    protected $casts = [
        'custom_properties' => 'array',
        'apply_at' => 'datetime',
        'applied_at' => 'datetime'
    ];

    public function model(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): \Illuminate\Database\Eloquent\Relations\MorphTo
    {
        return $this->morphTo();
    }

    public function scopeForField($query, string $field)
    {
        return $query->where('field', $field);
    }

    public function scopeNotApplied($query)
    {
        return $query->whereNull('applied_at');
    }

    public function scopeApplied($query)
    {
        return $query->whereNotNull('applied_at');
    }

    public function scopeReady($query)
    {
        return $query->where('apply_at', '<=', now())->whereNull('applied_at');
    }

    public function isReady(): bool
    {
        return $this->apply_at <= now() && is_null($this->applied_at);
    }

    public function markAsApplied(): void
    {
        $this->update(['applied_at' => now()]);
    }

    public function getCustomProperty(string $key)
    {
        return data_get($this->custom_properties, $key);
    }

    public function allCustomProperties(): array
    {
        return $this->custom_properties ?? [];
    }
}
