<?php

use App\Modules\Tenancy\Support\LiveUnique;
use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Measuring instruments used on PM (multimeter, clamp meter, ...), with their calibration due date.
        Schema::create('pm_instruments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->string('name');
            $table->string('serial_no', 100);
            $table->date('calibration_due_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        LiveUnique::add('pm_instruments', ['tenant_id', 'serial_no'], 'pm_instruments_tenant_serial_unique');
        Rls::enable('pm_instruments');

        // A signature on a work order. The signer's name and contact are typed in when signing
        // (not part of the template); data_hash = SHA-256 of the results as they were signed.
        Schema::create('pm_signatures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_visit_id')->constrained()->cascadeOnDelete();
            $table->string('role', 20); // engineer | customer | approver
            $table->string('name');
            $table->string('position')->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email')->nullable();
            $table->string('image_path'); // on the private disk
            $table->dateTime('signed_at');
            $table->string('ip', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->char('data_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['pm_visit_id', 'role']);
        });
        Rls::enable('pm_signatures');
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_signatures');
        Schema::dropIfExists('pm_instruments');
    }
};
