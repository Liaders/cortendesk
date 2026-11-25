<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 10);
            $table->string('value', 100);
            $table->string('reason', 500);
            $table->unsignedBigInteger('device_id')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->unsignedBigInteger('revoked_by')->nullable();
            $table->timestamps();
            $table->index(['kind', 'value', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_blocks');
    }
};
