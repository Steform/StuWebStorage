<?php

declare(strict_types=1);

namespace App\Service\Http;

use RuntimeException;

/**
 * @brief Raised when an HTTP Range header cannot be satisfied.
 * @date 2026-06-26
 * @author Stephane H.
 */
final class InvalidByteRangeException extends RuntimeException
{
}
