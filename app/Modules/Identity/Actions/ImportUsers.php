<?php

namespace App\Modules\Identity\Actions;

use App\Modules\Contract\Actions\ListCustomers;
use App\Modules\Identity\Models\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Identity\Support\DataScope;
use App\Modules\Identity\Support\PermissionCatalog;
use App\Modules\Platform\CrossTenant\UniqueUserEmail;
use App\Modules\Platform\Support\Modules;
use App\Modules\Tenancy\Models\Branch;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Maatwebsite\Excel\Facades\Excel;
use Throwable;

/**
 * Creates the company's first accounts (staff and customer accounts) from an Excel file, row by row.
 *
 * Runs right away and never stores the file, because it holds passwords. A row whose e-mail
 * already has an account (in any company) is skipped; a bad row is reported with its Excel row
 * number. Either way the other rows are still imported.
 */
class ImportUsers
{
    public const MAX_ROWS = 500;

    /** Columns of the sheet, in template order. */
    public const COLUMNS = ['name', 'email', 'password', 'role', 'branch', 'customer', 'employee_code', 'position', 'phone'];

    /** @var array<string, string> lower(name) and lower(label) => role name */
    private array $roles = [];

    /** @var array<string, int> lower(code) and lower(name) => branch id */
    private array $branches = [];

    /** @var array<string, int> lower(code) and lower(name) => customer id */
    private array $customers = [];

    public function __construct(
        private SaveUser $saveUser,
        private ListCustomers $listCustomers,
        private Modules $modules,
    ) {}

