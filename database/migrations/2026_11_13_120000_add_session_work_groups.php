<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_work_groups', function (Blueprint $table) {
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->foreignId('device_group_id')->constrained()->cascadeOnDelete();
            $table->primary(['device_id', 'device_group_id']);
        });
        Schema::table('audit_connections', function (Blueprint $table) {
            // Historical attribution must survive deletion of the group.
            $table->unsignedBigInteger('work_group_id')->nullable()->index();
            $table->string('work_group_name')->nullable();
            $table->string('work_group_status', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('audit_connections', function (Blueprint $table) {
            $table->dropIndex(['work_group_id']);
            $table->dropColumn(['work_group_id', 'work_group_name', 'work_group_status']);
        });
        Schema::dropIfExists('device_work_groups');
    }
};
