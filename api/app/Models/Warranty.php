<?php

namespace App\Models;

use App\Enums\WarrantyStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Warranty extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'warranty_number',
        'status',
        'start_date',
        'end_date',
        'duration_months',
        'coverage_details',
        'terms_conditions',
        'provider',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => WarrantyStatus::class,
            'start_date' => 'date',
            'end_date' => 'date',
            'terms_conditions' => 'array',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function claims(): HasMany
    {
        return $this->hasMany(WarrantyClaim::class);
    }

    public function isActive(): bool
    {
        return $this->status === WarrantyStatus::Active && $this->end_date->isFuture();
    }

    public function scopeActive($query)
    {
        return $query->where('status', WarrantyStatus::Active)
            ->where('end_date', '>=', now());
    }
}
