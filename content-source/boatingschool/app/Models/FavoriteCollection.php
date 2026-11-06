<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Benutzerdefinierte Gruppierung von Favoriten innerhalb eines Kurses
 * (siehe FavoriteCollectionService) -- jeder Kurs bekommt lazy eine nicht
 * löschbare Default-Collection, in die neue Favoriten automatisch
 * einsortiert werden, wenn der Lerner keine eigene Collection wählt.
 */
class FavoriteCollection extends Model
{
    use BelongsToTenant, HasUuids;

    protected $table = 'favorite_collection';

    protected $fillable = ['tenant_id', 'user_id', 'course_id', 'name', 'is_default', 'sort_order'];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FavoriteCollectionItem::class, 'favorite_collection_id');
    }

    public function questions(): BelongsToMany
    {
        return $this->belongsToMany(ContentQuestion::class, 'favorite_collection_item', 'favorite_collection_id', 'question_id')
            ->withPivot('context');
    }
}
