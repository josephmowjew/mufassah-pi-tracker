<?php

namespace App\Models;

use App\Enums\TheftReportStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TheftReportUpdate extends Model
{
    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'theft_report_id',
        'user_id',
        'old_status',
        'new_status',
        'notes',
        'is_public',
    ];

    protected function casts(): array
    {
        return [
            'old_status' => TheftReportStatus::class,
            'new_status' => TheftReportStatus::class,
            'is_public' => 'boolean',
            'created_at' => 'datetime',
        ];
    }

    public function theftReport(): BelongsTo
    {
        return $this->belongsTo(TheftReport::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
