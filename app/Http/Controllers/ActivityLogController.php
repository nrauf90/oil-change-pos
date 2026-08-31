<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Reading the audit trail. Read-only by design — there is deliberately no
 * store, update or destroy here, and no route that could reach one.
 */
class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $filters = [
            'action' => $request->string('action')->toString(),
            'q' => $request->string('q')->toString(),
            'from' => $this->date($request->string('from')->toString()),
            'to' => $this->date($request->string('to')->toString()),
        ];

        return view('activity-log.index', [
            'entries' => ActivityLog::query()
                ->with('user:id,name')
                ->ofAction($filters['action'])
                ->matching($filters['q'])
                ->loggedBetween($filters['from'], $filters['to'])
                // By id, not by timestamp: several entries inside one second
                // must still read back in the order they happened.
                ->latest('id')
                ->paginate(25)
                ->withQueryString(),
            'actions' => ActivityLog::filterableActions(),
            'filters' => $filters,
            'isFiltered' => collect($filters)->filter()->isNotEmpty(),
        ]);
    }

    /**
     * A junk date in the query string filters nothing rather than throwing —
     * the log is a reading screen, and a mistyped URL should still show it.
     */
    private function date(string $value): ?Carbon
    {
        if (trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
