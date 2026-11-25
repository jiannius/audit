<?php

namespace Jiannius\Audit\Exceptions;

use Illuminate\Contracts\Database\ConcurrencyErrorDetector as ConcurrencyErrorDetectorContract;
use Illuminate\Database\ConcurrencyErrorDetector;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * The audit insert failed. It carries the table and the database error codes
 * and nothing else: the driver's own message holds the SQL and every bound
 * value, so neither the message nor a previous exception has any of it.
 */
class AuditWriteFailed extends RuntimeException
{
    /** The phrase Laravel's concurrency detector recognises, so DB::transaction() still retries. */
    private const RETRYABLE_PHRASE = 'Deadlock found when trying to get lock';

    public function __construct(
        public readonly string $table,
        public readonly ?string $sqlState = null,
        public readonly int|string|null $driverCode = null,
        public readonly bool $retryable = false,
    ) {
        $message = sprintf(
            'The audit row could not be written to table [%s] (SQLSTATE %s, driver code %s).',
            $table,
            $sqlState ?? 'unknown',
            $driverCode ?? 'unknown',
        );

        if ($retryable || $sqlState === '40001') {
            $message .= ' Concurrency error, the transaction can be retried ('.self::RETRYABLE_PHRASE.').';
        }

        parent::__construct($message);
    }

    public static function from(QueryException $e, string $table): self
    {
        return new self($table, ...self::describe($e));
    }

    /**
     * The SQLSTATE, the driver code and whether Laravel treats the error as a
     * retryable concurrency error (deadlock, lock wait timeout, a busy SQLite
     * file, serialization failure), judged on the original exception.
     *
     * @return array{0: ?string, 1: int|string|null, 2: bool}
     */
    public static function describe(QueryException $e): array
    {
        $info = is_array($e->errorInfo) ? $e->errorInfo : [];

        return [
            isset($info[0]) ? (string) $info[0] : null,
            $info[1] ?? null,
            self::detector()?->causedByConcurrencyError($e) ?? false,
        ];
    }

    private static function detector(): ?ConcurrencyErrorDetectorContract
    {
        if (app()->bound(ConcurrencyErrorDetectorContract::class)) {
            return app(ConcurrencyErrorDetectorContract::class);
        }

        return class_exists(ConcurrencyErrorDetector::class) ? new ConcurrencyErrorDetector : null;
    }
}
