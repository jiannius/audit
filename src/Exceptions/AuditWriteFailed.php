<?php

namespace Jiannius\Audit\Exceptions;

use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The audit insert failed. It carries the table and the database error codes
 * and nothing else: the driver's own message holds the SQL and every bound
 * value, and an error tracker must never receive those from this package.
 */
class AuditWriteFailed extends RuntimeException
{
    public function __construct(
        public readonly string $table,
        public readonly ?string $sqlState = null,
        public readonly int|string|null $driverCode = null,
    ) {
        $message = sprintf(
            'The audit row could not be written to table [%s] (SQLSTATE %s, driver code %s).',
            $table,
            $sqlState ?? 'unknown',
            $driverCode ?? 'unknown',
        );

        // So a surrounding DB::transaction($callback, $attempts) still retries a deadlock.
        if ($sqlState === '40001') {
            $message .= ' Deadlock found when trying to get lock.';
        }

        parent::__construct($message);
    }

    public static function from(QueryException $e, string $table): self
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];

        return new self(
            $table,
            isset($info[0]) ? (string) $info[0] : null,
            $info[1] ?? null,
        );
    }
}
