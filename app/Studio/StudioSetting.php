<?php

declare(strict_types=1);

namespace App\Studio;

use App\Models\Workspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudioSetting extends Model
{
    protected $fillable = [
        'workspace_id', 'enabled', 'run_at', 'timezone', 'last_scheduled_date', 'brand_guide', 'creative_brief',
        'provider', 'alternative_provider', 'max_model_calls', 'max_voice_characters', 'voice_id',
    ];

    protected $attributes = [
        'enabled' => false, 'run_at' => '09:00', 'timezone' => 'UTC',
        'max_model_calls' => 0, 'max_voice_characters' => 0,
    ];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'max_model_calls' => 'integer', 'max_voice_characters' => 'integer'];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
