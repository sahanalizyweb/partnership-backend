<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssignmentHistory extends Model
{
    use HasFactory;

    protected $table = 'assignment_history';

    public $timestamps = true;

    protected $fillable = ['assignment_id', 'from_status', 'to_status', 'changed_by', 'notes'];

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(Assignment::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
