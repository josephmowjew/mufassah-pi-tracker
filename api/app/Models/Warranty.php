<?php

namespace App\Models;

use App\Enums\WarrantyStatus;
use App\Support\LocalTime;
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
        $today = LocalTime::now()->toDateString();

        return $this->status === WarrantyStatus::Active
            && $this->start_date !== null && $this->end_date !== null
            && $this->start_date->toDateString() <= $today
            && $this->end_date->toDateString() >= $today;
    }

    /** Calendar days still covered, including today and the closing day. */
    public function daysRemaining(): int
    {
        if (! $this->isActive()) {
            return 0;
        }

        $today = LocalTime::now()->startOfDay();
        $lastDay = $today->copy()->setDate($this->end_date->year, $this->end_date->month, $this->end_date->day);

        return max(0, (int) $today->diffInDays($lastDay) + 1);
    }

    public function scopeActive($query)
    {
        $today = LocalTime::now()->toDateString();

        return $query->where('status', WarrantyStatus::Active)
            ->whereDate('start_date', '<=', $today)
            ->whereDate('end_date', '>=', $today);
    }
}
