<?php
declare(strict_types=1);

namespace Foodwell\Repositories;

use RuntimeException;

final class CartException extends RuntimeException
{
    public function __construct(public int $status, string $message)
    {
        parent::__construct($message);
    }
}
