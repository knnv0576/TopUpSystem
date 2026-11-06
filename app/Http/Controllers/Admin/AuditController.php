<?php

namespace App\Http\Controllers\Admin;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AuditController
{
    public function __invoke(Request $request): View
    {
        $action = trim((string) $request->query('action'));

        return view('admin.audit', [
            'logs' => AuditLog::when($action !== '', fn ($q) => $q->where('action', 'like', addcslashes($action, '%_\\').'%'))
                ->orderByDesc('id')->paginate(50)->withQueryString(),
            'action' => $action,
        ]);
    }
}
