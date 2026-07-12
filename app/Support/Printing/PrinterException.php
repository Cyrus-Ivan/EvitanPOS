<?php

namespace App\Support\Printing;

use RuntimeException;

/**
 * Thrown when a receipt fails to reach the printer (device missing, permission
 * denied, socket refused, short write). Carries a message safe to surface to
 * the operator. A print failure must never roll back a committed sale, so
 * callers catch this and downgrade to a warning/error toast.
 */
class PrinterException extends RuntimeException {}
