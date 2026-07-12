<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Audit Logs display (Phase 15). Read-only view over the immutable, append-only
 * audit_logs table — the capture happens at each write-path via AuditRecorder,
 * never here. Admin+ (the route sits inside the role:admin group).
 *
 * Item Cost is Owner-only, but Admins may view audit logs, so any cost-bearing
 * key in the stored old/new payloads is stripped for non-Owner viewers.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewCost = $viewer->isOwner();

        $userFilter = $request->query('user') ?: null;
        $actionFilter = $request->query('action') ?: null;
        $dateFrom = $this->normalizeDate($request->query('date_from'));
        $dateTo = $this->normalizeDate($request->query('date_to'));

        $entries = AuditLog::query()
            ->with('user:id,name,username')
            ->when($userFilter, fn ($query) => $query->where('user_id', $userFilter))
            ->when($actionFilter, fn ($query) => $query->where('action', $actionFilter))
            ->when($dateFrom, fn ($query) => $query->where('created_at', '>=', $dateFrom->startOfDay()))
            ->when($dateTo, fn ($query) => $query->where('created_at', '<=', $dateTo->endOfDay()))
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (AuditLog $entry) => [
                'id' => $entry->id,
                'action' => $entry->action,
                'description' => $entry->description,
                'auditable_type' => $entry->auditable_type,
                'auditable_id' => $entry->auditable_id,
                'old_values' => $this->redact($entry->old_values, $canViewCost),
                'new_values' => $this->redact($entry->new_values, $canViewCost),
                'created_at' => $entry->created_at,
                'user' => $entry->user ? [
                    'id' => $entry->user->id,
                    'name' => $entry->user->name,
                    'username' => $entry->user->username,
                ] : null,
            ]);

        return Inertia::render('audit-logs', [
            'entries' => $entries,
            // Archived users included so their historical actions stay filterable.
            'users' => User::withTrashed()->orderBy('name')->get(['id', 'name', 'username']),
            // Distinct actions actually present, for the filter dropdown.
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
            'filters' => [
                'user' => $userFilter,
                'action' => $actionFilter,
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
            ],
        ]);
    }

    /**
     * Recursively strip cost-bearing keys from an audit payload unless the viewer
     * is the Owner. Item Cost must never reach an Admin, including via the audit
     * trail, so any `cost` key at any depth is removed.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values, bool $canViewCost): ?array
    {
        if ($values === null || $canViewCost) {
            return $values;
        }

        $stripped = [];

        foreach ($values as $key => $value) {
            if ($key === 'cost') {
                continue;
            }

            $stripped[$key] = is_array($value) ? $this->redact($value, $canViewCost) : $value;
        }

        return $stripped;
    }

    /**
     * Parse a date filter param, discarding anything unparseable, else null.
     */
    private function normalizeDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            return null;
        }
    }
}
