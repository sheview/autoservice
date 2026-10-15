<?php

namespace App\Modules\Contract\Actions;

use App\Modules\Asset\Support\AssetSheet;
use App\Modules\Contract\Models\Contract;
use App\Modules\Contract\Models\Customer;
use App\Modules\Contract\Support\ContractScope;
use App\Modules\Contract\Support\ContractSheet;
use App\Modules\Identity\Models\User;
use App\Modules\Platform\Support\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Imports an uploaded contract Excel file (ContractSheet layout) row by row, in the current tenant.
 *
 * A row whose contract number already exists updates that contract; any other row creates a new
 * contract. The customer is found by its code. A column missing from the file leaves the contract
 * as it is; for SLA, a priority whose two columns are both in the file and both empty has no SLA.
 * Each row is held to the user's reach like the form: contracts.update for the contract (and the
 * customer it moves to), contracts.create for the customer of a new one.
 * A bad row is skipped and reported with its Excel row number; the other rows are still imported.
 * Runs in the request (contract lists are small), like the part import.
 */
class ImportContracts
{
    public const MAX_ROWS = 2000;

    /** At most this many row errors are reported. */
    public const MAX_ERRORS = 200;

    public function __construct(private SaveContract $saveContract) {}

    /**
     * @return array{ok: bool, total: int, created: int, updated: int, failed: int,
     *     errors: list<array{row: int|null, messages: list<string>}>}
     */
    public function handle(UploadedFile $file, User $actor): array
    {
        try {
            $rows = Excel::toCollection(null, $file)->first() ?? collect();
        } catch (Throwable $e) {
            report($e);

            return $this->failed(__('contract.imports.unreadable'));
        }

        $columns = ContractSheet::mapHeadings($rows->first()?->all() ?? []);
        if (! in_array('contract_no', $columns, true)) {
            return $this->failed(__('contract.imports.missing_headings'));
        }

        $dataRows = $rows->slice(1)->filter(fn (Collection $row) => $row->contains(fn ($cell) => $cell !== null && $cell !== ''));
        if ($dataRows->count() > self::MAX_ROWS) {
            return $this->failed(__('contract.imports.too_many_rows', ['max' => self::MAX_ROWS]));
        }

        $result = ['ok' => true, 'total' => $dataRows->count(), 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];

        foreach ($dataRows as $index => $row) {
            $values = [];
            foreach ($columns as $cell => $column) {
                $value = $row[$cell] ?? null;
                // A text column may come back as a number (e.g. contract no 2569001); numbers and dates stay as read.
                $isText = ! in_array($column, ContractSheet::NUMERIC_COLUMNS, true)
                    && ! str_starts_with($column, 'sla_') && ! in_array($column, ['starts_on', 'ends_on'], true);
                $values[$column] = match (true) {
                    is_string($value) => trim($value) === '' ? null : trim($value),
                    $isText && is_scalar($value) => (string) $value,
                    default => $value,
                };
            }

            $outcome = $this->importRow($values, $actor);

            if (is_array($outcome)) {
                $result['failed']++;
                if (count($result['errors']) < self::MAX_ERRORS) {
                    // Excel rows start at 1; $index 0 is the heading row.
                    $result['errors'][] = ['row' => $index + 1, 'messages' => $outcome];
                }
            } else {
                $result[$outcome]++;
            }
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $values  column => cell (only the columns of the file)
     * @return 'created'|'updated'|list<string> the counter to bump, or the error messages
     */
    private function importRow(array $values, User $actor): string|array
    {
        $contractNo = (string) ($values['contract_no'] ?? '');
        $existing = $contractNo === '' ? null : Contract::withTrashed()->with('slas')->where('contract_no', $contractNo)->first();
        if ($existing?->trashed()) {
            return [__('contract.imports.number_deleted', ['no' => $contractNo])];
        }
        if ($existing ? ! $actor->can('update', $existing) : ! $actor->can('create', Contract::class)) {
            return [__('contract.imports.not_allowed', ['no' => $contractNo])];
        }

        $messages = [];

        // The customer: by code from the file, else the contract's own.
        $customerId = $existing?->customer_id;
        if (array_key_exists('customer_code', $values)) {
            $code = (string) ($values['customer_code'] ?? '');
            $customer = $code === '' ? null : Customer::query()->where('code', $code)->first();
            if ($code !== '' && ! $customer) {
                $messages[] = __('contract.imports.unknown_customer', ['code' => $code]);
            }
            $customerId = $customer?->id;
        }
        $permission = $existing ? 'contracts.update' : 'contracts.create';
        if ($customerId !== null && $customerId !== $existing?->customer_id && ! ContractScope::coversCustomerId($actor, $customerId, $permission)) {
            $messages[] = __('contract.imports.customer_not_allowed', ['code' => $values['customer_code'] ?? '']);
        }

        $data = ['customer_id' => $customerId, 'contract_no' => $contractNo === '' ? null : $contractNo];
        $data['title'] = array_key_exists('title', $values) ? $values['title'] : $existing?->title;
        $data['starts_on'] = array_key_exists('starts_on', $values) ? AssetSheet::date($values['starts_on']) : $existing?->starts_on?->toDateString();
        $data['ends_on'] = array_key_exists('ends_on', $values) ? AssetSheet::date($values['ends_on']) : $existing?->ends_on?->toDateString();
        $data['value'] = array_key_exists('value', $values)
            ? (is_string($values['value']) ? str_replace(',', '', $values['value']) : $values['value'])
            : Money::toBaht($existing?->value);
        $data['pm_interval_months'] = array_key_exists('pm_interval_months', $values) ? $values['pm_interval_months'] : $existing?->pm_interval_months;
        $data['notify_days_before'] = $values['notify_days_before'] ?? $existing?->notify_days_before ?? 60;
        $data['notes'] = array_key_exists('notes', $values) ? $values['notes'] : $existing?->notes;

        $statusText = $values['status'] ?? null;
        $status = $statusText === null ? null : ContractSheet::status($statusText);
        if ($statusText !== null && $status === null) {
            $messages[] = __('contract.imports.unknown_status', ['value' => $statusText]);
        }
        $data['status'] = $status ?? $existing?->status ?? Contract::STATUS_DRAFT;

        $windowText = $values['service_window'] ?? null;
        $window = $windowText === null ? null : ContractSheet::serviceWindow($windowText);
        if ($windowText !== null && $window === null) {
            $messages[] = __('contract.imports.unknown_service_window', ['value' => $windowText]);
        }
        $data['service_window'] = $window ?? $existing?->service_window ?? '8x5';

        // SLA hours per priority: from the file when both columns are there, else as the contract has it.
        $slaHours = [];
        foreach (Contract::PRIORITIES as $priority) {
            $responseColumn = ContractSheet::slaColumn($priority, 'response');
            $resolveColumn = ContractSheet::slaColumn($priority, 'resolve');
            if (array_key_exists($responseColumn, $values) && array_key_exists($resolveColumn, $values)) {
                $slaHours[$priority] = ['response' => $values[$responseColumn], 'resolve' => $values[$resolveColumn]];
            } else {
                $sla = $existing?->slas->firstWhere('priority', $priority);
                $slaHours[$priority] = ['response' => $sla ? $sla->response_minutes / 60 : null, 'resolve' => $sla ? $sla->resolve_minutes / 60 : null];
            }
        }

        $rules = [
            'customer_id' => ['required', 'integer'],
            'contract_no' => ['required', 'string', 'max:50'],
            'title' => ['required', 'string', 'max:255'],
            'status' => ['required', Rule::in(Contract::STATUSES)],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'value' => ['nullable', 'numeric', 'min:0', 'max:99999999999', 'decimal:0,2'],
            'service_window' => ['required', Rule::in(Contract::SERVICE_WINDOWS)],
            'pm_interval_months' => ['nullable', 'integer', Rule::in(Contract::PM_INTERVALS)],
            'notify_days_before' => ['required', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
        $attributes = [];
        foreach (ContractSheet::BASE_COLUMNS as $column) {
            $attributes[$column] = ContractSheet::heading($column);
        }
        $attributes['customer_id'] = ContractSheet::heading('customer_code');
        $input = $data;
        foreach (Contract::PRIORITIES as $priority) {
            $response = "sla_{$priority}_response";
            $resolve = "sla_{$priority}_resolve";
            $input[$response] = $slaHours[$priority]['response'];
            $input[$resolve] = $slaHours[$priority]['resolve'];
            $rules[$response] = ["required_with:{$resolve}", 'nullable', 'numeric', 'min:0.25', 'max:720'];
            $rules[$resolve] = ["required_with:{$response}", 'nullable', 'numeric', "gte:{$response}", 'max:720'];
            $attributes[$response] = ContractSheet::heading($response);
            $attributes[$resolve] = ContractSheet::heading($resolve);
        }

        // An unknown customer is already reported; do not report it twice as "required".
        $validator = Validator::make($input, $rules, [], $attributes);
        $errors = $customerId === null && $messages !== [] ? $validator->errors()->forget('customer_id') : $validator->errors();
        $messages = [...$messages, ...$errors->all()];
        if ($messages !== []) {
            return $messages;
        }

        $data['value'] = Money::toSatang($data['value']);
        $data['pm_interval_months'] = $data['pm_interval_months'] === null ? null : (int) $data['pm_interval_months'];
        $data['notify_days_before'] = (int) $data['notify_days_before'];
        $data['slas'] = collect($slaHours)
            ->filter(fn (array $hours) => $hours['response'] !== null)
            ->map(fn (array $hours) => [
                'response_minutes' => (int) round($hours['response'] * 60),
                'resolve_minutes' => (int) round($hours['resolve'] * 60),
            ])->all();

        $this->saveContract->handle($existing, $data);

        return $existing ? 'updated' : 'created';
    }

    /**
     * @return array{ok: bool, total: int, created: int, updated: int, failed: int,
     *     errors: list<array{row: int|null, messages: list<string>}>}
     */
    private function failed(string $message): array
    {
        return ['ok' => false, 'total' => 0, 'created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [['row' => null, 'messages' => [$message]]]];
    }
}
