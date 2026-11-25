<?php

namespace Jiannius\Audit;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Jiannius\Audit\Context\AuditContext;
use Jiannius\Audit\Exceptions\AuditWriteFailed;
use JsonException;
use JsonSerializable;
use Stringable;
use UnitEnum;

/**
 * The only code that inserts audit rows. It writes through the query builder,
 * synchronously, on the subject's connection, so a row joins the transaction
 * of the change it records.
 */
class Recorder
{
    /** MySQL TEXT holds 65,535 bytes. */
    private const TEXT_BYTES = 65535;

    public function record(PendingAudit $audit): void
    {
        if (! config('audit.enabled', true)) {
            return;
        }

        if (! in_array($audit->category, Category::all(), true)) {
            throw new InvalidArgumentException('Unknown audit category ['.$audit->category.']. Use one of: '.implode(', ', Category::all()).'.');
        }

        $context = app(AuditContext::class);

        if (! $audit->actorResolved) {
            $audit->actor($context->actor());
        }

        $row = [
            'category' => $this->limit($audit->category, 16),
            'event' => $this->limit($audit->event, 64),
            'subject_type' => $this->typeOf($audit->subject),
            'subject_id' => $this->idOf($audit->subject),
            'subject_label' => $audit->subject ? $this->limit($this->labelFor($audit->subject), 255) : null,
            'parent_type' => $this->typeOf($audit->parent),
            'parent_id' => $this->idOf($audit->parent),
            'actor_type' => $this->limit($audit->actor?->type, 64),
            'actor_id' => $this->limit($audit->actor?->id, 64),
            'actor_name' => $this->limit($audit->actor?->name, 255),
            'actor_identifier' => $this->limit($audit->actor?->identifier, 255),
        ];

        foreach ($this->scopeResolvers() as $column => $resolver) {
            $row[$column] = $resolver === null ? null : $this->callResolver($resolver, $audit);
        }

        $row += [
            'source' => $this->limit($context->source(), 191),
            'request_id' => $this->limit($context->requestId(), 64),
            'ip_address' => $this->limit($context->ipAddress(), 45),
            'user_agent' => $this->limitBytes($context->userAgent(), self::TEXT_BYTES),
            'old_values' => $this->encode($audit->old),
            'new_values' => $this->encode($audit->new),
            'meta' => $this->encode($audit->meta),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        $table = config('audit.table', 'audits');

        try {
            $this->connectionFor($audit->subject)->table($table)->insert($row);
        } catch (QueryException $e) {
            // The original message carries the SQL with every binding: values, emails, IPs.
            // Throw a new exception that holds none of it, do not chain the original, and
            // do not pass it to a function that appears in the trace with its arguments.
            [$sqlState, $driverCode, $retryable] = AuditWriteFailed::describe($e);

            throw new AuditWriteFailed($table, $sqlState, $driverCode, $retryable);
        }
    }

    /** The label snapshot: the model's own auditLabel() when it has one, else the default. */
    public function labelFor(Model $model): ?string
    {
        if (method_exists($model, 'auditLabel')) {
            $label = $model->auditLabel();

            return is_scalar($label) || $label instanceof Stringable ? (string) $label : null;
        }

        return $this->defaultLabel($model);
    }

    /** The first filled attribute listed in config('audit.attributes.label'), read strict-safely from the raw attributes. */
    public function defaultLabel(Model $model): ?string
    {
        $attributes = $model->getAttributes();

        foreach ((array) config('audit.attributes.label', []) as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $value = $attributes[$key];

            if ((is_scalar($value) || $value instanceof Stringable) && (string) $value !== '') {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * Make a value safe to store: scalars and arrays only, valid UTF-8.
     * A legitimate save must never be blocked by an awkward value.
     */
    public function sanitise(mixed $value): mixed
    {
        $cap = (int) config('audit.max_value_bytes', 65536);

        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_string($value) => $this->sanitiseString($value, $cap),
            is_array($value) => array_map(fn ($item) => $this->sanitise($item), $value),
            $value instanceof BackedEnum => $this->sanitise($value->value),
            $value instanceof UnitEnum => $value->name,
            $value instanceof DateTimeInterface => $value->format('Y-m-d H:i:s'),
            $value instanceof JsonSerializable => $this->sanitise($value->jsonSerialize()),
            $value instanceof Stringable => $this->sanitise((string) $value),
            is_object($value) => '[object '.$value::class.']',
            default => '['.get_debug_type($value).']',
        };
    }

    /** Valid UTF-8 only, and over the byte cap only a marker: the size is kept, the content is not. */
    private function sanitiseString(string $value, int $cap): string
    {
        if (! mb_check_encoding($value, 'UTF-8')) {
            return '[binary '.strlen($value).' bytes]';
        }

        return $cap > 0 && strlen($value) > $cap ? '[truncated '.strlen($value).' bytes]' : $value;
    }

    private function limit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->sanitiseString($value, 0);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function limitBytes(?string $value, int $bytes): ?string
    {
        return $value === null ? null : mb_strcut($this->sanitiseString($value, 0), 0, $bytes);
    }

    private function encode(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        try {
            return json_encode($this->sanitise($values), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            // Never write null for values that existed, and never block the save: say what happened.
            return json_encode(['_audit' => '[values could not be encoded]']);
        }
    }

    private function typeOf(?Model $model): ?string
    {
        return $this->limit($model?->getMorphClass(), 64);
    }

    private function idOf(?Model $model): ?string
    {
        $key = $model?->getKey();

        return $key === null ? null : $this->limit((string) $key, 64);
    }

    private function connectionFor(?Model $subject): ConnectionInterface
    {
        $configured = config('audit.connection');

        if (is_string($configured) && $configured !== '') {
            return DB::connection($configured);
        }

        return $subject?->getConnection() ?? DB::connection();
    }

    /** @return array<string, mixed> column => resolver (null when a scope has none) */
    private function scopeResolvers(): array
    {
        $resolvers = [];

        foreach ((array) config('audit.scopes', []) as $column => $scope) {
            $resolvers[$column] = is_array($scope) ? ($scope['resolver'] ?? null) : $scope;
        }

        return $resolvers;
    }

    private function callResolver(mixed $resolver, PendingAudit $audit): string|int|null
    {
        $callable = is_callable($resolver) && ! is_string($resolver) ? $resolver : app($resolver);
        $value = $callable($audit);

        return (is_string($value) && $value !== '') || is_int($value) ? $value : null;
    }
}
