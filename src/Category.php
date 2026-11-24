<?php

namespace Jiannius\Audit;

final class Category
{
    public const RECORD = 'record';

    public const AUTH = 'auth';

    public const ACTION = 'action';

    /** @return list<string> */
    public static function all(): array
    {
        return [self::RECORD, self::AUTH, self::ACTION];
    }
}
