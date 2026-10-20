<?php

namespace App\Modules\Asset\Actions;

use App\Modules\Asset\Jobs\ImportAssetsJob;
use App\Modules\Asset\Models\AssetImport;
use App\Modules\Contract\Actions\EditableContracts;
use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Support\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Stores an uploaded asset Excel file and queues ImportAssetsJob for it. Another module asking for
 * an import (a PM plan) passes its context: see ImportAssets.
 */
class StartAssetImport
{
    public function __construct(
        private TenantContext $context,
        private Modules $modules,
        private ListCustomers $listCustomers,
        private EditableContracts $editableContracts,
    ) {}

    /**
     * @param  array{contract_id?: int, contract_no?: string, customer_id?: int, columns?: array<string, string>}&array<string, mixed>  $context
     */
    public function handle(User $user, UploadedFile $file, array $context = []): AssetImport
    {
        $context = $this->chosenContract($user, $context);

        $path = $file->storeAs(
            'asset-imports/'.$this->context->id(),
            Str::ulid().'.'.strtolower($file->getClientOriginalExtension()),
            'local',
        );

        $import = AssetImport::create([
            'user_id' => $user->id,
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'context' => $context ?: null,
        ]);

        ImportAssetsJob::dispatch($import->id, DataScope::of($user, 'assets.import') === PermissionCatalog::SCOPE_ALL, $user->branch_id, $this->contracts($user));

        return $import;
    }

    /**
     * A contract picked by id (the import page) must be one of the chosen customer's that the uploader
     * may change; its number is kept for the row messages. A customer alone is the rows' default customer.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function chosenContract(User $user, array $context): array
    {
        if (! isset($context['contract_id']) || isset($context['contract_no'])) {
            return $context;
        }

        $customerId = (int) ($context['customer_id'] ?? 0);
        $contracts = $this->modules->enabled('contract') ? $this->editableContracts->handle($user, [$customerId], runningOnly: false) : [];
        $contract = collect($contracts[$customerId] ?? [])->firstWhere('id', (int) $context['contract_id']);
        if ($contract === null) {
            throw ValidationException::withMessages(['contract_id' => __('asset.imports.contract_not_allowed')]);
        }

        return [...$context, 'contract_no' => $contract['contract_no']];
    }

    /**
     * The contracts the file may put assets into, decided now like the branch scope (the uploader
     * may be an impersonating superadmin, not a user of this tenant): the contracts the uploader
     * may change, ended ones too as on the contract page (an exported file lists them), by
     * lower(contract number). Null = the contract column is ignored.
     *
     * @return array<string, array{id: int, customer_id: int}>|null
     */
    private function contracts(User $user): ?array
    {
        if (! $this->modules->enabled('contract') || ! $user->can('contracts.update')) {
            return null;
        }

        $contracts = [];
        $customerIds = array_column($this->listCustomers->handle(), 'id');
        foreach ($this->editableContracts->handle($user, $customerIds, runningOnly: false) as $customerId => $list) {
            foreach ($list as $contract) {
                $contracts[mb_strtolower($contract['contract_no'])] = ['id' => $contract['id'], 'customer_id' => (int) $customerId];
            }
        }

        return $contracts;
    }
}
