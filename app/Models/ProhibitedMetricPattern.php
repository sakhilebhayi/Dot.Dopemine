<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * The negative catalog (wiki.md §6 "What We Will Not Build"). Read-mostly
 * reference data — see database/seeders/MechanicCatalogSeeder for the seeded
 * list drawn directly from wiki.md §6 / Dot.Brain platforms/dot-dopemine.md §7.
 *
 * This model exists so the ethics constraint is queryable and visible in the
 * product (the dashboard renders it), not just documented in the wiki. The
 * actual enforcement mechanism is App\Enums\MechanicCategory's fixed
 * vocabulary — this table records, by name, which patterns were considered
 * and rejected, for anyone auditing the catalog's restraint.
 */
class ProhibitedMetricPattern extends Model
{
    protected $fillable = [
        'pattern',
        'reason',
        'example',
    ];
}
