<?php

namespace App\Models;

use App\Enums\DeploymentStatus;
use Database\Factories\MechanicDeploymentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * Records that a specific team is using a specific certified Mechanic
 * (wiki.md §4 "Deployment"). Team-scoped via `team_id`.
 *
 * Ethics gate continuation: a deployment can only be created against a
 * Mechanic whose status is Certified — see the `creating` listener below.
 * This closes the loop opened in Mechanic::booted(): certification is
 * required to reach the catalog, and now it's also required to be *used*.
 */
class MechanicDeployment extends Model
{
    /** @use HasFactory<MechanicDeploymentFactory> */
    use HasFactory;

    protected $fillable = [
        'team_id',
        'mechanic_id',
        'status',
        'started_at',
        'retired_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'status' => DeploymentStatus::class,
            'started_at' => 'datetime',
            'retired_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (MechanicDeployment $deployment) {
            $mechanic = $deployment->mechanic ?? Mechanic::find($deployment->mechanic_id);

            if (! $mechanic || ! $mechanic->isCertified()) {
                throw new RuntimeException(
                    'A team may only deploy a certified mechanic (wiki.md §3 "certified mechanics only").'
                );
            }
        });
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(Mechanic::class);
    }
}
