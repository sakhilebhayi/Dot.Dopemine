<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A retirement-candidate proposal raised by
 * App\Console\Commands\ScanMechanicDecoupling when a certified mechanic's
 * engagement.outcome_coupling_rate crosses the decoupling threshold
 * (wiki.md §11, Dot.Brain audit's Level 2 candidate). `status` is one of
 * `open`, `confirmed`, `dismissed` — see MechanicCatalog's review actions.
 */
class MechanicRetirementCandidate extends Model
{
    protected $fillable = [
        'mechanic_id',
        'coupling_rate',
        'sample_size',
        'status',
        'detected_at',
        'reviewed_by',
        'review_notes',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'coupling_rate' => 'decimal:4',
            'sample_size' => 'integer',
            'detected_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class);
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
