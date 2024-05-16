<?php

use Coreproc\NovaDataSync\Enum\Status;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(config('nova-data-sync.exports.table_name', 'exports'), function (Blueprint $table) {
            $table->id();
            $table->nullableMorphs('user');
            $table->string('filename')->nullable();
            $table->string('status')->default(Status::PENDING);
            $table->string('processor')->nullable();
            $table->bigInteger('file_total_rows')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('exports');
    }
};
