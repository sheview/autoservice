<?php

namespace App\Modules\Maintenance\Actions;

use App\Modules\Identity\Models\User;
use App\Modules\Maintenance\Models\PmVisit;
use App\Modules\Maintenance\Support\PmWorkflow;
use Illuminate\Validation\ValidationException;

/**
 * The same move for several work orders picked on the list (the end of a PM round: review,
 * approve, publish or close them all), each through MovePmVisit as if pressed on its own page.
 * One the actor may not move, or that cannot make the move (another status, a signature the
 * company asks for), is skipped with why; the others still go.
 */
class MovePmVisits
{
    /** Moves that need nothing typed or drawn, so they can be made for many at once. */
    public const ACTIONS = ['review', 'approve', 'publish', 'close'];

    public function __construct(private MovePmVisit $move) {}

    /**
     * @param  list<string>  $ulids
     * @return array{moved: int, skipped: list<string>} skipped: "PM-… : why"
     */
    public function handle(array $ulids, string $action, User $actor): array
    {
        $moved = 0;
        $skipped = [];

        foreach (PmVisit::query()->whereIn('ulid', $ulids)->orderBy('visit_no')->get() as $visit) {
            if (! $actor->can(PmWorkflow::ACTIONS[$action]['ability'], $visit)) {
                $skipped[] = "{$visit->visit_no}: ".__('maintenance.visits.bulk_forbidden');

                continue;
            }

            try {
                $this->move->handle($visit, $action, $actor);
                $moved++;
            } catch (ValidationException $e) {
                $skipped[] = "{$visit->visit_no}: ".collect($e->errors())->flatten()->first();
            }
        }

        return ['moved' => $moved, 'skipped' => $skipped];
    }
}
