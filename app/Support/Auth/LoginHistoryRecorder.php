<?php

namespace App\Support\Auth;

use App\Enums\LoginEventType;
use App\Models\LoginHistory;
use App\Models\User;
use Carbon\CarbonInterface;

/**
 * The single write-path into the append-only login_history table.
 */
class LoginHistoryRecorder
{
    public function record(
        User $user,
        LoginEventType $type,
        ?CarbonInterface $occurredAt = null,
        ?string $notes = null,
    ): LoginHistory {
        return LoginHistory::create([
            'user_id' => $user->getKey(),
            'event_type' => $type,
            'occurred_at' => $occurredAt ?? now(),
            'notes' => $notes,
        ]);
    }
}
