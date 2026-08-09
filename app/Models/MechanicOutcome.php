<?php

namespace App\Models;

use Database\Factories\MechanicOutcomeFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One recorded (deployment, period) pair of engagement + outcome movement
 * (wiki.md §4 "Mechanic outcome"). Always paired — see the migration's
 * NOT NULL columns. Feeds App\Console\Commands\ScanMechanicDecoupling's
 * engagement.outcome_coupling_rate computation.
 */
class MechanicOutcome extends Model
{
    /** @use HasFactory<MechanicOutcomeFactory> */
    use HasFactory;

    protected $fillable = [
        'deployment_id',
        'period_start',
        'period_end',
        'engagement_movement',
        'outcome_movement',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'engagement_movement' => 'decimal:4',
            'outcome_movement' => 'decimal:4',
        ];
    }

    public function deployment(): BelongsTo
    {
        return $this->belongsTo(MechanicDeployment::class, 'deployment_id');
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
