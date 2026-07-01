<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('system_backups', function (Blueprint $table) {
            $table->id();
            $table->string('backup_type')->default('manual'); // manual, automatic
            $table->string('status')->default('completed'); // completed, in_progress, failed
            $table->integer('tables_count')->default(0);
            $table->integer('records_count')->default(0);
            $table->integer('size_bytes')->default(0);
            $table->timestamp('restored_at')->nullable();
            $table->string('filename')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_backups');
    }
};
