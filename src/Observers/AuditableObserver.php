<?php

namespace Jiannius\Audit\Observers;

class AuditableObserver
{
    public function creating($auditable)
    {
        $auditable->audit(action: 'created', persist: false);
    }

    public function updating($auditable)
    {
        $auditable->audit(action: 'updated', persist: false);
    }

    public function deleting($auditable)
    {
        $auditable->audit(action: 'deleted', persist: false);
    }

    public function saved($auditable)
    {
        $auditable->persistAudits();
    }

    public function deleted($auditable)
    {
        $auditable->persistAudits();
    }
}