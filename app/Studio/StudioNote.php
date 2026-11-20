<?php

declare(strict_types=1);

namespace App\Studio;

use Illuminate\Database\Eloquent\Model;

class StudioNote extends Model
{
    protected $fillable = ['project_id', 'revision_id', 'run_id', 'created_by', 'time_seconds', 'note'];

    protected function casts(): array
    {
        return ['time_seconds' => 'float'];
    }
}
