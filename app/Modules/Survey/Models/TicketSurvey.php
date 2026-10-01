<?php

namespace App\Modules\Survey\Models;

use App\Modules\Survey\Policies\TicketSurveyPolicy;
use App\Modules\Tenancy\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\UsePolicy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The satisfaction survey of one closed ticket. Ticket number, title and ulid are copied from the
 * ticket (Service module); customer and users are read through the actions of their modules.
 */
#[UsePolicy(TicketSurveyPolicy::class)]
class TicketSurvey extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const MIN_SCORE = 1;

    public const MAX_SCORE = 5;

    protected $fillable = [
        'ticket_id', 'ticket_ulid', 'ticket_no', 'ticket_title', 'customer_id', 'assignee_id', 'token',
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
