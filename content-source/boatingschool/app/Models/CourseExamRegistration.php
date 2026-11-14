<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Prüfungsanmeldung je Bootsschule und Kurs, siehe App\Support\ExamRegistration. */
class CourseExamRegistration extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'course_exam_registration';

    protected array $compositeKey = ['tenant_id', 'course_id'];

    public $incrementing = false;

    const CREATED_AT = null;

    const UPDATED_AT = 'updated_at';

    protected $fillable = ['tenant_id', 'course_id', 'mode'];

    public function course(): BelongsTo
    {
        return $this->belongsTo(CourseDefinition::class, 'course_id');
    }
}
