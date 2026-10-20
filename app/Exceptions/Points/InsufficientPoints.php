<?php

namespace App\Exceptions\Points;

use RuntimeException;

/** Raised instead of ever letting a balance go below zero. */
class InsufficientPoints extends RuntimeException
{
    public function __construct(public readonly int $needed, public readonly int $available)
    {
        parent::__construct("{$needed} points needed, {$available} available.");
    }
}
