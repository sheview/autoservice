<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * - pm_visits.tracking_token: the token in the work order's tracking QR (pm-track/{token}), made when
 *   first printed. Kept as it is (like tickets.tracking_token): every print must show the same QR, and
 *   the page it opens shows only the status, never results.
 * - pm_sign_links: a link (QR) for the customer to sign the work order on their own phone. Only the
 *   SHA-256 of its token is kept; it expires, is used once, and can be revoked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pm_visits', function (Blueprint $table) {
            $table->char('tracking_token', 64)->nullable()->unique()->after('ulid');
        });

        Schema::create('pm_sign_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_visit_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('revoked_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['tenant_id', 'pm_visit_id']);
        });
        Rls::enable('pm_sign_links');
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_sign_links');
        Schema::table('pm_visits', function (Blueprint $table) {
            $table->dropUnique(['tracking_token']);
            $table->dropColumn('tracking_token');
        });
    }
};
