<?php

namespace Jiannius\Audit;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Context\AuditContext;

/**
 * The logger. Use it through the Auditor facade.
 */
class Auditor
{
    public function __construct(protected Recorder $recorder) {}

    /**
     * Record a custom event.
     *
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function record(
        string $event,
        ?Model $subject = null,
        array $meta = [],
        array $old = [],
        array $new = [],
        string $category = Category::ACTION,
        ?Model $parent = null,
    ): void {
        $this->recorder->record(new PendingAudit($event, $category, $subject, $parent, $meta, $old, $new));
    }

    /** Run the callback with the given actor, or with nobody ("System") when null. */
    public function actingAs(?Authenticatable $actor, Closure $callback): mixed
    {
        return $this->context()->actingAs($actor, $callback);
    }

    /** Run the callback with a fixed source, for example "backfill:fix-totals". */
    public function withSource(string $source, Closure $callback): mixed
    {
        return $this->context()->withSource($source, $callback);
    }

    private function context(): AuditContext
    {
        return app(AuditContext::class);
    }
}
