<?php

namespace Jiannius\Audit\Exceptions;

use LogicException;

class ImmutableAuditException extends LogicException
{
    public static function forAction(string $action): self
    {
        return new self("Audit rows are append-only and cannot be {$action} through the model.");
    }
}
