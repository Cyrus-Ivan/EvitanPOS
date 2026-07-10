<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown while applying a validated CSV import row when the movement is
 * rejected at write time (e.g. NegativeInventoryException). Carries the source
 * line number so the whole import can be rolled back and the offending row
 * reported to the user.
 */
class ImportRowException extends RuntimeException
{
    public function __construct(public readonly int $csvLine, string $message)
    {
        parent::__construct($message);
    }
}
