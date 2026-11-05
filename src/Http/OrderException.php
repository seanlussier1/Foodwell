<?php
declare(strict_types=1);

namespace Foodwell\Http;

use RuntimeException;

final class OrderException extends RuntimeException
{
    public function __construct(
        public int $statusCode,
        string $message
    ) {
        parent::__construct($message);
    }
}
