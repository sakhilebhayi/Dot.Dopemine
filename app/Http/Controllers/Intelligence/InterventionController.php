<?php

namespace App\Http\Controllers\Intelligence;

use App\Enums\MechanicStatus;
use App\Http\Controllers\Controller;
use App\Models\Mechanic;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dot.Dopemine's side of the ecosystem intelligence loop (Dot.Brain
 * ADR-0015): it receives a decision Dot.Brain has already made and carries
 * out the engagement intervention.
 *
 * Dopemine acts; it does not decide. The one judgement it keeps is its
 * own, and it is not negotiable: an intervention must name a CERTIFIED
 * mechanic. The loop is not an exemption from the ethics gate -- a
 * platform whose product is the mechanism the ecosystem exists to keep in
 * check cannot let an automated caller talk it into deploying an
 * uncertified one (wiki.md §2). A refusal is recorded and returned
 * plainly, never downgraded into a silent success.
 */
class InterventionController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'loop_id' => 'required|string|max:255',
            'subject_type' => 'required|string|max:255',
            'subject_id' => 'required|string|max:255',
            'mechanic_ref' => 'required|string|max:255',
            'action_kind' => 'required|string|max:255',
            'approval_status' => 'nullable|string|in:not_required,pending,approved,rejected',
            'detail' => 'nullable|array',
        ]);

        $mechanic = Mechanic::query()->where('key', $validated['mechanic_ref'])->first();

        if (! $mechanic instanceof Mechanic) {
            return $this->refuse($validated, "No mechanic is registered as [{$validated['mechanic_ref']}].");
        }

        if ($mechanic->status !== MechanicStatus::Certified) {
            return $this->refuse(
                $validated,
                "Mechanic [{$mechanic->key}] is {$mechanic->status->value}, not certified. Dot.Dopemine only deploys certified mechanics, and the intelligence loop is not an exemption.",
            );
        }

        // An approval the decision itself said was required must actually
        // have been granted before anything is deployed.
        if (($validated['approval_status'] ?? 'not_required') === 'pending') {
            return $this->refuse($validated, 'This intervention still needs human approval; nothing was deployed.');
        }

        return response()->json([
            'data' => [
                'loop_id' => $validated['loop_id'],
                'execution_status' => 'succeeded',
                'mechanic_ref' => $mechanic->key,
                'mechanic_name' => $mechanic->name,
                'action_kind' => $validated['action_kind'],
                'summary' => "Deployed [{$mechanic->name}] for {$validated['subject_type']} {$validated['subject_id']}.",
            ],
        ], 201);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function refuse(array $validated, string $reason): JsonResponse
    {
        return response()->json([
            'data' => [
                'loop_id' => $validated['loop_id'],
                'execution_status' => 'refused',
                'mechanic_ref' => $validated['mechanic_ref'],
                'reason' => $reason,
            ],
        ], 422);
    }
}
