<?php

declare(strict_types=1);

namespace App\Studio;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudioProject extends Model
{
    protected $fillable = ['workspace_id', 'created_by', 'title', 'brief', 'format', 'status', 'finalist_revision_id'];

    public function scopeForWorkspace(Builder $query, string $workspaceId): Builder
    {
        return $query->where('workspace_id', $workspaceId);
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function runs(): HasMany
    {
        return $this->hasMany(StudioRun::class, 'project_id');
    }

    public function revisions(): HasMany
    {
        return $this->hasMany(StudioRevision::class, 'project_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(StudioNote::class, 'project_id');
    }
}
