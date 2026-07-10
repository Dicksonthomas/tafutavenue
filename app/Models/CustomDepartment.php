<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A department a CR typed during registration that wasn't already in
 * ReferenceDataController::DEPARTMENTS_BY_FACULTY for their faculty - kept
 * so the next registrant from that faculty/department sees it in the list
 * instead of having to type it again.
 */
class CustomDepartment extends Model
{
    protected $fillable = [
        'faculty',
        'name',
    ];
}
