<?php

namespace Litepie\Flow\Models;

use Illuminate\Database\Eloquent\Model;

class StateHistory extends Model
{
    protected $table = 'flow_state_histories';

    protected $fillable = [
        'model_type',
        'model_id', 
        'field',
        'from',
        'to',
        'transition',
        'custom_properties',
        'causer_type',
        'causer_id'
    ];

    protected $casts = [
        'custom_properties' => 'array'
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

    public function scopeTo($query, string $state)
    {
        return $query->where('to', $state);
    }

    public function scopeFrom($query, string $state)
    {
        return $query->where('from', $state);
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
