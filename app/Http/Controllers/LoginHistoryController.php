<?php

namespace App\Http\Controllers;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Login History display (Phase 14). Read-only view over the append-only
 * login_history captured since Phase 2 — no capture logic lives here.
 *
 * Role scoping: a Cashier may only ever see their own events; Admins and the
 * Owner see everyone and may filter by user. The own-only constraint is applied
 * server-side and cannot be widened by a crafted `user` query parameter.
 */
class LoginHistoryController extends Controller
{
    public function index(Request $request): Response
    {
        $viewer = $request->user();
        $canViewAll = $viewer->isAdmin() || $viewer->isOwner();

        $userFilter = $canViewAll ? ($request->query('user') ?: null) : $viewer->getKey();
        $eventFilter = $this->normalizeEvent($request->query('event'));
        $dateFrom = $this->normalizeDate($request->query('date_from'));
        $dateTo = $this->normalizeDate($request->query('date_to'));

        $entries = LoginHistory::query()
            ->with('user:id,name,username')
            ->when($userFilter, fn ($query) => $query->where('user_id', $userFilter))
            ->when($eventFilter, fn ($query) => $query->where('event_type', $eventFilter))
            // Filter on the displayed "When" value (occurred_at falls back to
            // created_at) so the range matches exactly what the table shows.
            ->when($dateFrom, fn ($query) => $query->whereRaw(
                'COALESCE(occurred_at, created_at) >= ?',
                [$dateFrom->startOfDay()->format('Y-m-d H:i:s')],
            ))
            ->when($dateTo, fn ($query) => $query->whereRaw(
                'COALESCE(occurred_at, created_at) <= ?',
                [$dateTo->endOfDay()->format('Y-m-d H:i:s')],
            ))
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (LoginHistory $entry) => [
                'id' => $entry->id,
                'event_type' => $entry->event_type,
                'occurred_at' => $entry->occurred_at,
                'created_at' => $entry->created_at,
                'notes' => $entry->notes,
                'user' => $entry->user ? [
                    'id' => $entry->user->id,
                    'name' => $entry->user->name,
                    'username' => $entry->user->username,
                ] : null,
            ]);

        return Inertia::render('login-history', [
            'entries' => $entries,
            'canViewAll' => $canViewAll,
            // Only offered to those who may see everyone; archived users included
            // so their historical events stay filterable.
            'users' => $canViewAll
                ? User::withTrashed()->orderBy('name')->get(['id', 'name', 'username'])
                : [],
            'eventTypes' => array_map(fn (LoginEventType $type) => $type->value, LoginEventType::cases()),
            'filters' => [
                'user' => $canViewAll ? $userFilter : null,
                'event' => $eventFilter,
                'date_from' => $dateFrom?->toDateString(),
                'date_to' => $dateTo?->toDateString(),
            ],
        ]);
    }

    /**
     * Constrain the event filter to a known LoginEventType value, else null.
     */
    private function normalizeEvent(mixed $event): ?string
    {
        return LoginEventType::tryFrom((string) $event)?->value;
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
