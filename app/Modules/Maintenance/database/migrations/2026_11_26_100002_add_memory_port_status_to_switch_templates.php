<?php

use App\Modules\Maintenance\Actions\CreateTemplateVersion;
use App\Modules\Maintenance\Actions\PublishTemplate;
use App\Modules\Maintenance\Models\PmChecklist;
use App\Modules\Maintenance\Support\ChecklistItems;
use App\Modules\Tenancy\Models\Tenant;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Database\Migrations\Migration;

/**
 * The network switch forms (NW-SWITCH, and the network table NW-GROUP) also check Memory and the
 * Port Status, after CPU (StandardTemplates). Each company's published form gets a new version with
 * them, as if changed by hand: the version before is archived, work already started keeps its copy.
 * Left alone: a form that has them already, has no CPU question to put them after, or is being
 * changed (a draft waits).
 */
return new class extends Migration
{
    private const CODES = ['NW-SWITCH', 'NW-GROUP'];

    public function up(): void
    {
        foreach (Tenant::query()->get() as $tenant) {
            app(TenantContext::class)->run($tenant, function () {
                foreach (self::CODES as $code) {
                    $this->addChecks($code);
                }
            });
        }
    }

    public function down(): void
    {
        // The versions stay: a form's history is not taken back.
    }

    private function addChecks(string $code): void
    {
        $versions = PmChecklist::query()->where('code', $code)->get();
        $published = $versions->firstWhere('status', PmChecklist::STATUS_PUBLISHED);
        if ($published === null || $versions->contains('status', PmChecklist::STATUS_DRAFT)) {
            return;
        }

        $keys = array_column($published->items, 'key');
        $cpu = array_search('cpu', $keys, true);
        if ($cpu === false || in_array('memory', $keys, true) || in_array('port_status', $keys, true)) {
            return;
        }

        $section = $published->items[$cpu]['section'] ?? null;
        $check = fn (string $key, string $label) => array_filter(['key' => $key, 'label' => $label, 'type' => 'pass_fail', 'section' => $section]);
        $items = $published->items;
        array_splice($items, $cpu + 1, 0, [$check('memory', 'Memory'), $check('port_status', 'Port Status')]);

        $draft = app(CreateTemplateVersion::class)->handle($published);
        $draft->update(['items' => ChecklistItems::clean($items)]);
        app(PublishTemplate::class)->handle($draft);
    }
};
