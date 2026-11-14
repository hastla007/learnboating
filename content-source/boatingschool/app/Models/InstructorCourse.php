<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasCompositePrimaryKey;
use Illuminate\Database\Eloquent\Model;

/** Kurs, für den ein Ausbilder zuständig ist (ohne Zuordnung: alle Kurse der Schule). */
class InstructorCourse extends Model
{
    use BelongsToTenant, HasCompositePrimaryKey;

    protected $table = 'instructor_course';

    protected array $compositeKey = ['tenant_id', 'user_id', 'course_id'];

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = ['tenant_id', 'user_id', 'course_id'];
}
