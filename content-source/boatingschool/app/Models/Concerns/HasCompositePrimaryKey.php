<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/**
 * Eloquent unterstützt keine zusammengesetzten Primärschlüssel nativ. Diese
 * Tabellen (progress, favorite, tenant_user, exam_session_question) haben
 * laut schema.sql aber bewusst keinen künstlichen id-Surrogatschlüssel.
 * Modelle mit dieser Concern müssen `protected array $compositeKey` setzen.
 */
trait HasCompositePrimaryKey
{
    protected function setKeysForSaveQuery($query): Builder
    {
        foreach ($this->compositeKey as $column) {
            $query->where($column, '=', $this->original[$column] ?? $this->getAttribute($column));
        }

        return $query;
    }
}
