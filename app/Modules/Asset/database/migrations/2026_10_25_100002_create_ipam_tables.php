<?php

use App\Modules\Tenancy\Support\Rls;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple IP address management: customer -> site -> network -> subnet -> IP address -> asset.
 *
 * An ip_addresses row exists only for an address someone did something with (reserved, in use,
 * excluded, linked to a ticket, or released with its history kept); every other address of a
 * subnet is free without a row. Addresses are also kept as integers (ip_int) for ranges and order.
 * last_seen_at / mac_seen are left for a later scan (ping / ARP), which this version does not do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->restrictOnDelete(); // null = the company's own
            $table->foreignId('site_id')->nullable()->constrained('customer_sites')->restrictOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('vlan_id')->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'customer_id']);
        });
        Rls::enable('networks');

        Schema::create('subnets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('network_id')->constrained()->restrictOnDelete();
            $table->string('cidr', 18); // 192.168.1.0/24
            $table->bigInteger('first_int'); // the network address
            $table->unsignedTinyInteger('prefix');
            $table->string('gateway', 15)->nullable();
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'network_id']);
        });
        Rls::enable('subnets');

        Schema::create('ip_addresses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->unique();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('subnet_id')->constrained()->restrictOnDelete();
            $table->string('ip', 15);
            $table->bigInteger('ip_int');
            $table->string('status', 20); // available | reserved | in_use | excluded
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('hostname')->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->foreignId('responsible_id')->nullable()->constrained('users')->nullOnDelete();
            $table->date('in_use_since')->nullable();
            $table->text('notes')->nullable();
            // For a later network scan.
            $table->timestamp('last_seen_at')->nullable();
            $table->string('mac_seen', 17)->nullable();
            $table->timestamps();
            $table->unique(['subnet_id', 'ip_int']);
            $table->index(['tenant_id', 'ip']);
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'asset_id']);
            $table->index(['tenant_id', 'hostname']);
            $table->index(['tenant_id', 'mac_address']);
        });
        Rls::enable('ip_addresses');

        Schema::create('ip_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('ip_address_id')->constrained()->restrictOnDelete();
            $table->foreignId('reserved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reserved_at');
            $table->string('purpose');
            $table->text('notes')->nullable();
            $table->string('status', 20); // active | used | released
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'ip_address_id']);
        });
        Rls::enable('ip_reservations');

        Schema::create('ip_asset_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->index()->constrained()->restrictOnDelete();
            $table->foreignId('ip_address_id')->constrained()->restrictOnDelete();
            // reserved | assigned | released | excluded | included | updated
            $table->string('action', 20);
            $table->foreignId('asset_id')->nullable()->constrained()->nullOnDelete();
            $table->string('asset_code', 50)->nullable(); // kept even if the asset goes
            $table->string('hostname')->nullable();
            $table->string('mac_address', 17)->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'ip_address_id']);
        });
        Rls::enable('ip_asset_histories');
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_asset_histories');
        Schema::dropIfExists('ip_reservations');
        Schema::dropIfExists('ip_addresses');
        Schema::dropIfExists('subnets');
        Schema::dropIfExists('networks');
    }
};
