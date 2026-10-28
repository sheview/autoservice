<?php

namespace App\Modules\Labeling\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Asset\Actions\PublicAssetLabel;
use App\Modules\Contract\Actions\ContractsForAsset;
use App\Modules\Platform\Support\Modules;
use App\Modules\Platform\Support\PublicUrl;
use App\Modules\Platform\Support\Turnstile;
use App\Modules\Service\Actions\OpenReportedTicket;
use App\Modules\Service\Actions\OpenTicketOfAsset;
use App\Modules\Service\Actions\RepairPresetList;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\CompanyCodes;
use App\Modules\Tenancy\Support\PublicTenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The public side of an asset's QR page (no sign-in): what the device is, its warranty, its MA contract
 * (number and period only) and main photo, a form to
 * report a problem with it (or, if a job is already running for it, a link to follow that job),
 * and a way for staff to sign in and come back. An unknown company, an unknown code and a wrong
 * key all give the same page, after the same lookup. Nothing here uses the staff's session or rights.
 */
class QrPublicController extends Controller
{
    public const MAX_PHOTOS = 3;

    public const PHOTO_KB = 5120;

    public function __construct(private TenantContext $context, private Modules $modules, private PublicAssetLabel $label) {}

    public function page(Request $request, ?Tenant $tenant, string $code): Response
    {
        $tenant = $this->usable($tenant);
        $key = $this->key($request);
        [$asset, $running, $presets, $hasPhoto, $contract] = $this->context->run($tenant, function () use ($tenant, $code, $key) {
            $asset = $this->label->handle($code, $key);
            $serviceOn = $asset && $this->modules->enabled('service', $tenant);

            return [
                $asset,
                $serviceOn ? app(OpenTicketOfAsset::class)->handle($asset['id']) : null,
                $serviceOn ? app(RepairPresetList::class)->handle()['symptom'] : [],
                $asset !== null && $this->label->photo($code, $key) !== null,
                $asset && $this->modules->enabled('contract', $tenant) ? $this->contract($asset['id']) : null,
            ];
        });

        return Inertia::render('Labeling/QrPublic', [
            'company' => $asset ? $tenant->name : null,
            // What the device is and its warranty (PublicAssetLabel), never where it is or who uses it.
            'asset' => $asset ? [
                ...collect($asset)->except('id')->all(),
                'photo' => $hasPhoto ? url($request->path().'/photo').'?k='.urlencode($key) : null,
            ] : null,
            // The MA contract of the device: its number and period, never its title or customer.
            'contract' => $contract,
            // A job already running for the device: follow it instead of reporting it again.
            'running' => $running ? PublicUrl::forTenant($tenant, '/track/'.$running->tracking_token) : null,
            'canReport' => $asset !== null && $this->modules->enabled('service', $tenant),
            'symptoms' => $presets,
            'action' => $asset ? url($request->path().'/report').'?k='.urlencode($key) : null,
            'limits' => ['photos' => self::MAX_PHOTOS, 'photo_kb' => self::PHOTO_KB],
            'captcha' => Turnstile::siteKey(),
            'signIn' => url($request->path().'/staff').($key !== '' ? '?k='.urlencode($key) : ''),
        ]);
    }

    public function reportOnHost(Request $request, PublicTenant $publicTenant, string $code): RedirectResponse
    {
        // On a shared host only the company's own host (or /t/{code}) names the company.
        return $this->report($request, $publicTenant->known() ? $publicTenant->resolve() : null, $code);
    }

    public function reportOnPath(Request $request, string $company, string $code): RedirectResponse
    {
        return $this->report($request, CompanyCodes::tenant($company), $code);
    }

