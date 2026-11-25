<?php

namespace Jiannius\Audit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use ReflectionProperty;
use Throwable;

/**
 * The one place that decides which attributes are never stored (excluded) and
 * which are stored as "[redacted]". The observer and the Recorder (for the
 * record label) both ask it, so a secret cannot leak through one of them.
 */
class AttributeRules
{
    public const REDACTED = '[redacted]';

    /** @var array<class-string, list<string>> */
    private static array $declaredHidden = [];

    public function isExcluded(Model $model, string $key): bool
    {
        return Str::is($this->excludePatterns($model), $key);
    }

    /** True when the attribute is redacted, and also when it is excluded: neither may be shown. */
    public function isSecret(Model $model, string $key): bool
    {
        return $this->isExcluded($model, $key) || Str::is($this->redactPatterns($model), $key);
    }

    /**
     * Apply the rules to a set of raw attributes: drop the excluded, redact the secret,
     * and give booleans the shape the database stores them in.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function values(Model $model, array $attributes): array
    {
        $exclude = $this->excludePatterns($model);
        $redact = $this->redactPatterns($model);
        $values = [];

        foreach ($attributes as $key => $value) {
            $key = (string) $key;

            if (Str::is($exclude, $key)) {
                continue;
            }

            if ($value !== null && Str::is($redact, $key)) {
                $value = self::REDACTED;
            }

            $values[$key] = is_bool($value) ? (int) $value : $value;
        }

        return $values;
    }

    /** @return list<string> */
    public function excludePatterns(Model $model): array
    {
        return array_values(array_filter(array_merge(
            (array) config('audit.attributes.exclude', []),
            method_exists($model, 'auditExclude') ? $model->auditExclude() : [],
            [$model->getCreatedAtColumn(), $model->getUpdatedAtColumn()],
        ), fn ($pattern) => is_string($pattern) && $pattern !== ''));
    }

    /** @return list<string> */
    public function redactPatterns(Model $model): array
    {
        $patterns = array_merge(
            (array) config('audit.attributes.redact', []),
            method_exists($model, 'auditRedact') ? $model->auditRedact() : [],
        );

        if (config('audit.attributes.redact_hidden', true)) {
            // The class's own $hidden as well as the instance's: makeVisible() must not un-redact.
            // $visible is a display whitelist, not a redaction source.
            $patterns = array_merge($patterns, $model->getHidden(), $this->declaredHidden($model));
        }

        if (config('audit.attributes.redact_encrypted', true)) {
            foreach ($model->getCasts() as $key => $cast) {
                if ($this->isSecretCast($cast)) {
                    $patterns[] = (string) $key;
                }
            }
        }

        return array_values($patterns);
    }

    /** @return list<string> */
    private function declaredHidden(Model $model): array
    {
        return self::$declaredHidden[$model::class] ??= $this->freshHidden($model);
    }

    /**
     * What a pristine instance hides. A fresh instance, because #[Hidden] attributes and
     * #[Initialize] trait initialisers merge into the instance in the constructor, so the
     * property default alone misses them. If the model cannot be built without arguments,
     * the property default is the best baseline.
     *
     * @return list<string>
     */
    private function freshHidden(Model $model): array
    {
        $default = (new ReflectionProperty($model, 'hidden'))->getDefaultValue();
        $hidden = is_array($default) ? $default : [];

        try {
            $hidden = array_merge($hidden, (new ($model::class))->getHidden());
        } catch (Throwable) {
            // keep the property default
        }

        return array_values(array_unique($hidden));
    }

    /** encrypted, encrypted:array, hashed, AsEncryptedArrayObject and friends. */
    private function isSecretCast(mixed $cast): bool
    {
        $name = is_object($cast) ? $cast::class : (is_string($cast) ? $cast : '');
        $name = strtolower(class_basename(Str::before($name, ':')));

        return str_starts_with($name, 'encrypted') || str_starts_with($name, 'asencrypted') || $name === 'hashed';
    }
}
