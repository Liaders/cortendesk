<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->json('connection_report_filters')->nullable());
        // Make registration visible once for existing custom column selections.
        DB::table('users')->whereNotNull('devices_columns')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                $columns = json_decode($user->devices_columns, true);
                if (is_array($columns) && array_is_list($columns) && ! in_array('first_seen', $columns, true)) {
                    $columns[] = 'first_seen';
                    DB::table('users')->where('id', $user->id)->update(['devices_columns' => json_encode($columns)]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('connection_report_filters'));
    }
};
