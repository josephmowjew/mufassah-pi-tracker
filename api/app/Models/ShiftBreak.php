<?php

namespace App\Models;

use App\Enums\ShiftBreakType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A single break taken inside a shift.
 *
 * An open break (no `ended_at`) is what "on break" means — there is no status
 * on the parent shift that changes. A break is closed when the employee returns
 * or, failing that, when the shift itself is completed or cancelled.
 */
class ShiftBreak extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'shift_id',
        'type',
        'started_at',
        'ended_at',
        'notes',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => ShiftBreakType::class,
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
        ];
    }

    /**
     * Get the shift the break belongs to.
     */
    public function shift()
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * Whether the break has not been closed yet.
     */
    public function isOpen(): bool
    {
        return $this->ended_at === null;
    }

    /**
     * The length of the break in minutes.
     *
     * Returns null while the break is still open — a running break has no
     * length yet, and reporting it as elapsed-so-far would make the same row
     * mean different things on every read. Callers that need live elapsed time
     * compute it from `started_at` against the clock.
     *
     * Carbon 3 returns a signed difference, so the arguments must run
     * start -> end; the reverse produced negative durations for shifts.
     */
    public function getDurationMinutesAttribute(): ?float
    {
        if ($this->ended_at === null) {
            return null;
        }

        return max(0, round((float) $this->started_at->diffInMinutes($this->ended_at), 2));
    }
}
