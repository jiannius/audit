<?php

namespace Jiannius\Audit;

use App\Models\Audit;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Support\Facades\Schema;
use Jiannius\Audit\AuditableObserver;

trait Auditable
{
    public $auditsCollection = [];

    /**
     * Boot the auditable trait.
     */
    protected static function bootAuditable() : void
    {
        static::observe(new AuditableObserver);
    }

    /**
     * Get the audits for the model.
     */
    public function audits() : MorphMany
    {
        return $this->morphMany(Audit::class, 'auditable');
    }

    /**
     * Audit the model.
     */
    public function audit($action, $data = [], $persist = true)
    {
        $user = auth()->user();
        $changes = $action === 'updated' ? $this->getAuditableChanges() : [];
        $isTouched = $action === 'updated' && count($changes) === 1 && data_get(head($changes), 'key') === 'updated_at';
        $action = $isTouched ? 'touched' : $action;
        $ref = $this->number ?? $this->name ?? $this->title;

        if ($user) $data = ['user' => ['name' => $user->name, 'email' => $user->email], ...$data];
        if (!$isTouched && $changes) $data = ['changes' => $changes, ...$data];

        $this->auditsCollection = collect($this->auditsCollection)
            ->push($this->transformAuditData([
                'action' => $action,
                'reference' => $ref,
                'ip' => request()->ip(),
                'agent' => request()->header('user-agent'),
                'data' => $data,
                'user_id' => $user?->id,
            ]))
            ->toArray();

        return $persist ? $this->persistAudits() : $this;
    }

    /**
     * Get the changes for the model.
     */
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

    /**
     * Transform the audit data.
     */
    public function transformAuditData($data)
    {
        return $data;
    }

    /**
     * Persist the audits.
     */
    public function persistAudits()
    {
        foreach ($this->auditsCollection as $audit) {
            $action = data_get($audit, 'action');

            if (in_array($action, ['created', 'updated', 'touched'])) {
                if (Schema::hasColumn($this->getTable(), 'data')) {
                    $data = array_filter((array) data_get($this->data, 'audit'));
                    $key = $action === 'touched' ? 'updated' : $action;
                    $data[$key] = [
                        'user' => data_get($audit, 'data.user'),
                        'timestamp' => now()->toIso8601ZuluString(),
                    ];

                    $this->data = [...(array) $this->data, 'audit' => $data];
                    $this->saveQuietly();
                }
            }

            $this->audits()->saveQuietly(new Audit($audit));
        }

        $this->auditsCollection = [];

        return $this;
    }
}