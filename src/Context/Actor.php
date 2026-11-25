<?php

namespace Jiannius\Audit\Context;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * A snapshot of who acted. Built from the attributes already loaded on the
 * model, so it never runs a query and never trips strict mode.
 */
final class Actor
{
    public function __construct(
        public readonly string $type,
        public readonly string $id,
        public readonly ?string $name = null,
        public readonly ?string $identifier = null,
    ) {}

    public static function from(?Authenticatable $user): ?self
    {
        if ($user === null) {
            return null;
        }

        $attributes = $user instanceof Model ? $user->getAttributes() : get_object_vars($user);
        $id = $user instanceof Model ? $user->getKey() : $user->getAuthIdentifier();

        if ($id === null) {
            return null;
        }

        return new self(
            type: $user instanceof Model ? $user->getMorphClass() : $user::class,
            id: (string) $id,
            name: self::scalar($attributes, config('audit.actor.name', 'name')),
            identifier: self::scalar($attributes, config('audit.actor.identifier', 'email')),
        );
    }

    /** @param  array{type?: mixed, id?: mixed, name?: mixed, identifier?: mixed}|null  $data */
    public static function fromArray(?array $data): ?self
    {
        if (! is_array($data) || ! isset($data['type'], $data['id'])) {
            return null;
        }

        return new self(
            (string) $data['type'],
            (string) $data['id'],
            isset($data['name']) ? (string) $data['name'] : null,
            isset($data['identifier']) ? (string) $data['identifier'] : null,
        );
    }

    /** @return array{type: string, id: string, name: ?string, identifier: ?string} */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'name' => $this->name,
            'identifier' => $this->identifier,
        ];
    }

    private static function scalar(array $attributes, mixed $key): ?string
    {
        if (! is_string($key) || ! array_key_exists($key, $attributes)) {
            return null;
        }

        $value = $attributes[$key];

        return is_scalar($value) || $value instanceof \Stringable ? (string) $value : null;
    }
}
