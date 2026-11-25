<?php

namespace Jiannius\Audit;

use Illuminate\Database\Eloquent\Model;
use Jiannius\Audit\Context\Actor;

/**
 * One audit entry on its way to the Recorder. Scope resolvers receive it, so
 * they can read the subject, the parent, the actor and the event.
 */
class PendingAudit
{
    public ?Actor $actor = null;

    public bool $actorResolved = false;

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function __construct(
        public string $event,
        public string $category = Category::ACTION,
        public ?Model $subject = null,
        public ?Model $parent = null,
        public array $meta = [],
        public array $old = [],
        public array $new = [],
    ) {}

    /** Set the actor explicitly, including "nobody", instead of resolving it from the context. */
    public function actor(?Actor $actor): static
    {
        $this->actor = $actor;
        $this->actorResolved = true;

        return $this;
    }
}
