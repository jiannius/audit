<?php

namespace Jiannius\Audit;

use BackedEnum;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Jiannius\Audit\Context\AuditContext;
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
    public function record(PendingAudit $audit): void
    {
        if (! config('audit.enabled', true)) {
            return;
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
            'user_agent' => $this->limit($context->userAgent(), 65000),
            'old_values' => $this->encode($audit->old),
            'new_values' => $this->encode($audit->new),
            'meta' => $this->encode($audit->meta),
            'created_at' => now()->format('Y-m-d H:i:s'),
        ];

        $this->connectionFor($audit->subject)
            ->table(config('audit.table', 'audits'))
            ->insert($row);
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
        return match (true) {
            $value === null, is_bool($value), is_int($value) => $value,
            is_float($value) => is_finite($value) ? $value : (string) $value,
            is_string($value) => mb_check_encoding($value, 'UTF-8') ? $value : '[binary '.strlen($value).' bytes]',
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

    private function limit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = $this->sanitise($value);

        return mb_strlen($value) > $length ? mb_substr($value, 0, $length) : $value;
    }

    private function encode(array $values): ?string
    {
        if ($values === []) {
            return null;
        }

        return json_encode(
            $this->sanitise($values),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR,
        ) ?: null;
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

        return is_scalar($value) ? $value : null;
    }
}
