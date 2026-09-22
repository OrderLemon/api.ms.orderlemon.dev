<?php

declare(strict_types=1);

namespace Pmsrapi\V2\Exception;

final class ConflictException extends ApiException
{
    public function __construct(string $message = 'Resource conflict')
    {
        parent::__construct($message, 409, 'conflict');
    }
}
