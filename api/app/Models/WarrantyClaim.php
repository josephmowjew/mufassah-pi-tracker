<?php

namespace App\Models;

use App\Enums\WarrantyClaimStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class WarrantyClaim extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'warranty_id',
        'claim_number',
        'status',
        'issue_description',
        'issue_category',
        'evidence_photos',
        'processed_by',
        'processed_at',
        'resolution_notes',
        'resolution_type',
        'repair_cost',
    ];

    protected function casts(): array
    {
        return [
            'status' => WarrantyClaimStatus::class,
            'evidence_photos' => 'array',
            'processed_at' => 'datetime',
            'repair_cost' => 'decimal:2',
        ];
    }

    public function warranty(): BelongsTo
    {
        return $this->belongsTo(Warranty::class);
    }

    public function processor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'processed_by');
    }
}
