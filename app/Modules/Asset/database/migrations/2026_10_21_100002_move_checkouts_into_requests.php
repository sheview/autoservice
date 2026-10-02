<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Moves the single-item issue/loan forms (asset_checkouts, part_checkouts) into requests with
 * one line each, keeping their numbers, people, dates and outcome:
 *
 *   pending   → request pending, line pending
 *   approved  → request fulfilled, line handed out (a loan still to come back)
 *   returned  → request closed, line handed out and returned
 *   rejected  → request rejected, line rejected (with the reason)
 *   cancelled → request cancelled
 *
 * The old tables are left as they are (no longer used).
 */
return new class extends Migration
{
    private const TABLES = ['checkout_requests', 'checkout_items', 'checkout_fulfillments', 'asset_checkouts', 'part_checkouts', 'assets', 'parts'];

    public function up(): void
    {
        // Runs across every tenant: let the owner past row level security meanwhile.
        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }

        foreach (DB::table('asset_checkouts')->orderBy('id')->get() as $old) {
            $asset = DB::table('assets')->where('id', $old->asset_id)->first(['asset_code', 'name', 'unit', 'branch_id']);
            $this->move($old, [
                'item_type' => 'asset', 'asset_id' => $old->asset_id, 'part_id' => null,
                'item_code' => $asset?->asset_code, 'item_name' => $asset?->name ?? '-', 'unit' => $asset?->unit,
            ], $asset?->branch_id);
        }

        foreach (DB::table('part_checkouts')->orderBy('id')->get() as $old) {
            $part = DB::table('parts')->where('id', $old->part_id)->first(['code', 'name', 'unit']);
            $this->move($old, [
                'item_type' => 'part', 'asset_id' => null, 'part_id' => $old->part_id,
                'item_code' => $part?->code, 'item_name' => $part?->name ?? '-', 'unit' => $part?->unit,
            ], null);
        }

        foreach (self::TABLES as $table) {
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        }
    }

    /**
     * @param  array<string, mixed>  $item  what the line is
     */
    private function move(object $old, array $item, ?int $branchId): void
    {
        if (DB::table('checkout_requests')->where('tenant_id', $old->tenant_id)->where('request_no', $old->checkout_no)->exists()) {
            return; // already moved
        }

        $out = in_array($old->status, ['approved', 'returned'], true);
        $requestId = DB::table('checkout_requests')->insertGetId([
            'tenant_id' => $old->tenant_id,
            'ulid' => strtolower((string) Str::ulid()),
            'request_no' => $old->checkout_no,
            'status' => match ($old->status) {
                'approved' => 'fulfilled',
                'returned' => 'closed',
                default => $old->status, // pending, rejected, cancelled
            },
            'requester_id' => $old->requested_by,
            'requester_name' => $old->requested_by_name,
            'borrower_user_id' => $old->borrower_user_id,
            'borrower_name' => $old->borrower_name,
            'borrower_department' => $old->borrower_department,
            'borrower_phone' => $old->borrower_phone,
            'branch_id' => $branchId,
            'contract_id' => $old->contract_id,
            'purpose' => $old->purpose,
            'approved_by' => $old->decided_by,
            'approved_by_name' => $old->decided_by_name,
            'approved_at' => $old->decided_at,
            'reject_reason' => $old->status === 'rejected' ? $old->decision_note : null,
            'submitted_at' => $old->created_at,
            'closed_at' => $old->status === 'returned' ? $old->returned_at : null,
            'created_at' => $old->created_at,
            'updated_at' => $old->updated_at,
            'deleted_at' => $old->deleted_at,
        ]);

        $itemId = DB::table('checkout_items')->insertGetId([
            ...$item,
            'tenant_id' => $old->tenant_id,
            'request_id' => $requestId,
            'checkout_type' => $old->type,
            'qty_requested' => $old->quantity,
            'qty_approved' => $out ? $old->quantity : ($old->status === 'rejected' ? 0 : null),
            'qty_fulfilled' => $out ? $old->quantity : 0,
            'qty_returned' => $old->status === 'returned' ? $old->quantity : 0,
            'status' => match ($old->status) {
                'approved', 'returned' => 'fulfilled',
                default => $old->status,
            },
            'due_return_date' => $old->due_on,
            'returned_at' => $old->returned_at,
            'returned_by_name' => $old->returned_by_name,
            'return_condition' => $old->return_note,
            'reject_reason' => $old->status === 'rejected' ? $old->decision_note : null,
            'created_at' => $old->created_at,
            'updated_at' => $old->updated_at,
        ]);

        if ($out) {
            DB::table('checkout_fulfillments')->insert([
                'tenant_id' => $old->tenant_id,
                'item_id' => $itemId,
                'qty' => $old->quantity,
                'fulfilled_by' => $old->decided_by,
                'fulfilled_by_name' => $old->decided_by_name,
                'fulfilled_at' => $old->decided_at ?? $old->created_at,
                'created_at' => $old->decided_at ?? $old->created_at,
                'updated_at' => $old->decided_at ?? $old->created_at,
            ]);
        }
    }

    public function down(): void
    {
        // The old tables were never changed: nothing to put back.
    }
};
