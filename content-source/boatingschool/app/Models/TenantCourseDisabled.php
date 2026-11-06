<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TenantCourseDisabled extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'tenant_course_disabled';

    protected array $compositeKey = ['tenant_id', 'course_id'];

    public $incrementing = false;

    const CREATED_AT = 'created_at';

    const UPDATED_AT = null;

    protected $fillable = ['tenant_id', 'course_id'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }
}
