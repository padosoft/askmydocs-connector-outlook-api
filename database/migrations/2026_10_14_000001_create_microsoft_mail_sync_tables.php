<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['microsoft_mail_sync_runs', 'microsoft_mail_sync_folders', 'microsoft_mail_sync_messages'] as $name) {
            Schema::create($name, function (Blueprint $table) use ($name): void {
                $table->string('key', 64)->primary();
                $table->string('scope', 64)->index();
                $table->string('tenant_id', 50);
                $table->unsignedBigInteger('installation_id')->index();
                $table->text('mailbox_id');
                $table->longText('data');
                if ($name === 'microsoft_mail_sync_messages') {
                    $table->string('folder_key', 64)->nullable()->index();
                    $table->string('generation', 64)->nullable()->index();
                    $table->boolean('active')->default(false);
                    $table->boolean('pending_tombstone')->default(false);
                    $table->index(['scope', 'pending_tombstone', 'key'], 'microsoft_mail_pending_tombstones');
                }
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_mail_sync_messages');
        Schema::dropIfExists('microsoft_mail_sync_folders');
        Schema::dropIfExists('microsoft_mail_sync_runs');
    }
};
