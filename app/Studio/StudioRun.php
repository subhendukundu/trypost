<?php

declare(strict_types=1);

namespace App\Studio;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Storage;

class StudioRun extends Model
{
    protected $fillable = [
        'project_id', 'execution_key', 'kind', 'status', 'stage', 'message', 'error',
        'request', 'usage', 'events', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array', 'usage' => 'array', 'events' => 'array',
            'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(StudioProject::class, 'project_id');
    }

    public function revision(): HasOne
    {
        return $this->hasOne(StudioRevision::class, 'run_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', ['queued', 'running']);
    }

    public function directory(): string
    {
        return 'studio/runs/'.$this->execution_key;
    }

    public function readPrivateJson(string $name): ?array
    {
        $path = Storage::disk('local')->path($this->directory().'/'.$name);
        if (! is_file($path) || is_link($path) || filesize($path) > 1048576) {
            return null;
        }

        $value = json_decode((string) file_get_contents($path), true);

        return is_array($value) ? $value : null;
    }
}
