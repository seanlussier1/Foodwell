<?php
declare(strict_types=1);

namespace Foodwell\Services;

use RuntimeException;

final class PostalLookupException extends RuntimeException
{
    public function __construct(public string $reason)
    {
        parent::__construct('Postal-area lookup unavailable.');
    }
}
