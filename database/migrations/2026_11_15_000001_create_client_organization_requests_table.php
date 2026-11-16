<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_organization_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('operation', 16);
            $table->uuid('request_id');
            $table->string('payload_hash', 64);
            // Keep the receipt even when the organization is deleted.
            $table->unsignedBigInteger('organization_id')->nullable()->index();
            $table->text('response')->nullable();
            $table->timestamp('created_at');
            $table->unique(['user_id', 'operation', 'request_id'], 'client_org_request_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_organization_requests');
    }
};
