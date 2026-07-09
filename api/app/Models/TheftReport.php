<?php

namespace App\Models;

use App\Enums\TheftReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class TheftReport extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'device_id',
        'reported_by',
        'status',
        'theft_date',
        'theft_location',
        'theft_latitude',
        'theft_longitude',
        'description',
        'police_report_number',
        'police_station',
        'officer_name',
        'officer_contact',
        'evidence_photos',
        'assigned_to',
        'recovered_at',
        'recovery_location',
        'recovery_notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => TheftReportStatus::class,
            'theft_date' => 'datetime',
            'theft_latitude' => 'decimal:8',
            'theft_longitude' => 'decimal:8',
            'evidence_photos' => 'array',
            'recovered_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function reporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reported_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function updates(): HasMany
    {
        return $this->hasMany(TheftReportUpdate::class);
    }

    public function scopeOpen($query)
    {
        return $query->whereIn('status', [
            TheftReportStatus::Reported,
            TheftReportStatus::Investigating,
        ]);
    }
}
