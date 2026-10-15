<?php

namespace App\Modules\Survey\Models;

use App\Modules\Survey\Policies\TicketSurveyPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The satisfaction survey of one closed ticket, or of one PM work order (pm_visit_id, answered when
 * the customer signs it; ticket_no / ticket_ulid / ticket_title then hold the work order's). Number,
 * title and ulid are copied (Service / Maintenance modules); customer and users are read through the
 * actions of their modules.
 */
#[UsePolicy(TicketSurveyPolicy::class)]
class TicketSurvey extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const MIN_SCORE = 1;

    public const MAX_SCORE = 5;

    /** The four levels of a PM work order's survey (as on the paper form), as scores of the same scale. */
    public const PM_LEVELS = ['excellent' => 5, 'good' => 4, 'fair' => 3, 'poor' => 2];

    protected $fillable = [
        'ticket_id', 'pm_visit_id', 'ticket_ulid', 'ticket_no', 'ticket_title', 'customer_id', 'assignee_id', 'token',
        'score', 'comment', 'answered_at', 'answered_by', 'answered_name', 'on_paper',
    ];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return [
            'score' => 'integer',
            'answered_at' => 'datetime',
            'on_paper' => 'boolean',
        ];
    }

    public function isAnswered(): bool
    {
        return $this->answered_at !== null;
    }
}
