<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The history of a work order, kept for good (the activity log is pruned after 90 days):
        // every step of the workflow, equipment added or removed, report versions, share links
        // made, revoked and opened.
        Schema::create('pm_visit_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_visit_id')->constrained()->restrictOnDelete();
            $table->string('type', 30);
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();
            $table->text('note')->nullable();
            // Details of the event (e.g. the asset added, the version, the share link id).
            $table->json('data')->nullable();
            // Kept as written: the user may be deleted later. Null = the system or a share link.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'pm_visit_id']);
        });
        Rls::enable('pm_visit_events');

        // What an approved report said, frozen: the PDFs of a version are always made from its
        // snapshot, so a version reads the same however the work order changes afterwards.
        // Append only; a correction is a new version.
        Schema::create('pm_report_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_visit_id')->constrained()->restrictOnDelete();
            $table->unsignedSmallInteger('version');
            $table->json('snapshot');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('approved_by_name')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['pm_visit_id', 'version']);
        });
        Rls::enable('pm_report_versions');

        // Links that let a customer see and download a published report without an account.
        // Only the SHA-256 of the token is kept: the link itself is shown once, when made.
        Schema::create('pm_report_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('pm_visit_id')->constrained()->restrictOnDelete();
            // The version shown; null = the newest published one.
            $table->foreignId('pm_report_version_id')->nullable()->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('recipient_name')->nullable();
            $table->string('recipient_email')->nullable();
            $table->boolean('allow_download')->default(true);
            $table->dateTime('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->string('revoked_by_name')->nullable();
            $table->unsignedInteger('view_count')->default(0);
            $table->timestamp('last_viewed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_name')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'pm_visit_id']);
        });
        Rls::enable('pm_report_shares');
    }

    public function down(): void
    {
        Schema::dropIfExists('pm_report_shares');
        Schema::dropIfExists('pm_report_versions');
        Schema::dropIfExists('pm_visit_events');
    }
};
