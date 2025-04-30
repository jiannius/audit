<?php

namespace Jiannius\Audit\Traits;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Jiannius\Audit\Observers\AuditableObserver;

trait Auditable
{
    public $auditsCollection = [];

    protected static function bootAuditable() : void
    {
        static::observe(new AuditableObserver);
    }

    public function audits() : MorphMany
    {
        return $this->morphMany(Audit::class, 'auditable');
    }

    public function audit($action, $data = [], $persist = true)
    {
        $changes = $this->getAuditableChanges();
        $isTouched = $action === 'updated' && count($changes) === 1 && data_get(head($changes), 'key') === 'updated_at';
        $action = $isTouched ? 'touched' : $action;
        $ref = $this->number ?? $this->name ?? $this->title;
        $data = !$isTouched && $changes ? ['changes' => $changes, ...$data] : $data;

        $this->auditsCollection = collect($this->auditsCollection)
            ->push($this->transformAuditData([
                'action' => $action,
                'reference' => $ref,
                'ip' => request()->ip(),
                'agent' => request()->header('user-agent'),
                'data' => $data,
                'user_id' => auth()->id(),
            ]))
            ->toArray();

        return $persist ? $this->persistAudits() : $this;
    }

    public function getAuditableChanges()
    {
        $original = $this->getOriginal();
        $dirty = $this->getDirty();
        $changes = [];

        foreach ($dirty as $key => $value) {
            $old = data_get($original, $key);

            if ($old !== $value) {
                $changes[] = compact('key', 'old', 'value');
            }
        }

        return $changes;
    }

    public function transformAuditData($data)
    {
        return $data;
    }

    public function persistAudits()
    {
        foreach ($this->auditsCollection as $audit) {
            $this->audits()->saveQuietly(new Audit($audit));
        }

        $this->auditsCollection = [];

        return $this;
    }
}