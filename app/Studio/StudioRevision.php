<?php

declare(strict_types=1);

namespace App\Studio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StudioRevision extends Model
{
    protected $fillable = ['project_id', 'run_id', 'parent_id', 'version', 'duration_seconds', 'manifest'];

    protected function casts(): array
    {
        return ['manifest' => 'array', 'duration_seconds' => 'float'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(StudioProject::class, 'project_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(StudioRun::class, 'run_id');
    }

    public function artifacts(): HasMany
    {
        return $this->hasMany(StudioArtifact::class, 'revision_id');
    }
}
