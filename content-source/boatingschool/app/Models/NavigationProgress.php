<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Lernstand je Teilfrage einer Navigationsaufgabe (eigene Antwort, richtig/falsch). */
class NavigationProgress extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'navigation_progress';

    protected array $compositeKey = ['tenant_id', 'user_id', 'navigation_question_id'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'navigation_question_id', 'answer', 'correct', 'updated_at'];

    protected function casts(): array
    {
        return ['correct' => 'boolean', 'updated_at' => 'datetime'];
    }
}
