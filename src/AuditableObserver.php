<?php

namespace Jiannius\Audit;

class AuditableObserver
{
    /**
     * Handle the creating event.
     */
    public function creating($auditable)
    {
        $auditable->audit(action: 'created', persist: false);
    }

    /**
     * Handle the updating event.
     */
    public function updating($auditable)
    {
        $auditable->audit(action: 'updated', persist: false);
    }

    /**
     * Handle the deleting event.
     */
    public function deleting($auditable)
    {
        $auditable->audit(action: 'deleted', persist: false);
    }

    /**
     * Handle the saved event.
     */
    public function saved($auditable)
    {
        $auditable->persistAudits();
    }

    /**
     * Handle the deleted event.
     */
    public function deleted($auditable)
    {
        $auditable->persistAudits();
    }
}