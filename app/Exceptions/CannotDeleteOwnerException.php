<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when something attempts to delete (soft or force) the Owner account.
 * The Owner can never be archived or deleted — a core business invariant.
 */
class CannotDeleteOwnerException extends RuntimeException
{
    public function __construct(string $message = 'The Owner account cannot be deleted.')
    {
        parent::__construct($message);
    }
}
