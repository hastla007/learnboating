<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Favorite extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    public const CONTEXT_SMART_LEARNING = 'smart_learning';

    public const CONTEXT_EXAM = 'exam';

    protected $table = 'favorite';

    protected array $compositeKey = ['tenant_id', 'user_id', 'question_id', 'context'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'question_id', 'context'];

    // Review (Datenexport 500): created_at setzt die Datenbank (keine
    // Eloquent-Timestamps); ohne Cast war es ein String und der DSGVO-Export
    // brach mit "toIso8601String() on string" ab.
    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(ContentQuestion::class, 'question_id');
    }
}
