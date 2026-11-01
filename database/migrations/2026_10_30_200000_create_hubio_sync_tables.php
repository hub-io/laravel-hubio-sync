<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Households of this device, deletions waiting for upload and the photos already exchanged
 * with Hubio.Space (by checksum, per household).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('households')) {
            Schema::create('households', function (Blueprint $table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('name', 100);
                $table->boolean('is_personal')->default(false);
                $table->string('invite_code', 16)->nullable();
                $table->json('members')->nullable();
                $table->unsignedBigInteger('cursor')->default(0);
                $table->timestamps();
            });
        }
        if (! Schema::hasTable('sync_tombstones')) {
            Schema::create('sync_tombstones', function (Blueprint $table) {
                $table->id();
                $table->string('type', 32);
                $table->uuid('uuid');
                $table->uuid('household_uuid')->nullable();
                $table->string('deleted_at', 32);
            });
        }
        Schema::create('sync_files', function (Blueprint $table) {
            $table->id();
            $table->uuid('household_uuid');
            $table->char('checksum', 64);
            $table->uuid('remote_uuid');
            $table->string('path', 512)->nullable();
            $table->unique(['household_uuid', 'checksum']);
            $table->index('remote_uuid');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_files');
        Schema::dropIfExists('sync_tombstones');
        Schema::dropIfExists('households');
    }
};