    private function report(Request $request, ?Tenant $tenant, string $code): RedirectResponse
    {
        $tenant = $this->usable($tenant);
        $key = $this->key($request);
        $back = fn () => redirect()->to(url(str_replace('/report', '', $request->path())).'?k='.urlencode($key));

        $data = Validator::make($request->all(), [
            'symptoms' => ['required', 'array', 'min:1', 'max:10'],
            'symptoms.*' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:1000'],
            'name' => ['required', 'string', 'max:100'],
            'phone' => ['nullable', 'required_without:email', 'string', 'max:30', 'regex:/^[0-9+\-() ]{6,30}$/'],
            'email' => ['nullable', 'required_without:phone', 'email:rfc', 'max:150'],
            'photos' => ['array', 'max:'.self::MAX_PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.self::PHOTO_KB],
            // A field people never see: filled in, it is a robot.
            'website' => ['nullable', 'string'],
        ], [
            'phone.required_without' => __('service.reported.contact_required'),
            'email.required_without' => __('service.reported.contact_required'),
            'symptoms.required' => __('service.reported.symptom_required'),
        ], __('service.reported.fields'))->validate();

        if (! Turnstile::passes($request->string('cf-turnstile-response')->value(), $request->ip())) {
            return $back()->withErrors(['captcha' => __('platform.security.captcha_failed')])->withInput($request->except('photos'));
        }

        $asset = $this->context->run($tenant, fn () => $this->label->handle($code, $key));
        if ($asset === null || $tenant === null || ! $this->modules->enabled('service', $tenant)) {
            return $back();
        }
        // A robot gets the same "thank you" and nothing is made.
        if (filled($data['website'] ?? null)) {
            return $back()->with('reported', true);
        }

        $result = $this->context->run($tenant, fn () => app(OpenReportedTicket::class)->handle($asset['id'], [
            'symptoms' => array_values(array_unique(array_map('trim', $data['symptoms']))),
            'note' => $data['note'] ?? null,
            'name' => trim($data['name']),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
        ], $request->file('photos', [])));

        return redirect()->to(PublicUrl::forTenant($tenant, '/track/'.$result['ticket']->tracking_token))
            ->with($result['created'] ? 'reported' : 'already', $result['ticket']->ticket_no);
    }

    /** The device's main photo on its public page, under the same key check as the page. */
    public function photoOnHost(Request $request, PublicTenant $publicTenant, string $code): StreamedResponse
    {
        return $this->photo($request, $publicTenant->known() ? $publicTenant->resolve() : null, $code);
    }

    public function photoOnPath(Request $request, string $company, string $code): StreamedResponse
    {
        return $this->photo($request, CompanyCodes::tenant($company), $code);
    }

    private function photo(Request $request, ?Tenant $tenant, string $code): StreamedResponse
    {
        $tenant = $this->usable($tenant);
        abort_if($tenant === null, 404);
        $media = $this->context->run($tenant, fn () => $this->label->photo($code, $this->key($request)));
        abort_if($media === null, 404);

        return response()->streamDownload(
            fn () => fpassthru($media->stream()),
            $media->file_name,
            ['Content-Type' => $media->mime_type, 'Cache-Control' => 'private, max-age=3600'],
            'inline',
        );
    }

    /**
     * The contract covering the device today, else the latest one it was or will be in; null when none.
     *
     * @return array{contract_no: string, starts_on: string, ends_on: string, phase: string}|null
     */
    private function contract(int $assetId): ?array
    {
        $contracts = app(ContractsForAsset::class)->handle($assetId);
        $contract = collect($contracts)->firstWhere('covering', true) ?? $contracts[0] ?? null;

        return $contract ? collect($contract)->only(['contract_no', 'starts_on', 'ends_on', 'phase'])->all() : null;
    }

    private function usable(?Tenant $tenant): ?Tenant
    {
        return $tenant !== null && ! $tenant->is_platform && $tenant->isActive() && $this->modules->enabled('asset', $tenant) ? $tenant : null;
    }

    private function key(Request $request): string
    {
        return $request->string('k')->trim()->limit(32, '')->value();
    }
}
