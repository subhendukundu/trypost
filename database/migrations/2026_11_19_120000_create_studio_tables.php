<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('studio_settings', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('enabled')->default(false);
            $table->string('run_at', 5)->default('09:00');
            $table->string('timezone')->default('UTC');
            $table->date('last_scheduled_date')->nullable();
            $table->text('brand_guide')->nullable();
            $table->text('creative_brief')->nullable();
            $table->string('provider')->nullable();
            $table->string('alternative_provider')->nullable();
            $table->unsignedSmallInteger('max_model_calls')->default(0);
            $table->unsignedInteger('max_voice_characters')->default(0);
            $table->string('voice_id')->nullable();
            $table->timestamps();
        });

        Schema::create('studio_projects', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title');
            $table->text('brief');
            $table->unsignedBigInteger('finalist_revision_id')->nullable();
            $table->string('format');
            $table->string('status');
            $table->timestamps();
            $table->index(['workspace_id', 'updated_at']);
        });

        Schema::create('studio_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('studio_projects')->cascadeOnDelete();
            $table->uuid('execution_key')->unique();
            $table->string('kind');
            $table->string('status');
            $table->string('stage');
            $table->text('message')->nullable();
            $table->text('error')->nullable();
            $table->json('request');
            $table->json('usage')->nullable();
            $table->json('events')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'updated_at']);
        });

        Schema::create('studio_revisions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('studio_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('run_id')->unique();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->unsignedInteger('version');
            $table->decimal('duration_seconds', 8, 3);
            $table->json('manifest');
            $table->timestamps();
            $table->unique(['project_id', 'version']);
        });

        Schema::create('studio_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('revision_id')->constrained('studio_revisions')->cascadeOnDelete();
            $table->string('kind');
            $table->string('title');
            $table->string('path');
            $table->string('mime');
            $table->string('sha256', 64);
            $table->unsignedBigInteger('bytes');
            $table->timestamps();
            $table->unique(['revision_id', 'kind']);
        });

        Schema::create('studio_notes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('project_id')->constrained('studio_projects')->cascadeOnDelete();
            $table->unsignedBigInteger('revision_id');
            $table->unsignedBigInteger('run_id');
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('time_seconds', 8, 3);
            $table->text('note');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('studio_notes');
        Schema::dropIfExists('studio_artifacts');
        Schema::dropIfExists('studio_revisions');
        Schema::dropIfExists('studio_runs');
        Schema::dropIfExists('studio_projects');
        Schema::dropIfExists('studio_settings');
    }
};
