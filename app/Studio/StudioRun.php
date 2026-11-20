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
        'request', 'storyboard_decision', 'usage', 'events', 'started_at', 'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'request' => 'array', 'storyboard_decision' => 'array', 'usage' => 'array', 'events' => 'array',
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
        $content = $this->readPrivateFile($name);
        $value = $content === null ? null : json_decode($content, true);

        return is_array($value) ? $value : null;
    }

    public function needsStoryboardApproval(): bool
    {
        return $this->kind === 'produce' && ($this->request['review_storyboard'] ?? false) === true
            && $this->storyboard_decision === null;
    }

    public function storyboard(): ?array
    {
        if (! $this->needsStoryboardApproval()) {
            return null;
        }

        $content = $this->readPrivateFile('storyboard.json');
        $plan = $content === null ? null : json_decode($content, true, 32);
        $review = $plan['review'] ?? null;
        $treatments = $plan['treatments'] ?? null;
        if (($plan['version'] ?? null) !== 1 || ($plan['status'] ?? null) !== 'awaiting_storyboard'
            || ! in_array($review['selected'] ?? null, [0, 1], true)
            || ! self::hasTextFields($review, ['rationale', 'assessment_a', 'assessment_b'])
            || ! is_array($treatments) || ! array_is_list($treatments) || count($treatments) !== 2) {
            return null;
        }

        $treatmentFields = ['title', 'angle', 'hook', 'payoff', 'caption', 'script', 'provider', 'model', 'assessment'];
        $shotFields = ['setting', 'camera', 'character', 'secondary', 'expression', 'action', 'prop', 'speech'];
        foreach ($treatments as $index => $treatment) {
            $shots = $treatment['shots'] ?? null;
            if (! self::hasTextFields($treatment, $treatmentFields)
                || ($treatment['selected'] ?? null) !== ($index === $review['selected'])
                || ! is_array($shots) || ! array_is_list($shots) || count($shots) < 3 || count($shots) > 7) {
                return null;
            }

            foreach ($shots as $shot) {
                if (! self::hasTextFields($shot, $shotFields)) {
                    return null;
                }
            }

            $treatments[$index] = array_intersect_key($treatment, array_flip([...$treatmentFields, 'selected'])) + [
                'shots' => array_map(fn (array $shot): array => array_intersect_key($shot, array_flip($shotFields)), $shots),
            ];
        }

        return [
            'run_id' => $this->id,
            'fingerprint' => hash('sha256', $content),
            'treatments' => $treatments,
            'review' => array_intersect_key($review, array_flip(['selected', 'rationale', 'assessment_a', 'assessment_b'])),
        ];
    }

    private function readPrivateFile(string $name): ?string
    {
        $path = Storage::disk('local')->path($this->directory().'/'.$name);
        $maxBytes = 1048576;
        if (! is_file($path) || is_link($path) || filesize($path) > $maxBytes) {
            return null;
        }

        $value = file_get_contents($path, false, null, 0, $maxBytes + 1);

        return $value !== false && strlen($value) <= $maxBytes ? $value : null;
    }

    private static function hasTextFields(mixed $value, array $fields): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($fields as $field) {
            if (! is_string($value[$field] ?? null) || trim($value[$field]) === '') {
                return false;
            }
        }

        return true;
    }
}
