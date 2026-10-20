<?php

namespace App\Http\Controllers\Admin\Points;

use App\Http\Controllers\Controller;
use App\Models\LedgerEntry;
use App\Models\Redemption;
use App\Services\Points\PointsLedger;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

/**
 * Read-only. Search by user uuid and see the balance, every lot with its expiry and what is left in it, and the full
 * entry history with the rule version that produced each entry. Nothing here writes.
 */
class LedgerExplorerController extends Controller
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function index(Request $request, PointsLedger $ledger): View
    {
        $query = strtolower(trim((string) $request->query('user', '')));

        if ($query === '') {
            return view('admin.points.ledger', [
                'query' => '',
                'recent' => LedgerEntry::orderByDesc('id')->limit(25)->get(),
            ]);
        }

        if (preg_match(self::UUID, $query) !== 1) {
            return view('admin.points.ledger', ['query' => $query, 'invalid' => true, 'recent' => collect()]);
        }

        $lots = $ledger->lots($query);
        $entries = LedgerEntry::where('user_uuid', $query)->orderByDesc('id')->simplePaginate(50)->withQueryString();

        // A spend, expiry or reversal has no rule of its own: it drew on a lot, and the lot's rule explains it.
        $lotRules = LedgerEntry::whereIn('id', $entries->getCollection()->pluck('lot_id')->filter()->unique())->get()->keyBy('id');

        return view('admin.points.ledger', [
            'query' => $query,
            'found' => $lots->isNotEmpty() || $entries->isNotEmpty(),
            'balance' => $ledger->balance($query),
            'lots' => $lots,
            'entries' => $entries,
            'lotRules' => $lotRules,
            'redemptions' => Redemption::with('reward')->where('user_uuid', $query)->orderByDesc('id')->limit(25)->get(),
            'recent' => collect(),
        ]);
    }
}
