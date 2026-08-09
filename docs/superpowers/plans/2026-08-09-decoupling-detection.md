# Engagement/Outcome Decoupling Detection Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Implement wiki.md §4's two missing domain entities (`MechanicOutcome`, `WellbeingObservation`), then build this platform's first scheduled job — `dopemine:scan-decoupling` — computing `engagement.outcome_coupling_rate` per certified mechanic (Level 1) and raising a `canGovern()`-gated retirement-candidate proposal on detected decoupling (Level 2).

**Architecture:** Two new append-only ledger models recorded by humans (no live telemetry pipeline exists anywhere in this ecosystem yet) feed a daily scheduled command. The command writes a computed `coupling_rate` onto each certified `Mechanic` (pure reporting, no mutation) and, when a mechanic's rate crosses a decoupling threshold, writes a `MechanicRetirementCandidate` proposal that only a `canGovern()` admin can act on — confirming calls the existing, unchanged `DecertifyMechanic` action; dismissing leaves certification untouched.

**Tech Stack:** Laravel 13.8, PHP 8.3+, Livewire 3.6, PHPUnit, Jetstream Teams.

## Global Constraints

- `MechanicOutcome.engagement_movement` and `.outcome_movement` are both non-nullable columns — no schema-level way to record one without the other (spec §1, implements roadmap's "reject-at-ingestion for unpaired engagement metrics").
- `WellbeingObservation` rejects `cohort_size < 50` via a `static::saving()` listener that throws `RuntimeException`, mirroring `Mechanic::booted()`'s existing acid-test gate pattern exactly (spec §1, wiki.md §4 "Aggregate only, n ≥ 50 — never individual-level").
- `MechanicOutcome` recording authority: any member of the deployment's own team (same bar as `MechanicDeployments::retire()` — no new role).
- `WellbeingObservation` recording authority, and all retirement-candidate review actions: `MechanicCatalog::canGovern()` — the existing team-`admin` bar already gating certify/decertify. No new authority concept anywhere in this feature.
- Level 1 (`coupling_rate` computation) performs no mutation to certification status. Level 2 (`MechanicRetirementCandidate`) only ever proposes; decertification still requires an explicit admin "Confirm" click that runs the existing `DecertifyMechanic` action unchanged.
- A record is "coupled" unless `engagement_movement > 0 AND outcome_movement <= 0` (spec §3 — the exact "engagement-up/outcome-flat" pattern named throughout wiki.md).
- Decoupling threshold: `sample_size >= 2 AND coupling_rate < 0.5` (spec §4).
- An already-`open` `MechanicRetirementCandidate` refreshes `coupling_rate`/`sample_size` on rescan but preserves its original `detected_at` — never duplicates, never auto-clears (spec §4).
- Run `vendor/bin/pint --dirty --format agent` after any PHP file changes (per this repo's CLAUDE.md).
- Run `php artisan test --compact <file>` after each task; run the full suite only in the final task.

---

## Task 1: `MechanicOutcome` ledger

**Files:**
- Create: `database/migrations/2026_08_09_130001_create_mechanic_outcomes_table.php`
- Create: `app/Models/MechanicOutcome.php`
- Create: `database/factories/MechanicOutcomeFactory.php`
- Test: `tests/Feature/Dopemine/MechanicOutcomeTest.php`

**Interfaces:**
- Produces: `App\Models\MechanicOutcome` with fillable `deployment_id, period_start, period_end, engagement_movement, outcome_movement, recorded_by, notes`; relations `deployment(): BelongsTo` (to `MechanicDeployment`), `recordedBy(): BelongsTo` (to `User` via `recorded_by`). `MechanicDeploymentFactory` and `UserFactory` already exist and are consumed here.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The paired engagement/outcome ledger (wiki.md §3 "Wellbeing & Outcome
 * Ledger", §4 "Mechanic outcome", natural key: deployment + period).
 *
 * `engagement_movement` and `outcome_movement` are both NOT NULL — there is
 * no schema-level way to record one without the other. This is the literal
 * implementation of the Roadmap's "reject-at-ingestion for unpaired
 * engagement metrics" (wiki.md §9): the column itself refuses a null, not
 * just a validator that could be bypassed.
 *
 * No team_id of its own: always created/queried through an already
 * team-scoped MechanicDeployment (App\Models\Concerns\HasTeamScope), so a
 * redundant scope column would duplicate authority that already exists one
 * hop away — see app/Livewire/MechanicDeployments.php.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('deployment_id')->constrained('mechanic_deployments')->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('engagement_movement', 8, 4);
            $table->decimal('outcome_movement', 8, 4);
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['deployment_id', 'period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_outcomes');
    }
};
```

- [ ] **Step 2: Write the model**

```php
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
```

- [ ] **Step 3: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MechanicOutcome>
 */
class MechanicOutcomeFactory extends Factory
{
    public function definition(): array
    {
        $periodStart = $this->faker->dateTimeBetween('-6 months', '-1 month');

        return [
            'deployment_id' => MechanicDeployment::factory(),
            'period_start' => $periodStart,
            'period_end' => (clone $periodStart)->modify('+1 month'),
            'engagement_movement' => $this->faker->randomFloat(4, -0.5, 0.5),
            'outcome_movement' => $this->faker->randomFloat(4, -0.5, 0.5),
            'recorded_by' => User::factory(),
            'notes' => null,
        ];
    }

    /**
     * Engagement rose, outcome did not — the exact failure mode this
     * feature exists to detect (wiki.md §2, §10, §11).
     */
    public function decoupled(): static
    {
        return $this->state(fn () => [
            'engagement_movement' => $this->faker->randomFloat(4, 0.05, 0.5),
            'outcome_movement' => $this->faker->randomFloat(4, -0.3, 0),
        ]);
    }

    /**
     * Engagement and outcome both rose together — the healthy case.
     */
    public function coupled(): static
    {
        return $this->state(fn () => [
            'engagement_movement' => $this->faker->randomFloat(4, 0.05, 0.5),
            'outcome_movement' => $this->faker->randomFloat(4, 0.05, 0.5),
        ]);
    }
}
```

- [ ] **Step 4: Write the failing tests**

```php
<?php

namespace Tests\Feature\Dopemine;

use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MechanicOutcomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_outcome_record_requires_both_engagement_and_outcome_movement(): void
    {
        $deployment = MechanicDeployment::factory()->create();
        $recorder = User::factory()->create();

        $this->expectException(QueryException::class);

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.12,
            // outcome_movement omitted — must be rejected at the schema level
            'recorded_by' => $recorder->id,
        ]);
    }

    public function test_a_paired_outcome_record_can_be_created(): void
    {
        $deployment = MechanicDeployment::factory()->create();
        $recorder = User::factory()->create();

        $outcome = MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.12,
            'outcome_movement' => -0.03,
            'recorded_by' => $recorder->id,
        ]);

        $this->assertDatabaseHas('mechanic_outcomes', [
            'id' => $outcome->id,
            'deployment_id' => $deployment->id,
        ]);
        $this->assertSame($deployment->id, $outcome->deployment->id);
        $this->assertSame($recorder->id, $outcome->recordedBy->id);
    }

    public function test_the_same_deployment_and_period_cannot_be_recorded_twice(): void
    {
        $deployment = MechanicDeployment::factory()->create();
        $recorder = User::factory()->create();

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.1,
            'outcome_movement' => 0.1,
            'recorded_by' => $recorder->id,
        ]);

        $this->expectException(QueryException::class);

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'engagement_movement' => 0.2,
            'outcome_movement' => 0.2,
            'recorded_by' => $recorder->id,
        ]);
    }

    public function test_a_deployment_can_have_multiple_outcome_records_across_periods(): void
    {
        $deployment = MechanicDeployment::factory()->create();

        MechanicOutcome::factory()->for($deployment, 'deployment')->create([
            'period_start' => '2026-05-01',
            'period_end' => '2026-05-31',
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->create([
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
        ]);

        $this->assertSame(2, MechanicOutcome::where('deployment_id', $deployment->id)->count());
    }
}
```

- [ ] **Step 5: Run tests to verify they fail (model/migration don't exist yet)**

Run: `php artisan test --compact tests/Feature/Dopemine/MechanicOutcomeTest.php`
Expected: FAIL — class `App\Models\MechanicOutcome` not found (or table missing).

- [ ] **Step 6: Run migration and re-run tests**

```bash
php artisan migrate
php artisan test --compact tests/Feature/Dopemine/MechanicOutcomeTest.php
```
Expected: 4 passed.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_09_130001_create_mechanic_outcomes_table.php app/Models/MechanicOutcome.php database/factories/MechanicOutcomeFactory.php tests/Feature/Dopemine/MechanicOutcomeTest.php
git commit -m "feat(dopemine): add MechanicOutcome ledger (deployment+period, paired movements)"
```

---

## Task 2: `WellbeingObservation` ledger

**Files:**
- Create: `database/migrations/2026_08_09_130002_create_wellbeing_observations_table.php`
- Create: `app/Models/WellbeingObservation.php`
- Create: `database/factories/WellbeingObservationFactory.php`
- Modify: `app/Models/Mechanic.php` (add `wellbeingObservations()` relation)
- Test: `tests/Feature/Dopemine/WellbeingObservationTest.php`

**Interfaces:**
- Consumes: `App\Models\Mechanic` (Task predates this plan), `App\Models\User`.
- Produces: `App\Models\WellbeingObservation` with fillable `mechanic_id, cohort, window_start, window_end, cohort_size, wellbeing_movement, recorded_by, notes`; relations `mechanic(): BelongsTo`, `recordedBy(): BelongsTo`. `Mechanic::wellbeingObservations(): HasMany`, consumed later by Task 6's retirement-candidate review context.

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The aggregate wellbeing guard (wiki.md §4 "Wellbeing observation",
 * natural key: mechanic + cohort + window). `cohort_size` is enforced
 * >= 50 at the model layer (App\Models\WellbeingObservation::booted()) —
 * wiki.md's "Aggregate only, n >= 50 — never individual-level" rule,
 * the fourth layer of this codebase's structural ethics gate alongside
 * the MechanicCategory enum, Mechanic::booted(), and
 * MechanicDeployment::booted().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wellbeing_observations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mechanic_id')->constrained()->cascadeOnDelete();
            $table->string('cohort');
            $table->date('window_start');
            $table->date('window_end');
            $table->unsignedInteger('cohort_size');
            $table->decimal('wellbeing_movement', 8, 4);
            $table->foreignId('recorded_by')->constrained('users')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['mechanic_id', 'cohort', 'window_start', 'window_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wellbeing_observations');
    }
};
```

- [ ] **Step 2: Write the model**

```php
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
```

- [ ] **Step 3: Add the inverse relation to `Mechanic`**

In `app/Models/Mechanic.php`, add this import:

```php
use Illuminate\Database\Eloquent\Relations\HasMany;
```

(Already imported — `deployments()` uses it. Skip if already present.)

Add this method, next to `activeDeployments()`:

```php
    public function wellbeingObservations(): HasMany
    {
        return $this->hasMany(WellbeingObservation::class);
    }
```

- [ ] **Step 4: Write the factory**

```php
<?php

namespace Database\Factories;

use App\Models\Mechanic;
use App\Models\User;
use App\Models\WellbeingObservation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WellbeingObservation>
 */
class WellbeingObservationFactory extends Factory
{
    public function definition(): array
    {
        $windowStart = $this->faker->dateTimeBetween('-6 months', '-1 month');

        return [
            'mechanic_id' => Mechanic::factory()->certified(),
            'cohort' => $this->faker->company().' — pilot cohort',
            'window_start' => $windowStart,
            'window_end' => (clone $windowStart)->modify('+1 month'),
            'cohort_size' => $this->faker->numberBetween(50, 500),
            'wellbeing_movement' => $this->faker->randomFloat(4, -0.2, 0.2),
            'recorded_by' => User::factory(),
            'notes' => null,
        ];
    }
}
```

- [ ] **Step 5: Write the failing tests**

```php
<?php

namespace Tests\Feature\Dopemine;

use App\Models\Mechanic;
use App\Models\User;
use App\Models\WellbeingObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\TestCase;

class WellbeingObservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_cohort_smaller_than_fifty_is_rejected(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        $this->expectException(RuntimeException::class);

        WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Small pilot group',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 49,
            'wellbeing_movement' => 0.01,
            'recorded_by' => $recorder->id,
        ]);
    }

    public function test_a_cohort_of_exactly_fifty_is_accepted(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        $observation = WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Exactly-floor cohort',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 50,
            'wellbeing_movement' => 0.01,
            'recorded_by' => $recorder->id,
        ]);

        $this->assertDatabaseHas('wellbeing_observations', ['id' => $observation->id, 'cohort_size' => 50]);
    }

    public function test_an_observation_belongs_to_its_mechanic_and_recorder(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        $observation = WellbeingObservation::factory()->for($mechanic)->create(['recorded_by' => $recorder->id]);

        $this->assertSame($mechanic->id, $observation->mechanic->id);
        $this->assertSame($recorder->id, $observation->recordedBy->id);
        $this->assertTrue($mechanic->wellbeingObservations->contains($observation));
    }

    public function test_the_same_mechanic_cohort_and_window_cannot_be_recorded_twice(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $recorder = User::factory()->create();

        WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Repeat cohort',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 60,
            'wellbeing_movement' => 0.01,
            'recorded_by' => $recorder->id,
        ]);

        $this->expectException(QueryException::class);

        WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Repeat cohort',
            'window_start' => '2026-06-01',
            'window_end' => '2026-06-30',
            'cohort_size' => 61,
            'wellbeing_movement' => 0.02,
            'recorded_by' => $recorder->id,
        ]);
    }
}
```

- [ ] **Step 6: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Dopemine/WellbeingObservationTest.php`
Expected: FAIL — class not found / table missing.

- [ ] **Step 7: Migrate and re-run**

```bash
php artisan migrate
php artisan test --compact tests/Feature/Dopemine/WellbeingObservationTest.php
```
Expected: 4 passed.

- [ ] **Step 8: Also re-run the existing ethics gate suite** (confirms the `Mechanic.php` edit didn't disturb the existing structural gate)

Run: `php artisan test --compact tests/Feature/Dopemine/EthicsGateTest.php`
Expected: all still passing.

- [ ] **Step 9: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_09_130002_create_wellbeing_observations_table.php app/Models/WellbeingObservation.php app/Models/Mechanic.php database/factories/WellbeingObservationFactory.php tests/Feature/Dopemine/WellbeingObservationTest.php
git commit -m "feat(dopemine): add WellbeingObservation ledger with n>=50 structural gate"
```

---

## Task 3: Record `MechanicOutcome` from `MechanicDeployments`

**Files:**
- Modify: `app/Livewire/MechanicDeployments.php`
- Modify: `resources/views/livewire/mechanic-deployments.blade.php`
- Test: `tests/Feature/Dopemine/MechanicOutcomeRecordingTest.php`

**Interfaces:**
- Consumes: `App\Models\MechanicOutcome::create()` (Task 1).
- Produces: `MechanicDeployments::startRecordingOutcome(int $deploymentId)`, `::cancelRecordingOutcome()`, `::saveOutcome()` — public Livewire actions; public properties `$recordingOutcomeId`, `$outcomePeriodStart`, `$outcomePeriodEnd`, `$outcomeEngagementMovement`, `$outcomeOutcomeMovement`, `$outcomeNotes`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicDeployments;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MechanicOutcomeRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_team_member_can_record_a_paired_outcome_for_their_own_deployment(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create([
            'team_id' => $user->currentTeam->id,
            'mechanic_id' => $mechanic->id,
        ]);

        Livewire::actingAs($user)
            ->test(MechanicDeployments::class)
            ->call('startRecordingOutcome', $deployment->id)
            ->set('outcomePeriodStart', '2026-06-01')
            ->set('outcomePeriodEnd', '2026-06-30')
            ->set('outcomeEngagementMovement', '0.15')
            ->set('outcomeOutcomeMovement', '-0.02')
            ->call('saveOutcome');

        $this->assertDatabaseHas('mechanic_outcomes', [
            'deployment_id' => $deployment->id,
            'recorded_by' => $user->id,
        ]);
    }

    public function test_recording_an_outcome_requires_both_movement_fields(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create([
            'team_id' => $user->currentTeam->id,
            'mechanic_id' => $mechanic->id,
        ]);

        Livewire::actingAs($user)
            ->test(MechanicDeployments::class)
            ->call('startRecordingOutcome', $deployment->id)
            ->set('outcomePeriodStart', '2026-06-01')
            ->set('outcomePeriodEnd', '2026-06-30')
            ->set('outcomeEngagementMovement', '')
            ->set('outcomeOutcomeMovement', '')
            ->call('saveOutcome')
            ->assertHasErrors(['outcomeEngagementMovement', 'outcomeOutcomeMovement']);

        $this->assertSame(0, MechanicOutcome::count());
    }

    public function test_a_user_cannot_record_an_outcome_against_another_teams_deployment(): void
    {
        $ownerTeam = Team::factory()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $otherTeamsDeployment = MechanicDeployment::factory()->create([
            'team_id' => $ownerTeam->id,
            'mechanic_id' => $mechanic->id,
        ]);

        $attacker = User::factory()->withPersonalTeam()->create();

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($attacker)
            ->test(MechanicDeployments::class)
            ->call('startRecordingOutcome', $otherTeamsDeployment->id)
            ->set('outcomePeriodStart', '2026-06-01')
            ->set('outcomePeriodEnd', '2026-06-30')
            ->set('outcomeEngagementMovement', '0.1')
            ->set('outcomeOutcomeMovement', '0.1')
            ->call('saveOutcome');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Dopemine/MechanicOutcomeRecordingTest.php`
Expected: FAIL — `startRecordingOutcome`/`saveOutcome` methods don't exist.

- [ ] **Step 3: Implement the Livewire component changes**

In `app/Livewire/MechanicDeployments.php`, replace the full file:

```php
<?php

namespace App\Livewire;

use App\Actions\Dopemine\RetireMechanicDeployment;
use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Which certified mechanics the current team has deployed, and their
 * status. Deploying happens from MechanicCatalog; this component covers
 * viewing, retiring (wiki.md §5 `engagement.deployment.retired`), and
 * recording paired engagement/outcome movement (wiki.md §4 "Mechanic
 * outcome") — the deploying team self-reports its own metrics, matching
 * wiki.md §3's architecture diagram.
 *
 * MechanicDeployment::HasTeamScope already restricts every query below to
 * the current team, so no explicit where('team_id', ...) is needed here —
 * a cross-team ID passed to retire() or startRecordingOutcome() is
 * invisible to the model and findOrFail() throws ModelNotFoundException,
 * same as before.
 */
class MechanicDeployments extends Component
{
    public ?int $recordingOutcomeId = null;

    public string $outcomePeriodStart = '';

    public string $outcomePeriodEnd = '';

    public string $outcomeEngagementMovement = '';

    public string $outcomeOutcomeMovement = '';

    public string $outcomeNotes = '';

    #[Computed]
    public function deployments(): Collection
    {
        return MechanicDeployment::query()
            ->with('mechanic')
            ->latest('started_at')
            ->get();
    }

    public function retire(int $deploymentId): void
    {
        $deployment = MechanicDeployment::findOrFail($deploymentId);

        app(RetireMechanicDeployment::class)->retire($deployment);

        unset($this->deployments);
    }

    public function startRecordingOutcome(int $deploymentId): void
    {
        // Scoped by HasTeamScope — throws ModelNotFoundException for a
        // cross-team ID before any form state is set.
        MechanicDeployment::findOrFail($deploymentId);

        $this->recordingOutcomeId = $deploymentId;
        $this->outcomePeriodStart = '';
        $this->outcomePeriodEnd = '';
        $this->outcomeEngagementMovement = '';
        $this->outcomeOutcomeMovement = '';
        $this->outcomeNotes = '';
    }

    public function cancelRecordingOutcome(): void
    {
        $this->recordingOutcomeId = null;
    }

    public function saveOutcome(): void
    {
        $deployment = MechanicDeployment::findOrFail($this->recordingOutcomeId);

        $this->validate([
            'outcomePeriodStart' => ['required', 'date'],
            'outcomePeriodEnd' => ['required', 'date', 'after_or_equal:outcomePeriodStart'],
            'outcomeEngagementMovement' => ['required', 'numeric'],
            'outcomeOutcomeMovement' => ['required', 'numeric'],
            'outcomeNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        MechanicOutcome::create([
            'deployment_id' => $deployment->id,
            'period_start' => $this->outcomePeriodStart,
            'period_end' => $this->outcomePeriodEnd,
            'engagement_movement' => $this->outcomeEngagementMovement,
            'outcome_movement' => $this->outcomeOutcomeMovement,
            'recorded_by' => auth()->id(),
            'notes' => $this->outcomeNotes ?: null,
        ]);

        $this->recordingOutcomeId = null;
    }

    public function render()
    {
        return view('livewire.mechanic-deployments');
    }
}
```

- [ ] **Step 4: Add the recording form to the view**

In `resources/views/livewire/mechanic-deployments.blade.php`, replace the `<td>` containing the Retire button (the last `<td>` in each row) with:

```blade
                    <td style="padding:0.65rem 0;text-align:right;">
                        @if ($deployment->status->value === 'active')
                            <button wire:click="startRecordingOutcome({{ $deployment->id }})" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">
                                Record outcome
                            </button>
                            <button wire:click="retire({{ $deployment->id }})" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">
                                Retire
                            </button>
                        @endif
                    </td>
```

Then, immediately before the closing `</table>` tag... actually the form needs to render per-row since it's keyed to `$recordingOutcomeId`. Add a new row directly under each deployment's row, inside the `@forelse` loop, right after the closing `</tr>` of the deployment row:

```blade
                @if ($recordingOutcomeId === $deployment->id)
                    <tr>
                        <td colspan="6" style="padding:0 0 0.75rem;">
                            <div class="dot-card" style="padding:1rem;">
                                <div style="display:flex;gap:0.75rem;flex-wrap:wrap;">
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Period start</label>
                                        <input type="date" wire:model="outcomePeriodStart" class="dot-input" style="display:block;">
                                        @error('outcomePeriodStart') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Period end</label>
                                        <input type="date" wire:model="outcomePeriodEnd" class="dot-input" style="display:block;">
                                        @error('outcomePeriodEnd') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Engagement movement</label>
                                        <input type="text" wire:model="outcomeEngagementMovement" placeholder="e.g. 0.15" class="dot-input" style="display:block;width:110px;">
                                        @error('outcomeEngagementMovement') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                    <div>
                                        <label style="font-size:10px;color:#71717a;text-transform:uppercase;letter-spacing:0.06em;">Outcome movement</label>
                                        <input type="text" wire:model="outcomeOutcomeMovement" placeholder="e.g. -0.02" class="dot-input" style="display:block;width:110px;">
                                        @error('outcomeOutcomeMovement') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                                    </div>
                                </div>
                                <textarea wire:model="outcomeNotes" class="dot-input" rows="2" placeholder="Notes (optional)" style="margin-top:0.6rem;"></textarea>
                                <div style="display:flex;gap:0.5rem;margin-top:0.6rem;">
                                    <button wire:click="saveOutcome" class="dot-btn dot-btn-primary" style="font-size:11px;padding:5px 10px;">Save outcome</button>
                                    <button wire:click="cancelRecordingOutcome" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">Cancel</button>
                                </div>
                            </div>
                        </td>
                    </tr>
                @endif
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Dopemine/MechanicOutcomeRecordingTest.php`
Expected: 3 passed.

- [ ] **Step 6: Re-run the existing tenancy regression suite** (confirms `retire()` and `deployments()` are untouched)

Run: `php artisan test --compact tests/Feature/Dopemine/MechanicDeploymentTenancyTest.php`
Expected: all still passing.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/MechanicDeployments.php resources/views/livewire/mechanic-deployments.blade.php tests/Feature/Dopemine/MechanicOutcomeRecordingTest.php
git commit -m "feat(dopemine): record paired outcomes from the deployment list"
```

---

## Task 4: Record `WellbeingObservation` from `MechanicCatalog`

**Files:**
- Modify: `app/Livewire/MechanicCatalog.php`
- Modify: `resources/views/livewire/mechanic-catalog.blade.php`
- Test: `tests/Feature/Dopemine/WellbeingObservationRecordingTest.php`

**Interfaces:**
- Consumes: `App\Models\WellbeingObservation::create()` (Task 2), `MechanicCatalog::canGovern()` (pre-existing).
- Produces: `MechanicCatalog::startRecordingWellbeing(int $mechanicId)`, `::cancelRecordingWellbeing()`, `::saveWellbeingObservation()`; public properties `$recordingWellbeingId`, `$wellbeingCohort`, `$wellbeingWindowStart`, `$wellbeingWindowEnd`, `$wellbeingCohortSize`, `$wellbeingMovement`, `$wellbeingNotes`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicCatalog;
use App\Models\Mechanic;
use App\Models\User;
use App\Models\WellbeingObservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class WellbeingObservationRecordingTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_record_a_wellbeing_observation(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startRecordingWellbeing', $mechanic->id)
            ->set('wellbeingCohort', 'Dot.Projects — pilot team')
            ->set('wellbeingWindowStart', '2026-06-01')
            ->set('wellbeingWindowEnd', '2026-06-30')
            ->set('wellbeingCohortSize', '75')
            ->set('wellbeingMovement', '-0.01')
            ->call('saveWellbeingObservation');

        $this->assertDatabaseHas('wellbeing_observations', [
            'mechanic_id' => $mechanic->id,
            'cohort' => 'Dot.Projects — pilot team',
            'recorded_by' => $admin->id,
        ]);
    }

    public function test_a_non_admin_cannot_record_a_wellbeing_observation(): void
    {
        $member = User::factory()->create(['current_team_id' => null]);
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($member)
            ->test(MechanicCatalog::class)
            ->call('startRecordingWellbeing', $mechanic->id)
            ->assertForbidden();

        $this->assertSame(0, WellbeingObservation::count());
    }

    public function test_a_cohort_size_under_fifty_is_rejected_at_the_form_layer(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startRecordingWellbeing', $mechanic->id)
            ->set('wellbeingCohort', 'Tiny cohort')
            ->set('wellbeingWindowStart', '2026-06-01')
            ->set('wellbeingWindowEnd', '2026-06-30')
            ->set('wellbeingCohortSize', '10')
            ->set('wellbeingMovement', '0.0')
            ->call('saveWellbeingObservation')
            ->assertHasErrors(['wellbeingCohortSize']);

        $this->assertSame(0, WellbeingObservation::count());
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Dopemine/WellbeingObservationRecordingTest.php`
Expected: FAIL — methods don't exist.

- [ ] **Step 3: Add the new properties and methods to `MechanicCatalog`**

In `app/Livewire/MechanicCatalog.php`, add this import:

```php
use App\Models\WellbeingObservation;
```

Add these public properties, alongside the existing `$decertifyingReason`/`$decertifyingId`:

```php
    public string $wellbeingCohort = '';

    public string $wellbeingWindowStart = '';

    public string $wellbeingWindowEnd = '';

    public string $wellbeingCohortSize = '';

    public string $wellbeingMovement = '';

    public string $wellbeingNotes = '';

    public ?int $recordingWellbeingId = null;
```

Add these methods, after `confirmDecertify()`:

```php
    public function startRecordingWellbeing(int $mechanicId): void
    {
        abort_unless($this->canGovern(), 403);

        Mechanic::findOrFail($mechanicId);

        $this->recordingWellbeingId = $mechanicId;
        $this->wellbeingCohort = '';
        $this->wellbeingWindowStart = '';
        $this->wellbeingWindowEnd = '';
        $this->wellbeingCohortSize = '';
        $this->wellbeingMovement = '';
        $this->wellbeingNotes = '';
    }

    public function cancelRecordingWellbeing(): void
    {
        $this->recordingWellbeingId = null;
    }

    public function saveWellbeingObservation(): void
    {
        abort_unless($this->canGovern(), 403);

        $mechanic = Mechanic::findOrFail($this->recordingWellbeingId);

        $this->validate([
            'wellbeingCohort' => ['required', 'string', 'max:255'],
            'wellbeingWindowStart' => ['required', 'date'],
            'wellbeingWindowEnd' => ['required', 'date', 'after_or_equal:wellbeingWindowStart'],
            'wellbeingCohortSize' => ['required', 'integer', 'min:50'],
            'wellbeingMovement' => ['required', 'numeric'],
            'wellbeingNotes' => ['nullable', 'string', 'max:2000'],
        ]);

        WellbeingObservation::create([
            'mechanic_id' => $mechanic->id,
            'cohort' => $this->wellbeingCohort,
            'window_start' => $this->wellbeingWindowStart,
            'window_end' => $this->wellbeingWindowEnd,
            'cohort_size' => $this->wellbeingCohortSize,
            'wellbeing_movement' => $this->wellbeingMovement,
            'recorded_by' => auth()->id(),
            'notes' => $this->wellbeingNotes ?: null,
        ]);

        $this->recordingWellbeingId = null;
    }
```

- [ ] **Step 4: Add the recording form to the view**

In `resources/views/livewire/mechanic-catalog.blade.php`, inside the `@if ($this->canGovern())` block that already renders Certify/Decertify buttons, add a third button right after the `@if ($mechanic->status->value === 'certified')` Decertify block closes (i.e. as a sibling, still inside the outer `@if ($this->canGovern())`):

```blade
                        <button wire:click="startRecordingWellbeing({{ $mechanic->id }})" class="dot-btn dot-btn-ghost" style="font-size:11.5px;padding:6px 11px;">
                            Record wellbeing observation
                        </button>
```

Then, immediately after the existing `@if ($decertifyingId === $mechanic->id)` block closes (its `@endif`), add:

```blade
                @if ($recordingWellbeingId === $mechanic->id)
                    <div style="margin-top:0.75rem;padding-top:0.75rem;border-top:1px solid rgba(255,255,255,0.06);">
                        <input type="text" wire:model="wellbeingCohort" placeholder="Cohort (e.g. Dot.Projects — pilot team)" class="dot-input" style="margin-bottom:0.4rem;">
                        @error('wellbeingCohort') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                        <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.4rem;">
                            <input type="date" wire:model="wellbeingWindowStart" class="dot-input">
                            <input type="date" wire:model="wellbeingWindowEnd" class="dot-input">
                            <input type="number" wire:model="wellbeingCohortSize" placeholder="Cohort size (n >= 50)" class="dot-input" style="width:150px;">
                            <input type="text" wire:model="wellbeingMovement" placeholder="Wellbeing movement" class="dot-input" style="width:150px;">
                        </div>
                        @error('wellbeingWindowStart') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                        @error('wellbeingWindowEnd') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                        @error('wellbeingCohortSize') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                        @error('wellbeingMovement') <div style="color:#ef4444;font-size:11px;">{{ $message }}</div> @enderror
                        <textarea wire:model="wellbeingNotes" class="dot-input" rows="2" placeholder="Notes (optional)"></textarea>
                        <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                            <button wire:click="saveWellbeingObservation" class="dot-btn dot-btn-primary" style="font-size:11px;padding:5px 10px;">Save observation</button>
                            <button wire:click="cancelRecordingWellbeing" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">Cancel</button>
                        </div>
                    </div>
                @endif
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Dopemine/WellbeingObservationRecordingTest.php`
Expected: 3 passed.

- [ ] **Step 6: Re-run the existing MechanicCatalog regression suites**

Run: `php artisan test --compact tests/Feature/Dopemine/MechanicCatalogNoTeamTest.php tests/Feature/Dopemine/EthicsGateTest.php`
Expected: all still passing.

- [ ] **Step 7: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/MechanicCatalog.php resources/views/livewire/mechanic-catalog.blade.php tests/Feature/Dopemine/WellbeingObservationRecordingTest.php
git commit -m "feat(dopemine): record wellbeing observations from the catalog (canGovern-gated)"
```

---

## Task 5: `dopemine:scan-decoupling` command (Level 1 + Level 2 detection)

**Files:**
- Create: `database/migrations/2026_08_09_130003_add_coupling_rate_to_mechanics_table.php`
- Create: `database/migrations/2026_08_09_130004_create_mechanic_retirement_candidates_table.php`
- Create: `app/Models/MechanicRetirementCandidate.php`
- Create: `app/Console/Commands/ScanMechanicDecoupling.php`
- Modify: `app/Models/Mechanic.php` (add `coupling_rate`/`coupling_rate_computed_at` casts)
- Modify: `resources/views/livewire/mechanic-catalog.blade.php` (coupling-rate badge)
- Modify: `routes/console.php`
- Test: `tests/Feature/Dopemine/ScanMechanicDecouplingTest.php`

**Interfaces:**
- Consumes: `App\Models\Mechanic` (`coupling_rate`, `coupling_rate_computed_at` new columns), `App\Models\MechanicOutcome` (Task 1), `App\Models\MechanicDeployment::activeDeployments()` relation (pre-existing, via `Mechanic::activeDeployments()`).
- Produces: `App\Models\MechanicRetirementCandidate` with fillable `mechanic_id, coupling_rate, sample_size, status, detected_at, reviewed_by, review_notes, reviewed_at`; relations `mechanic(): BelongsTo`, `reviewedBy(): BelongsTo`. Console command `dopemine:scan-decoupling`, consumed by Task 6's review UI (reads `MechanicRetirementCandidate::where('status', 'open')`).

- [ ] **Step 1: Write the `mechanics` column migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * engagement.outcome_coupling_rate (Dot.Brain audit §11), written by
 * App\Console\Commands\ScanMechanicDecoupling. Pure computed reporting
 * output — this migration performs no mutation to certification status.
 * Both columns stay null for a mechanic with no MechanicOutcome records in
 * the scan window (no data, not a false "fully decoupled" 0% reading).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->decimal('coupling_rate', 5, 4)->nullable()->after('decertified_at');
            $table->timestamp('coupling_rate_computed_at')->nullable()->after('coupling_rate');
        });
    }

    public function down(): void
    {
        Schema::table('mechanics', function (Blueprint $table) {
            $table->dropColumn(['coupling_rate', 'coupling_rate_computed_at']);
        });
    }
};
```

- [ ] **Step 2: Write the `mechanic_retirement_candidates` migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A Level 2 proposal: engagement-up/outcome-flat decoupling detected for a
 * certified mechanic, awaiting a canGovern() admin's decision. Never
 * auto-executes — "confirmed" still requires an explicit admin action that
 * calls the existing, unchanged App\Actions\Dopemine\DecertifyMechanic.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mechanic_retirement_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('mechanic_id')->constrained()->cascadeOnDelete();
            $table->decimal('coupling_rate', 5, 4);
            $table->unsignedInteger('sample_size');
            $table->string('status')->default('open');
            $table->timestamp('detected_at');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('review_notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['mechanic_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mechanic_retirement_candidates');
    }
};
```

- [ ] **Step 3: Write the model**

```php
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
```

- [ ] **Step 4: Write the failing command test**

```php
<?php

namespace Tests\Feature\Dopemine;

use App\Livewire\MechanicCatalog;
use App\Models\Mechanic;
use App\Models\MechanicDeployment;
use App\Models\MechanicOutcome;
use App\Models\MechanicRetirementCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ScanMechanicDecouplingTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_mechanic_with_no_outcome_records_is_left_with_a_null_coupling_rate(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertNull($mechanic->coupling_rate);
        $this->assertNull($mechanic->coupling_rate_computed_at);
    }

    public function test_a_fully_coupled_mechanic_gets_a_coupling_rate_of_one_and_no_candidate(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->coupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->coupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertSame('1.0000', $mechanic->coupling_rate);
        $this->assertNotNull($mechanic->coupling_rate_computed_at);
        $this->assertSame(0, MechanicRetirementCandidate::count());
    }

    public function test_a_majority_decoupled_mechanic_gets_a_low_coupling_rate_and_a_retirement_candidate(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertSame('0.0000', $mechanic->coupling_rate);

        $this->assertDatabaseHas('mechanic_retirement_candidates', [
            'mechanic_id' => $mechanic->id,
            'status' => 'open',
            'sample_size' => 2,
        ]);
    }

    public function test_a_single_decoupled_record_does_not_raise_a_candidate_below_the_sample_size_floor(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $this->assertSame(0, MechanicRetirementCandidate::count());
    }

    public function test_outcome_records_older_than_three_months_are_excluded_from_the_window(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(8),
            'period_end' => now()->subMonths(8)->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling')->assertExitCode(0);

        $mechanic->refresh();
        $this->assertNull($mechanic->coupling_rate);
    }

    public function test_rescanning_refreshes_an_open_candidates_numbers_but_preserves_its_detected_at(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling');
        $firstDetectedAt = MechanicRetirementCandidate::first()->detected_at;

        $this->travel(1)->day();

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subDays(2),
            'period_end' => now()->subDay(),
        ]);

        $this->artisan('dopemine:scan-decoupling');

        $this->assertSame(1, MechanicRetirementCandidate::count());
        $candidate = MechanicRetirementCandidate::first();
        $this->assertSame(3, $candidate->sample_size);
        $this->assertEquals($firstDetectedAt->timestamp, $candidate->detected_at->timestamp);
    }

    public function test_a_decertified_mechanic_is_never_scanned(): void
    {
        $mechanic = Mechanic::factory()->certified()->create();
        $mechanic->forceFill(['status' => 'decertified'])->save();
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);
        MechanicOutcome::factory()->for($deployment, 'deployment')->decoupled()->create([
            'period_start' => now()->subMonths(2),
            'period_end' => now()->subMonths(2)->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling');

        $mechanic->refresh();
        $this->assertNull($mechanic->coupling_rate);
        $this->assertSame(0, MechanicRetirementCandidate::count());
    }
}
```

- [ ] **Step 5: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Dopemine/ScanMechanicDecouplingTest.php`
Expected: FAIL — command `dopemine:scan-decoupling` doesn't exist, `coupling_rate` column missing.

- [ ] **Step 6: Migrate**

```bash
php artisan migrate
```

- [ ] **Step 7: Add the two new columns to `Mechanic`'s casts**

In `app/Models/Mechanic.php`, the `casts()` method currently returns an array ending with `'decertified_at' => 'datetime',`. Add two more entries so it reads:

```php
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
```

- [ ] **Step 8: Write the command**

```php
<?php

namespace App\Console\Commands;

use App\Models\Mechanic;
use App\Models\MechanicOutcome;
use App\Models\MechanicRetirementCandidate;
use Illuminate\Console\Command;

/**
 * This platform's first scheduled job (Dot.Brain audit's Level 1/2
 * candidate, wiki.md §11 `engagement.outcome_coupling_rate`).
 *
 * Level 1 (no mutation to certification): for every certified mechanic,
 * compute coupling_rate from its MechanicOutcome records over the last 3
 * months and write it back onto the Mechanic row as pure reporting output.
 *
 * Level 2 (proposal only, never auto-executes): if the mechanic has at
 * least 2 outcome records in the window and coupling_rate < 0.5, raise or
 * refresh an open MechanicRetirementCandidate for a canGovern() admin to
 * review (see App\Livewire\MechanicCatalog::confirmRetirementCandidate /
 * ::dismissRetirementCandidate).
 *
 * Runs without an authenticated user (console context), so
 * MechanicDeployment::HasTeamScope's global scope adds no where('team_id')
 * clause here — this command intentionally sees every team's deployments,
 * since a mechanic's coupling rate spans its use across the whole catalog,
 * not one team's usage of it.
 */
class ScanMechanicDecoupling extends Command
{
    protected $signature = 'dopemine:scan-decoupling';

    protected $description = 'Compute engagement.outcome_coupling_rate per certified mechanic and flag decoupling for admin review.';

    private const WINDOW_MONTHS = 3;

    private const DECOUPLING_THRESHOLD = 0.5;

    private const MINIMUM_SAMPLE_SIZE = 2;

    public function handle(): int
    {
        Mechanic::where('status', 'certified')->each(function (Mechanic $mechanic): void {
            $deploymentIds = $mechanic->activeDeployments()->pluck('id');

            $outcomes = MechanicOutcome::whereIn('deployment_id', $deploymentIds)
                ->where('period_end', '>=', now()->subMonths(self::WINDOW_MONTHS))
                ->get();

            if ($outcomes->isEmpty()) {
                $mechanic->forceFill([
                    'coupling_rate' => null,
                    'coupling_rate_computed_at' => null,
                ])->save();

                return;
            }

            $coupledCount = $outcomes->filter(function (MechanicOutcome $outcome): bool {
                return ! ($outcome->engagement_movement > 0 && $outcome->outcome_movement <= 0);
            })->count();

            $couplingRate = round($coupledCount / $outcomes->count(), 4);

            $mechanic->forceFill([
                'coupling_rate' => $couplingRate,
                'coupling_rate_computed_at' => now(),
            ])->save();

            $sampleSize = $outcomes->count();

            if ($sampleSize < self::MINIMUM_SAMPLE_SIZE || $couplingRate >= self::DECOUPLING_THRESHOLD) {
                return;
            }

            $openCandidate = MechanicRetirementCandidate::where('mechanic_id', $mechanic->id)
                ->where('status', 'open')
                ->first();

            if ($openCandidate) {
                $openCandidate->update([
                    'coupling_rate' => $couplingRate,
                    'sample_size' => $sampleSize,
                ]);

                return;
            }

            MechanicRetirementCandidate::create([
                'mechanic_id' => $mechanic->id,
                'coupling_rate' => $couplingRate,
                'sample_size' => $sampleSize,
                'status' => 'open',
                'detected_at' => now(),
            ]);
        });

        $this->info('Decoupling scan complete.');

        return self::SUCCESS;
    }
}
```

- [ ] **Step 9: Schedule the command**

In `routes/console.php`, replace the full file:

```php
<?php

use App\Console\Commands\ScanMechanicDecoupling;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─── Scheduled Platform Jobs ──────────────────────────────────────────────────
// This platform's first scheduled process — see
// docs/superpowers/specs/2026-08-09-decoupling-detection-design.md.
Schedule::command(ScanMechanicDecoupling::class)
    ->daily()
    ->withoutOverlapping();
```

- [ ] **Step 10: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Dopemine/ScanMechanicDecouplingTest.php`
Expected: 7 passed.

- [ ] **Step 11: Display the computed coupling rate on each mechanic card**

Spec §3 requires the coupling rate to be visible as a read-only badge — this is Level 1's actual "reporting output," not just a column no one sees. In `resources/views/livewire/mechanic-catalog.blade.php`, inside the mechanic card's status-badge row (the `<div>` containing the status badge, acid-test text, and "team(s) using this" count), add a new line right after the "team(s) using this" `<span>`:

```blade
                    @if ($mechanic->coupling_rate_computed_at)
                        <span style="font-size:11px;color:{{ $mechanic->coupling_rate >= 0.5 ? '#22c55e' : '#ef4444' }};">
                            coupling: {{ number_format($mechanic->coupling_rate * 100, 0) }}%
                        </span>
                    @endif
```

Add this test to `tests/Feature/Dopemine/ScanMechanicDecouplingTest.php`, after `test_a_fully_coupled_mechanic_gets_a_coupling_rate_of_one_and_no_candidate`:

```php
    public function test_the_catalog_view_shows_the_computed_coupling_rate_badge(): void
    {
        $user = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create(['name' => 'Progress Bar']);
        $deployment = MechanicDeployment::factory()->create(['mechanic_id' => $mechanic->id, 'status' => 'active']);

        MechanicOutcome::factory()->for($deployment, 'deployment')->coupled()->create([
            'period_start' => now()->subMonth(),
            'period_end' => now()->subMonth()->addDays(28),
        ]);

        $this->artisan('dopemine:scan-decoupling');

        Livewire::actingAs($user)
            ->test(MechanicCatalog::class)
            ->assertSee('coupling: 100%');
    }
```

Run: `php artisan test --compact tests/Feature/Dopemine/ScanMechanicDecouplingTest.php`
Expected: 8 passed.

- [ ] **Step 12: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add database/migrations/2026_08_09_130003_add_coupling_rate_to_mechanics_table.php database/migrations/2026_08_09_130004_create_mechanic_retirement_candidates_table.php app/Models/Mechanic.php app/Models/MechanicRetirementCandidate.php app/Console/Commands/ScanMechanicDecoupling.php resources/views/livewire/mechanic-catalog.blade.php routes/console.php tests/Feature/Dopemine/ScanMechanicDecouplingTest.php
git commit -m "feat(dopemine): add dopemine:scan-decoupling — coupling_rate + retirement candidates"
```

---

## Task 6: Retirement-candidate review UI (Level 2 gate)

**Files:**
- Modify: `app/Livewire/MechanicCatalog.php`
- Modify: `resources/views/livewire/mechanic-catalog.blade.php`
- Test: `tests/Feature/Dopemine/RetirementCandidateReviewTest.php`

**Interfaces:**
- Consumes: `App\Models\MechanicRetirementCandidate` (Task 5), `App\Actions\Dopemine\DecertifyMechanic::decertify()` (pre-existing, unchanged), `Mechanic::wellbeingObservations()` (Task 2).
- Produces: `MechanicCatalog::confirmRetirementCandidate(int $candidateId)`, `::startDismissingCandidate(int $candidateId)`, `::confirmDismissCandidate()`; computed property `retirementCandidates(): Collection`; public properties `$dismissingCandidateId`, `$dismissalNotes`.

- [ ] **Step 1: Write the failing tests**

```php
<?php

namespace Tests\Feature\Dopemine;

use App\Enums\MechanicStatus;
use App\Livewire\MechanicCatalog;
use App\Models\Mechanic;
use App\Models\MechanicRetirementCandidate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class RetirementCandidateReviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_confirm_a_retirement_candidate_which_decertifies_the_mechanic(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('confirmRetirementCandidate', $candidate->id);

        $mechanic->refresh();
        $this->assertSame(MechanicStatus::Decertified, $mechanic->status);
        $this->assertNotNull($mechanic->decertification_reason);

        $candidate->refresh();
        $this->assertSame('confirmed', $candidate->status);
        $this->assertSame($admin->id, $candidate->reviewed_by);
        $this->assertNotNull($candidate->reviewed_at);
    }

    public function test_an_admin_can_dismiss_a_retirement_candidate_leaving_the_mechanic_certified(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startDismissingCandidate', $candidate->id)
            ->set('dismissalNotes', 'Confirmed false positive — one deployment had a data entry error.')
            ->call('confirmDismissCandidate');

        $mechanic->refresh();
        $this->assertSame(MechanicStatus::Certified, $mechanic->status);

        $candidate->refresh();
        $this->assertSame('dismissed', $candidate->status);
        $this->assertSame($admin->id, $candidate->reviewed_by);
        $this->assertSame('Confirmed false positive — one deployment had a data entry error.', $candidate->review_notes);
    }

    public function test_dismissing_a_candidate_requires_notes(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->call('startDismissingCandidate', $candidate->id)
            ->set('dismissalNotes', '')
            ->call('confirmDismissCandidate')
            ->assertHasErrors(['dismissalNotes']);

        $this->assertSame('open', $candidate->fresh()->status);
    }

    public function test_a_non_admin_cannot_confirm_a_retirement_candidate(): void
    {
        $member = User::factory()->create(['current_team_id' => null]);
        $mechanic = Mechanic::factory()->certified()->create();
        $candidate = MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.2,
            'sample_size' => 3,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($member)
            ->test(MechanicCatalog::class)
            ->call('confirmRetirementCandidate', $candidate->id)
            ->assertForbidden();

        $this->assertSame(MechanicStatus::Certified, $mechanic->fresh()->status);
        $this->assertSame('open', $candidate->fresh()->status);
    }

    public function test_the_catalog_view_lists_open_retirement_candidates_for_an_admin(): void
    {
        $admin = User::factory()->withPersonalTeam()->create();
        $mechanic = Mechanic::factory()->certified()->create(['name' => 'Milestone Recognition']);
        MechanicRetirementCandidate::create([
            'mechanic_id' => $mechanic->id,
            'coupling_rate' => 0.25,
            'sample_size' => 4,
            'status' => 'open',
            'detected_at' => now(),
        ]);

        Livewire::actingAs($admin)
            ->test(MechanicCatalog::class)
            ->assertSee('Milestone Recognition')
            ->assertSee('Retirement Candidates');
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --compact tests/Feature/Dopemine/RetirementCandidateReviewTest.php`
Expected: FAIL — methods/computed property don't exist.

- [ ] **Step 3: Implement the Livewire component changes**

In `app/Livewire/MechanicCatalog.php`, add these imports:

```php
use App\Actions\Dopemine\DecertifyMechanic;
use App\Models\MechanicRetirementCandidate;
```

(`DecertifyMechanic` is already imported — skip if present.)

Add these public properties, alongside the wellbeing-recording properties added in Task 4:

```php
    public ?int $dismissingCandidateId = null;

    public string $dismissalNotes = '';
```

Add this computed property, alongside `mechanics()`:

```php
    #[Computed]
    public function retirementCandidates(): Collection
    {
        return MechanicRetirementCandidate::query()
            ->where('status', 'open')
            ->with('mechanic')
            ->latest('detected_at')
            ->get();
    }
```

Add these methods, after `saveWellbeingObservation()`:

```php
    public function confirmRetirementCandidate(int $candidateId): void
    {
        abort_unless($this->canGovern(), 403);

        $candidate = MechanicRetirementCandidate::findOrFail($candidateId);
        $mechanic = $candidate->mechanic;

        $reason = sprintf(
            'Decoupling finding: coupling rate %s across %d outcome records over the last 3 months (wiki.md §11).',
            $candidate->coupling_rate,
            $candidate->sample_size
        );

        app(DecertifyMechanic::class)->decertify(auth()->user(), $mechanic, ['reason' => $reason]);

        $candidate->update([
            'status' => 'confirmed',
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        unset($this->mechanics, $this->retirementCandidates);
    }

    public function startDismissingCandidate(int $candidateId): void
    {
        abort_unless($this->canGovern(), 403);

        MechanicRetirementCandidate::findOrFail($candidateId);

        $this->dismissingCandidateId = $candidateId;
        $this->dismissalNotes = '';
    }

    public function confirmDismissCandidate(): void
    {
        abort_unless($this->canGovern(), 403);

        $this->validate([
            'dismissalNotes' => ['required', 'string', 'max:2000'],
        ]);

        $candidate = MechanicRetirementCandidate::findOrFail($this->dismissingCandidateId);

        $candidate->update([
            'status' => 'dismissed',
            'review_notes' => $this->dismissalNotes,
            'reviewed_by' => auth()->id(),
            'reviewed_at' => now(),
        ]);

        $this->dismissingCandidateId = null;
        $this->dismissalNotes = '';

        unset($this->retirementCandidates);
    }
```

- [ ] **Step 4: Add the review section to the view**

In `resources/views/livewire/mechanic-catalog.blade.php`, add this block immediately after the opening `<div>` tag, before the existing filter bar `<div class="dot-card" ...>`:

```blade
    @if ($this->canGovern() && $this->retirementCandidates->isNotEmpty())
        <div class="dot-card" style="padding:1.25rem 1.5rem;margin-bottom:1rem;border-color:rgba(239,68,68,0.3);">
            <h3 style="font-family:'Syne',sans-serif;font-size:0.85rem;font-weight:700;color:#f4f4f5;margin:0 0 0.75rem;">Retirement Candidates</h3>
            @foreach ($this->retirementCandidates as $candidate)
                <div style="padding:0.75rem 0;border-top:1px solid rgba(255,255,255,0.06);">
                    <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:0.5rem;">
                        <div>
                            <span style="color:#f4f4f5;font-weight:600;font-size:0.85rem;">{{ $candidate->mechanic->name }}</span>
                            <span style="color:#71717a;font-size:0.75rem;margin-left:0.5rem;">
                                coupling rate {{ number_format($candidate->coupling_rate * 100, 1) }}% across {{ $candidate->sample_size }} record(s)
                            </span>
                        </div>
                        <div style="display:flex;gap:0.5rem;">
                            <button wire:click="confirmRetirementCandidate({{ $candidate->id }})" wire:confirm="Confirm retirement? This will decertify the mechanic." class="dot-btn" style="font-size:11px;padding:5px 10px;background:#ef4444;color:#fff;">
                                Confirm retirement
                            </button>
                            <button wire:click="startDismissingCandidate({{ $candidate->id }})" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">
                                Dismiss
                            </button>
                        </div>
                    </div>
                    @php($latestWellbeing = $candidate->mechanic->wellbeingObservations()->latest('window_end')->first())
                    @if ($latestWellbeing)
                        <p style="font-size:0.72rem;color:#52525b;margin:0.4rem 0 0;">
                            Most recent wellbeing observation: {{ $latestWellbeing->cohort }}, movement {{ $latestWellbeing->wellbeing_movement }} (n={{ $latestWellbeing->cohort_size }})
                        </p>
                    @endif
                    @if ($dismissingCandidateId === $candidate->id)
                        <div style="margin-top:0.6rem;">
                            <textarea wire:model="dismissalNotes" class="dot-input" rows="2" placeholder="Dismissal notes (required)"></textarea>
                            @error('dismissalNotes') <div style="color:#ef4444;font-size:11px;margin-top:4px;">{{ $message }}</div> @enderror
                            <div style="display:flex;gap:0.5rem;margin-top:0.5rem;">
                                <button wire:click="confirmDismissCandidate" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">Confirm dismiss</button>
                                <button wire:click="$set('dismissingCandidateId', null)" class="dot-btn dot-btn-ghost" style="font-size:11px;padding:5px 10px;">Cancel</button>
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --compact tests/Feature/Dopemine/RetirementCandidateReviewTest.php`
Expected: 5 passed.

- [ ] **Step 6: Format and commit**

```bash
vendor/bin/pint --dirty --format agent
git add app/Livewire/MechanicCatalog.php resources/views/livewire/mechanic-catalog.blade.php tests/Feature/Dopemine/RetirementCandidateReviewTest.php
git commit -m "feat(dopemine): retirement-candidate review UI (confirm decertifies, dismiss keeps certified)"
```

---

## Task 7: Full regression

**Files:** none (verification only).

- [ ] **Step 1: Run the full test suite**

```bash
php artisan test --compact
```

Expected: all tests pass, including every pre-existing suite (`EthicsGateTest`, `MechanicCatalogSeederTest`, `MechanicCatalogNoTeamTest`, `MechanicDeploymentTenancyTest`, Jetstream's own team-management tests) alongside all six new suites from this plan.

- [ ] **Step 2: Run Pint across the whole diff one final time**

```bash
vendor/bin/pint --dirty --format agent
```

If it reformats anything, `git add -A` and amend or add a small formatting commit.

- [ ] **Step 3: Confirm the migration set applies cleanly from scratch**

```bash
php artisan migrate:fresh
php artisan test --compact
```

Expected: identical pass count to Step 1 — confirms no migration ordering issue between the six new migration files and the pre-existing schema.

- [ ] **Step 4: Report**

Summarize the final test count and confirm this plan's scope (Tasks 1-6) is fully implemented against `docs/superpowers/specs/2026-08-09-decoupling-detection-design.md`. Do not commit anything in this task beyond an optional Pint formatting fix — this is a verification-only task.