    /**
     * @return array{total: int, created: int, skipped: int, failed: int, errors: list<array{row: int|null, messages: list<string>}>}
     */
    public function handle(User $importer, UploadedFile $file): array
    {
        try {
            $rows = Excel::toCollection(null, $file)->first() ?? collect();
        } catch (Throwable $e) {
            report($e);

            return $this->failed(__('identity.imports.unreadable'));
        }

        $columns = self::mapHeadings($rows->first()?->all() ?? []);
        if (array_diff(['name', 'email', 'password', 'role'], $columns) !== []) {
            return $this->failed(__('identity.imports.missing_headings'));
        }

        $dataRows = $rows->slice(1)->filter(fn (Collection $row) => $row->contains(fn ($cell) => $cell !== null && trim((string) $cell) !== ''));
        if ($dataRows->count() > self::MAX_ROWS) {
            return $this->failed(__('identity.imports.too_many_rows', ['max' => self::MAX_ROWS]));
        }

        $this->loadLookups();
        // users.manage scope branch: only into the importer's own branch (or none), as in the user form.
        $ownBranchOnly = DataScope::of($importer, 'users.manage') === PermissionCatalog::SCOPE_BRANCH;
        $result = ['total' => $dataRows->count(), 'created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];

        foreach ($dataRows as $index => $row) {
            $values = [];
            foreach ($columns as $cell => $column) {
                // Numbers (a phone, an employee code, a password of digits) are read as text.
                $value = is_scalar($row[$cell] ?? null) ? trim((string) $row[$cell]) : '';
                $values[$column] = $value === '' ? null : $value;
            }

            [$outcome, $messages] = $this->importRow($values, $ownBranchOnly ? $importer->branch_id : false);
            $result[$outcome]++;
            if ($messages !== []) {
                $result['errors'][] = ['row' => $index + 1, 'messages' => $messages]; // $index 0 is the heading row
            }
        }

        return $result;
    }

    /**
     * Column of each heading cell: the Thai label of the template or the column key.
     *
     * @param  array<int, mixed>  $headingRow
     * @return array<int, string> cell index => column
     */
    public static function mapHeadings(array $headingRow): array
    {
        $byLabel = [];
        foreach (self::COLUMNS as $column) {
            $byLabel[mb_strtolower(__("identity.imports.columns.{$column}"))] = $column;
            $byLabel[$column] = $column;
        }

        $map = [];
        foreach ($headingRow as $index => $heading) {
            if (isset($byLabel[$key = mb_strtolower(trim((string) $heading))])) {
                $map[$index] = $byLabel[$key];
            }
        }

        return $map;
    }

    /**
     * @param  array<string, string|null>  $values  column => cell
     * @param  int|null|false  $onlyBranchId  the one branch allowed (null = none), or false = any
     * @return array{0: 'created'|'skipped'|'failed', 1: list<string>}
     */
    private function importRow(array $values, int|null|false $onlyBranchId): array
    {
        $email = mb_strtolower((string) ($values['email'] ?? ''));
        if ($email !== '' && Validator::make(['email' => $email], ['email' => [new UniqueUserEmail]])->fails()) {
            return ['skipped', [__('identity.imports.email_exists', ['email' => $email])]];
        }

        $messages = [];
        $role = $this->roles[mb_strtolower((string) $values['role'])] ?? null;
        if ($values['role'] !== null && $role === null) {
            $messages[] = __('identity.imports.unknown_role', ['value' => $values['role']]);
        }

        $branchId = null;
        if (($values['branch'] ?? null) !== null) {
            $branchId = $this->branches[mb_strtolower($values['branch'])] ?? null;
            if ($branchId === null) {
                $messages[] = __('identity.imports.unknown_branch', ['value' => $values['branch']]);
            } elseif ($onlyBranchId !== false && $branchId !== $onlyBranchId) {
                $messages[] = __('identity.imports.branch_not_allowed', ['value' => $values['branch']]);
            }
        }

        $customerId = null;
        if (($values['customer'] ?? null) !== null) {
            $customerId = $this->customers[mb_strtolower($values['customer'])] ?? null;
            if ($customerId === null) {
                $messages[] = __('identity.imports.unknown_customer', ['value' => $values['customer']]);
            }
        }

        // Customer accounts and the customer role go together.
        if ($role !== null && ($values['customer'] ?? null) === null && $role === PermissionCatalog::CUSTOMER_ROLE) {
            $messages[] = __('identity.users.customer_required');
        } elseif ($role !== null && $customerId !== null && $role !== PermissionCatalog::CUSTOMER_ROLE) {
            $messages[] = __('identity.users.customer_role_only');
        }

        $data = [
            'name' => $values['name'] ?? null,
            'email' => $email === '' ? null : $email,
            'password' => $values['password'] ?? null,
            'role' => $role ?? $values['role'],
            'employee_code' => $values['employee_code'] ?? null,
            'position' => $values['position'] ?? null,
            'phone' => $values['phone'] ?? null,
        ];

        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string', Password::defaults()],
            'role' => ['required'],
            'employee_code' => ['nullable', 'string', 'max:50'],
            'position' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
        ], attributes: __('identity.imports.columns'));

        $messages = [...$validator->errors()->all(), ...$messages];
        if ($messages !== []) {
            return ['failed', array_values(array_unique($messages))];
        }

        $this->saveUser->handle(null, [
            ...$data,
            'branch_id' => $branchId,
            'customer_id' => $customerId,
            'service_lines' => [],
            'is_active' => true,
        ]);

        return ['created', []];
    }

    private function loadLookups(): void
    {
        foreach (Role::get(['name', 'label']) as $role) {
            $this->roles[mb_strtolower($role->name)] = $role->name;
            if (filled($role->label)) {
                $this->roles[mb_strtolower($role->label)] = $role->name;
            }
        }

        foreach (Branch::get(['id', 'code', 'name']) as $branch) {
            $this->branches[mb_strtolower($branch->name)] = $branch->id;
            $this->branches[mb_strtolower($branch->code)] = $branch->id; // a code wins over a name
        }

        if ($this->modules->enabled('contract')) {
            foreach ($this->listCustomers->handle() as $customer) {
                $this->customers[mb_strtolower($customer['name'])] = $customer['id'];
                $this->customers[mb_strtolower($customer['code'])] = $customer['id'];
            }
        }
    }

    /**
     * @return array{total: int, created: int, skipped: int, failed: int, errors: list<array{row: int|null, messages: list<string>}>}
     */
    private function failed(string $message): array
    {
        return ['total' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => [['row' => null, 'messages' => [$message]]]];
    }
}
