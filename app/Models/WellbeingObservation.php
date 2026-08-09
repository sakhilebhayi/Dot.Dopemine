<?php

namespace App\Models;

use Database\Factories\WellbeingObservationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One recorded aggregate wellbeing measurement for a mechanic's cohort
 * over a window (wiki.md §4 "Wellbeing observation"). Surfaced as
 * read-only review context alongside decoupling findings — never folded
 * into the coupling_rate formula itself (wiki.md's worked example treats
 * coupling and the wellbeing guard as two independent signals).
 */
class WellbeingObservation extends Model
{
    /** @use HasFactory<WellbeingObservationFactory> */
    use HasFactory;

    protected $fillable = [
        'mechanic_id',
        'cohort',
        'window_start',
        'window_end',
        'cohort_size',
        'wellbeing_movement',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'window_start' => 'date',
            'window_end' => 'date',
            'cohort_size' => 'integer',
            'wellbeing_movement' => 'decimal:4',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WellbeingObservation $observation) {
            if ($observation->cohort_size < 50) {
                throw new RuntimeException(
                    'Wellbeing observations must be aggregate, n >= 50 — never individual-level (wiki.md §4).'
                );
            }
        });
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
