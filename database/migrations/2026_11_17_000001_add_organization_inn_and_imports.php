<?php

use App\Support\OrganizationIdentity;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_groups', function (Blueprint $table) {
            $table->string('inn', 12)->nullable()->unique();
            $table->string('name_key', 768)->nullable()->index();
        });
        DB::table('device_groups')->orderBy('id')->chunkById(500, function ($rows) {
            foreach ($rows as $row) {
                DB::table('device_groups')->where('id', $row->id)->update(['name_key' => OrganizationIdentity::nameKey($row->name)]);
            }
        });
        Schema::create('organization_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->longText('rows');
            $table->longText('preview');
            $table->text('result')->nullable();
            $table->timestamp('expires_at')->index();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();
        });
        Schema::table('device_work_groups', function (Blueprint $table) {
            $table->index('device_group_id', 'statusdesk_work_groups_organization_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_import_batches');
        Schema::table('device_work_groups', function (Blueprint $table) {
            $table->dropIndex('statusdesk_work_groups_organization_idx');
        });
        Schema::table('device_groups', function (Blueprint $table) {
            $table->dropUnique(['inn']);
            $table->dropIndex(['name_key']);
            $table->dropColumn(['inn', 'name_key']);
        });
    }
};
