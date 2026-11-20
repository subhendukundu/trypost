<?php

declare(strict_types=1);

namespace App\Studio;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class StudioArtifact extends Model
{
    protected $fillable = ['revision_id', 'kind', 'title', 'path', 'mime', 'sha256', 'bytes'];

    public function revision(): BelongsTo
    {
        return $this->belongsTo(StudioRevision::class, 'revision_id');
    }

    public function privatePath(): string
    {
        $disk = Storage::disk('local');
        $root = realpath($disk->path($this->revision->run->directory()));
        $path = realpath($disk->path($this->path));

        abort_unless($root !== false && $path !== false && str_starts_with($path, $root.DIRECTORY_SEPARATOR)
            && is_file($path) && filesize($path) === (int) $this->bytes, 404);

        return $path;
    }

    public function verifiedPath(): string
    {
        $path = $this->privatePath();
        abort_unless(hash_equals($this->sha256, (string) hash_file('sha256', $path)), 409, 'The saved Studio file has changed.');

        return $path;
    }
}
