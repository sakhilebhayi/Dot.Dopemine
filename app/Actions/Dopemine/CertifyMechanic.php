<?php

namespace App\Actions\Dopemine;

use App\Enums\MechanicStatus;
use App\Models\Mechanic;
use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The Ethics Officer certification workflow (wiki.md §3 "Ethics gate", §5
 * `engagement.mechanic.certified`). This is the only intended path from
 * `proposed` to `certified` — the Mechanic model's `saving` listener is the
 * backstop that holds even if this action is bypassed, but this class is
 * where the human-review step (an acid-test verdict, recorded, by a named
 * person) actually happens.
 *
 * Per wiki.md §2 rule 2, the acid test is answered here, before the
 * mechanic is offered to anyone — there is no "let the consuming platform
 * decide" path in this codebase.
 */
class CertifyMechanic
{
    /**
     * @param  array{acid_test_notes?: string}  $input
     */
    public function certify(User $ethicsOfficer, Mechanic $mechanic, array $input = []): Mechanic
    {
        Validator::make($input, [
            'acid_test_notes' => ['nullable', 'string', 'max:2000'],
        ])->validate();

        if (! $mechanic->acid_test_passed) {
            throw ValidationException::withMessages([
                'acid_test_passed' => 'A mechanic cannot be certified until its acid-test verdict is recorded as passing: "Would we show this mechanic to the person it targets, with its intent labeled?" (wiki.md §2).',
            ]);
        }

        $mechanic->forceFill([
            'status' => MechanicStatus::Certified,
            'certified_by' => $ethicsOfficer->id,
            'certified_at' => now(),
            'acid_test_notes' => $input['acid_test_notes'] ?? $mechanic->acid_test_notes,
        ])->save();

        return $mechanic->fresh();
    }
}
