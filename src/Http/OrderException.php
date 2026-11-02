<?php
declare(strict_types=1);

namespace Foodwell\Http;

use RuntimeException;

final class OrderException extends RuntimeException
{
    public function __construct(
        public readonly int $statusCode,
        string $message
    ) {
        parent::__construct($message);
    }
}