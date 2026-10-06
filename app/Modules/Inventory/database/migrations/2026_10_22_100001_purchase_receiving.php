<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchase requests from asking to handing out:
 *
 *   pending โ’ approved โ’ ordered โ’ partially_received โ’ received โ’ registered โ’ issued
 *   (rejected, cancelled)
 *
 * - purchase_requests: the issue/loan request it was asked from, and how much has been received,
 *   registered into the system (assets / parts) and handed out so far.
 * - purchase_receipts: each delivery (some or all of it) with what the buyer read off the goods
 *   (brand, model, serials, price), and what it was registered as.
 * - purchase_request_events: every change of status, who made it and when. The requests asked
 *   before this get their history back from the columns they kept.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_requests', function (Blueprint $table) {
            // The issue/loan request it was asked from (nothing to hand out was found).
            $table->foreignId('checkout_request_id')->nullable()->after('contract_id')->constrained()->nullOnDelete();
            $table->unsignedInteger('qty_received')->default(0)->after('quantity');
            $table->unsignedInteger('qty_registered')->default(0)->after('qty_received');
            $table->unsignedInteger('qty_issued')->default(0)->after('qty_registered');
        });

        Schema::create('purchase_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->string('brand', 100)->nullable();
            $table->string('model', 100)->nullable();
            $table->bigInteger('unit_price')->nullable(); // paid, satang
            // Serial numbers read off the goods, as many as were written down.
            $table->json('serials')->default('[]');
            $table->text('note')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('received_by_name')->nullable();
            $table->timestamp('received_at');
            // What it became: an asset (Asset module) or stock of a part.
            $table->string('registered_as', 10)->nullable();
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('part_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stock_movement_id')->nullable()->constrained()->nullOnDelete();
            $table->string('registered_by_name')->nullable();
            $table->timestamp('registered_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'purchase_request_id']);
        });
        Rls::enable('purchase_receipts');

        Schema::create('purchase_request_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('purchase_request_id')->constrained()->restrictOnDelete();
            $table->string('action', 20);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('actor_name')->nullable();
            $table->text('note')->nullable();
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'purchase_request_id']);
        });
        Rls::enable('purchase_request_events');

        $this->backfill();
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_request_events');
        Schema::dropIfExists('purchase_receipts');
        Schema::table('purchase_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('checkout_request_id');
            $table->dropColumn(['qty_received', 'qty_registered', 'qty_issued']);
        });
    }

    /** What the requests asked before this already went through, rebuilt from their columns. */
    private function backfill(): void
    {
        // Runs across every tenant: let the owner past row level security meanwhile.
        Rls::noForce('purchase_requests');
        Rls::noForce('purchase_request_events');
        Rls::noForce('purchase_receipts');

        DB::table('purchase_requests')->where('status', 'received')->update(['qty_received' => DB::raw('quantity')]);

        foreach (DB::table('purchase_requests')->orderBy('id')->get() as $pr) {
            $event = fn (string $action, ?string $from, string $to, ?string $name, $at, ?string $note = null, ?int $actorId = null) => [
                'tenant_id' => $pr->tenant_id, 'purchase_request_id' => $pr->id, 'action' => $action, 'from_status' => $from,
                'to_status' => $to, 'actor_id' => $actorId, 'actor_name' => $name, 'note' => $note, 'created_at' => $at,
            ];
            $rows = [$event('create', null, 'pending', $pr->requested_by_name, $pr->created_at, null, $pr->requested_by)];
            if ($pr->decided_at !== null && in_array($pr->status, ['approved', 'ordered', 'received', 'rejected'], true)) {
                $rejected = $pr->status === 'rejected';
                $rows[] = $event($rejected ? 'reject' : 'approve', 'pending', $rejected ? 'rejected' : 'approved',
                    $pr->decided_by_name, $pr->decided_at, $pr->decision_note, $pr->decided_by);
            }
            if ($pr->ordered_at !== null) {
                $rows[] = $event('order', 'approved', 'ordered', $pr->ordered_by_name, $pr->ordered_at, $pr->order_note);
            }
            if ($pr->received_at !== null) {
                $rows[] = $event('receive', 'ordered', 'received', $pr->received_by_name, $pr->received_at, $pr->receive_note);
                // Received in one go, still to be registered.
                DB::table('purchase_receipts')->insert([
                    'tenant_id' => $pr->tenant_id, 'purchase_request_id' => $pr->id, 'quantity' => $pr->quantity,
                    'unit_price' => $pr->unit_price, 'serials' => '[]', 'note' => $pr->receive_note,
                    'received_by_name' => $pr->received_by_name, 'received_at' => $pr->received_at,
                    'created_at' => $pr->received_at, 'updated_at' => $pr->received_at,
                ]);
            }
            if ($pr->status === 'cancelled') {
                $rows[] = $event('cancel', $pr->decided_at ? 'approved' : 'pending', 'cancelled', null, $pr->updated_at, $pr->decision_note);
            }
            DB::table('purchase_request_events')->insert($rows);
        }

        Rls::force('purchase_requests');
        Rls::force('purchase_request_events');
        Rls::force('purchase_receipts');
    }
};
