<?php

namespace App\Modules\Service\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Platform\CrossTenant\ShareGateway;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Service\Actions\FieldTechnicianDirectory;
use App\Modules\Service\Actions\ReviewFieldTechnician;
use App\Modules\Service\Actions\SaveFieldTechnician;
use App\Modules\Service\Models\FieldTechnician;
use App\Modules\Service\Models\Ticket;
use App\Modules\Service\Support\ThaiProvinces;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The outside technicians who signed up through a company's link. The company that has the
 * register (module "field_technicians") approves, corrects and removes them; companies it shares
 * the register with (ability field-technicians.view) find the approved ones and call them. A ticket
 * picks one when making a link for an outside technician, those of the ticket's province first.
 */
class FieldTechnicianController extends Controller
{
    public const ABILITY = 'field-technicians.view';

    public function __construct(
        private TenantContext $context,
        private Modules $modules,
        private ShareGateway $gateway,
        private FieldTechnicianDirectory $directory,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_if(! $user->can(self::ABILITY) || $user->customer_id !== null, 403);

        $sources = $this->sources($request);
        $own = $this->context->tenant();
        $chosen = $sources->firstWhere('id', $request->integer('company')) ?? $this->defaultSource($sources);
        $mine = $chosen !== null && $chosen->id === $own?->id;
        $manage = $mine && $user->can('field-technicians.manage');

        $filters = [
            'company' => $chosen?->id,
            'search' => $request->string('search')->trim()->value(),
            'province' => in_array($request->input('province'), ThaiProvinces::ALL, true) ? $request->input('province') : null,
            'skill' => in_array($request->input('skill'), FieldTechnician::SKILLS, true) ? $request->input('skill') : null,
            'status' => $manage && in_array($request->input('status'), FieldTechnician::STATUSES, true) ? $request->input('status') : null,
            'sort' => in_array($request->input('sort'), FieldTechnicianDirectory::SORTABLE, true) ? $request->input('sort') : 'created_at',
            'direction' => $request->input('direction') === 'asc' ? 'asc' : 'desc',
        ];

        $read = fn () => [
            'technicians' => $this->directory->page($filters, ! $manage),
            'pending' => $manage ? $this->directory->pendingCount() : 0,
        ];
        $data = match (true) {
            $chosen === null => ['technicians' => null, 'pending' => 0],
            $mine => $read(),
            default => $this->gateway->run($user, $chosen, self::ABILITY, fn () => $read()),
        };

        return Inertia::render('Service/FieldTechnicians/Index', [
            ...$data,
            'filters' => $filters,
            'sources' => $sources->map(fn (Tenant $tenant) => ['id' => $tenant->id, 'name' => $tenant->name, 'own' => $tenant->id === $own?->id])->values(),
            'provinces' => ThaiProvinces::ALL,
            'skills' => FieldTechnician::SKILLS,
            'can' => ['manage' => $manage],
            // The link to send to technicians: only the company that has the register gives it out.
            'signupUrl' => $manage ? PublicUrl::forTenant($own, '/join-technician') : null,
        ]);
    }

    public function update(Request $request, FieldTechnician $technician, SaveFieldTechnician $save): RedirectResponse
    {
        $this->authorizeManage($request);
        $save->handle($technician, $this->validated($request));

        return back()->with('success', __('service.field_technicians.updated'));
    }

    public function review(Request $request, FieldTechnician $technician, ReviewFieldTechnician $review): RedirectResponse
    {
        $this->authorizeManage($request);
        $approve = $request->validate(['approve' => ['required', 'boolean']])['approve'];
        $review->handle($technician, (bool) $approve, $request->user());

        return back()->with('success', __($approve ? 'service.field_technicians.approved' : 'service.field_technicians.rejected', ['name' => $technician->name]));
    }

    public function destroy(Request $request, FieldTechnician $technician): RedirectResponse
    {
        $this->authorizeManage($request);
        $technician->delete();

        return back()->with('success', __('service.field_technicians.deleted', ['name' => $technician->name]));
    }

    /**
     * Approved technicians to make a ticket's outside technician link for, from every register the
     * user may read: those in the ticket's province first.
     */
    public function pick(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('update', $ticket);
        $user = $request->user();
        abort_unless($user->can(self::ABILITY), 403);

        $province = ThaiProvinces::find($request->string('province')->value())
            ?? ThaiProvinces::find($ticket->branch?->province) ?? ThaiProvinces::find($ticket->branch?->name);
        $search = $request->string('search')->trim()->value();
        $own = $this->context->tenant();
        $sources = $this->sources($request);
        // No register to read: the ticket page leaves the picker out.
        abort_if($sources->isEmpty(), 404);

        $results = $sources->flatMap(fn (Tenant $company) => array_map(
            fn (array $row) => [...$row, 'source' => $company->id === $own?->id ? null : $company->name],
            $company->id === $own?->id
                ? $this->directory->suggest($province, $search)
                : $this->gateway->run($user, $company, self::ABILITY, fn () => $this->directory->suggest($province, $search)),
        ))->sortByDesc('in_province')->values()->take(15)->all();

        return response()->json(['province' => $province, 'technicians' => $results]);
    }

    /**
     * The registers the user may read: their own company's (when it keeps one), then the ones
     * other companies share with it.
     *
     * @return Collection<int, Tenant>
     */
    private function sources(Request $request): Collection
    {
        $own = $this->context->tenant();
        $shared = $this->gateway->targets($request->user(), self::ABILITY);

        return ($own && $this->modules->enabled('field_technicians') ? collect([$own]) : collect())
            ->concat($shared)->unique('id')->values();
    }

    /** The company's own register, unless it is still empty and another one is shared with it. */
    private function defaultSource(Collection $sources): ?Tenant
    {
        $first = $sources->first();
        if ($first?->id === $this->context->id() && $sources->count() > 1 && ! FieldTechnician::query()->exists()) {
            return $sources->get(1);
        }

        return $first;
    }

    private function authorizeManage(Request $request): void
    {
        abort_unless($this->modules->enabled('field_technicians') && $request->user()->can('field-technicians.manage'), 403);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate(self::fieldRules(), [], __('service.field_technicians.fields'));
    }

    /** @return array<string, mixed> shared by the sign-up form and staff's corrections */
    public static function fieldRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:50', 'regex:/^\+?[0-9][0-9\s\-()]{7,}[0-9]$/'],
            'line_id' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:255'],
            'note' => ['nullable', 'string', 'max:2000'],
            'provinces' => ['required', 'array', 'min:1', 'max:'.FieldTechnician::MAX_PROVINCES],
            'provinces.*' => ['string', Rule::in(ThaiProvinces::ALL)],
            'skills' => ['required', 'array', 'min:1'],
            'skills.*' => ['string', Rule::in(FieldTechnician::SKILLS)],
        ];
    }
}
