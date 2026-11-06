<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Zuordnung einer Frage zu einer FavoriteCollection -- Many-to-many
 * (eine Frage kann gleichzeitig in mehreren Collections liegen), getrennt
 * nach Smart-Learning/Prüfungsfragen (context), analog zu Favorite.
 */
class FavoriteCollectionItem extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    public const CONTEXT_SMART_LEARNING = Favorite::CONTEXT_SMART_LEARNING;

    public const CONTEXT_EXAM = Favorite::CONTEXT_EXAM;

    protected $table = 'favorite_collection_item';

    protected array $compositeKey = ['favorite_collection_id', 'question_id', 'context'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['favorite_collection_id', 'tenant_id', 'question_id', 'context'];

    public function collection(): BelongsTo
    {
        return $this->belongsTo(FavoriteCollection::class, 'favorite_collection_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentQuestion::class, 'question_id');
    }
}
