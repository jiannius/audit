<?php

namespace Jiannius\Audit\Retention;

use Closure;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Deletes audit rows in chunks, with query-builder deletes. The Audit model is never involved,
 * so its immutability guard and its read scopes stay out of the way.
 *
 * @internal
 */
final class Pruner
{
    public function connection(): ConnectionInterface
    {
        $name = config('audit.connection');

        return DB::connection(is_string($name) && $name !== '' ? $name : null);
    }

    public function table(): Builder
    {
        return $this->connection()->table(config('audit.table', 'audits'));
    }

    /**
     * Delete the rows the constraint selects, one chunk at a time, and return how many went.
     * With $pretend nothing is deleted and the count is what would be.
     *
     * @param  Closure(Builder): Builder  $constrain
     */
    public function delete(Closure $constrain, bool $pretend = false): int
    {
        if ($pretend) {
            return $constrain($this->table())->count();
        }

        $chunk = max(1, (int) config('audit.prune.chunk', 1000));
        $deleted = 0;

        do {
            // The last id of the next chunk: every row the constraint selects up to it is the chunk.
            $last = $constrain($this->table())->orderBy('id')->limit($chunk)->pluck('id')->last();

            if ($last === null) {
                break;
            }

            $gone = $constrain($this->table())->where('id', '<=', $last)->delete();
            $deleted += $gone;
        } while ($gone > 0);

        return $deleted;
    }
}
