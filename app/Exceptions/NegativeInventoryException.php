<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a ledger write would drive a variant's on-hand stock below zero.
 * Negative inventory is never allowed and is enforced at write time — a core
 * business invariant (CLAUDE.md). Framework-agnostic so every movement path
 * (manual adjustment, POS sale, CSV import) can rely on it; controllers
 * translate it into a user-facing validation error.
 */
class NegativeInventoryException extends RuntimeException
{
    public function __construct(string $message = 'This change would drive stock below zero, which is not allowed.')
    {
        parent::__construct($message);
    }
}
