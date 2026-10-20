<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Reward extends Model
{
    public const KINDS = ['billing_credit', 'capacity', 'marketplace'];

    protected $guarded = [];

    protected function casts(): array
    {
        return ['active' => 'boolean', 'params' => 'array'];
    }
}
