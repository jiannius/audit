<?php

namespace Jiannius\Audit\Relations;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A MorphMany that always binds the subject id as a string. The column is a
 * varchar, and on MySQL comparing it with an integer defeats its index.
 */
class SubjectAudits extends MorphMany
{
    public function getParentKey(): string
    {
        return (string) parent::getParentKey();
    }

    /** @return array<int, string> */
    protected function getKeys(array $models, $key = null)
    {
        return array_map(strval(...), parent::getKeys($models, $key));
    }

    /** Never inline integer keys into the query: they must stay bound strings. */
    protected function whereInMethod(Model $model, $key)
    {
        return 'whereIn';
    }
}
