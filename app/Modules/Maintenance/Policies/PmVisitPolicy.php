<?php

namespace App\Modules\Maintenance\Policies;

use App\Modules\Identity\Models\User;
use App\Modules\Identity\Policies\TenantPolicy;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Maintenance\Models\PmVisit;
use Illuminate\Database\Eloquent\Model;

/**
 * pm-visits.* for PM work orders. They have no branch; customer = work orders of the account's
 * customer; project = those of the user's project teams; own = those the user is the technician of.
 *
 *   create (a work order by hand)                     pm-visits.create
 *   update (schedule, assign, equipment), cancel      pm-visits.update
 *   perform (start, record, photos, sign, submit)     pm-visits.complete
 *   review (pass on handed-in work)                   pm-visits.review
 *   approve (approve, reopen an approved report)      pm-visits.approve
 *   publish (publish, close)                          pm-visits.publish
 *   returnWork (send back for revision)               review while submitted, approve while pending approval
 *   share (customers' report links)                   pm-reports.share
 *
 * Which status a move needs is PmWorkflow's; these say only who.
 */
class PmVisitPolicy extends TenantPolicy
{
    protected string $resource = 'pm-visits';

    protected ?string $projectColumn = 'contract_id';

    protected array $actions = ['perform' => 'complete', 'cancel' => 'update'];

    protected ?string $branchColumn = null;

    public function perform(User $user, Model $visit): bool
    {
        return $this->permits($user, 'perform') && $this->inScope($user, $visit, 'perform');
    }

    public function cancel(User $user, Model $visit): bool
    {
        return $this->update($user, $visit);
    }

    public function review(User $user, Model $visit): bool
    {
        return $this->permits($user, 'review') && $this->inScope($user, $visit, 'review');
    }

    public function approve(User $user, Model $visit): bool
    {
        return $this->permits($user, 'approve') && $this->inScope($user, $visit, 'approve');
    }

    public function publish(User $user, Model $visit): bool
    {
        return $this->permits($user, 'publish') && $this->inScope($user, $visit, 'publish');
    }

    public function returnWork(User $user, Model $visit): bool
    {
        return $visit->getAttribute('status') === PmVisit::STATUS_SUBMITTED ? $this->review($user, $visit) : $this->approve($user, $visit);
    }

    /** Make and revoke customers' links to the report (pm-reports.share, within its scope). */
    public function share(User $user, Model $visit): bool
    {
        return $this->reaches($user, $visit, 'pm-reports.share');
    }

    /**
     * The user holds $permission (another resource's) and reaches this work order with it.
     */
    private function reaches(User $user, Model $visit, string $permission): bool
    {
        return $user->checkPermissionTo($permission)
            && (int) $visit->getAttribute('tenant_id') === $this->tenantIdOf($user)
            && DataScope::covers($visit, $user, $permission, $this->branchColumn, $this->customerColumn,
                fn (Model $record) => $this->owns($user, $record), $this->project());
    }

    protected function owns(User $user, Model $model): bool
    {
        return $model->getAttribute('assignee_id') !== null && (int) $model->getAttribute('assignee_id') === (int) $user->id;
    }
}
