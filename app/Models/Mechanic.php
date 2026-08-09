<?php

namespace App\Models;

use App\Enums\MechanicCategory;
use App\Enums\MechanicStatus;
use Database\Factories\MechanicFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

/**
 * A single entry in the global Mechanic Catalog (wiki.md §3, §4).
 *
 * Global/shared, NOT team-scoped — see migration 2026_08_01_120001 for the
 * tenancy rationale (same reasoning as Dot.Design's shared token library).
 *
 * Ethics gate, enforced at two layers:
 *  1. Structural: `category` casts to the fixed App\Enums\MechanicCategory
 *     backed enum, so the database column can never hold a value outside
 *     that allow-list — a loss-framed or FOMO category cannot be constructed.
 *  2. Behavioral: the `saving` listener below refuses to persist a mechanic
 *     with status `certified` unless `acid_test_passed` is true. This holds
 *     regardless of entry point (factory, seeder, tinker, mass assignment) —
 *     it is not merely a rule enforced by the CertifyMechanic action class,
 *     though that action is still the intended path (see its docblock).
 */
class Mechanic extends Model
{
    /** @use HasFactory<MechanicFactory> */
    use HasFactory;

    protected $fillable = [
        'key',
        'name',
        'description',
        'category',
        'status',
        'acid_test_passed',
        'acid_test_notes',
        'certified_by',
        'certified_at',
        'decertification_reason',
        'decertified_at',
    ];

    protected function casts(): array
    {
        return [
            'category' => MechanicCategory::class,
            'status' => MechanicStatus::class,
            'acid_test_passed' => 'boolean',
            'certified_at' => 'datetime',
            'decertified_at' => 'datetime',
            'coupling_rate' => 'decimal:4',
            'coupling_rate_computed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Mechanic $mechanic) {
            if ($mechanic->status === MechanicStatus::Certified && ! $mechanic->acid_test_passed) {
                throw new RuntimeException(
                    "Mechanic [{$mechanic->key}] cannot be certified without a recorded, passing acid-test verdict (wiki.md §2)."
                );
            }
        });
    }

    public function certifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'certified_by');
    }

    public function deployments(): HasMany
    {
        return $this->hasMany(MechanicDeployment::class);
    }

    public function activeDeployments(): HasMany
    {
        return $this->deployments()->where('status', 'active');
    }

    public function wellbeingObservations(): HasMany
    {
        return $this->hasMany(WellbeingObservation::class);
    }

    public function isCertified(): bool
    {
        return $this->status === MechanicStatus::Certified;
    }
}
