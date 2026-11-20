<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_post_releases', function (Blueprint $table): void {
            $table->foreignUuid('post_id')->constrained('posts')->cascadeOnDelete();
            $table->primary('post_id');
            $table->foreignId('revision_id')->nullable()->constrained('studio_revisions')->nullOnDelete();
            $table->foreignUuid('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('fingerprint', 64)->nullable();
            $table->timestampTz('approved_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_post_releases');
    }
};
