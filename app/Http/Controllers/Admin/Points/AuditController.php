<?php

namespace App\Http\Controllers\Admin\Points;

use App\Http\Controllers\Controller;
use App\Models\AdminAudit;
use Illuminate\Contracts\View\View;

/** Who changed what in this back-office, newest first. Read-only. */
class AuditController extends Controller
{
    public function index(): View
    {
        return view('admin.points.audit', ['audits' => AdminAudit::orderByDesc('id')->simplePaginate(50)]);
    }
}
