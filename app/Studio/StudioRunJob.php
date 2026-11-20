<?php

declare(strict_types=1);

namespace App\Studio;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;

class StudioRunJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 1500;

    public function __construct(public int $runId)
    {
        $this->onConnection('studio')->onQueue('studio');
    }

    public function handle(): void
    {
        $run = StudioRun::query()->find($this->runId);
        if ($run === null || ! $this->claim($run)) {
            return;
        }

        $manifest = $run->readPrivateJson('manifest.json');
        if ($manifest === null) {
            if ($this->pauseForStoryboard($run)) {
                return;
            }

            $problem = $this->configurationProblem($run);
            if ($problem !== null) {
                self::finish($run, 'blocked', $problem);

                return;
            }

            $run->update(['started_at' => $run->started_at ?? now()]);
            $this->produce($run);
            $manifest = $run->readPrivateJson('manifest.json');
        }

        if ($manifest === null) {
            if ($this->pauseForStoryboard($run)) {
                return;
            }

            self::finish($run, 'failed', self::failureMessage($run->readPrivateJson('failure.json')['code'] ?? ''));

            return;
        }

        if ($run->needsStoryboardApproval()) {
            self::finish($run, 'failed', __('Studio received a rendered cut before the storyboard was approved. Check the Studio worker version.'));

            return;
        }

        $artifacts = $this->verifiedArtifacts($run, $manifest);
        $this->saveRevision($run, $manifest, $artifacts);
    }

    public function failed(?Throwable $exception): void
    {
        $run = StudioRun::query()->find($this->runId);
        if ($run !== null) {
            self::finish($run, 'failed', __('The run stopped before Studio could save a verified cut. Resume to check the saved production steps.'));
        }
    }

    public static function finish(StudioRun $run, string $status, string $message): void
    {
        DB::transaction(function () use ($run, $status, $message): void {
            $project = StudioProject::query()->lockForUpdate()->find($run->project_id);
            $current = StudioRun::query()->active()->lockForUpdate()->find($run->id);
            if ($project === null || $current === null) {
                return;
            }

            $progress = $current->readPrivateJson('progress.json');
            $waiting = $status === 'awaiting_storyboard';
            $current->update([
                'status' => $status,
                'stage' => $status,
                'message' => $waiting ? $message : null,
                'error' => $waiting ? null : $message,
                'events' => $progress['events'] ?? $current->events,
                'finished_at' => $waiting ? null : now(),
            ]);
            $project->update(['status' => $status]);
        });
    }

    private function claim(StudioRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            $project = StudioProject::query()->lockForUpdate()->find($run->project_id);
            $claimed = StudioRun::query()->whereKey($run->id)->where('status', 'queued')->update([
                'status' => 'running',
                'stage' => 'preflight',
                'message' => __('Checking production settings'),
            ]);
            if ($project === null || $claimed === 0) {
                return false;
            }

            $project->update(['status' => 'running']);
            $run->refresh();

            return true;
        });
    }

    private function pauseForStoryboard(StudioRun $run): bool
    {
        if ($run->storyboard() === null) {
            return false;
        }

        self::finish($run, 'awaiting_storyboard', __('Two storyboards are ready. Choose one before Studio records narration.'));

        return true;
    }

    private function configurationProblem(StudioRun $run): ?string
    {
        $settings = $run->request['settings'];
        if ($run->storyboard_decision === null) {
            $providers = config('studio.providers', []);
            if (blank($settings['provider']) || empty($providers[$settings['provider']])) {
                return __('Configure and select a connected model provider in Studio settings, then resume this run.');
            }

            if (filled($settings['alternative_provider']) && empty($providers[$settings['alternative_provider']])) {
                return __('The alternative model provider is unavailable. Check Studio settings before resuming.');
            }

            $minimumCalls = $run->kind === 'revise' ? 1 : 3;
            if ($settings['max_model_calls'] < $minimumCalls) {
                return __('Set a model call budget for this production in Studio settings, then resume.');
            }
        }

        if ($run->kind === 'produce' && $settings['max_voice_characters'] < 36) {
            return __('Set a voice character budget in Studio settings, then resume.');
        }

        if ($run->needsStoryboardApproval()) {
            return null;
        }

        if ($run->kind === 'produce' && config('studio.voice_configured') === false) {
            return __('Connect the narration provider on the Studio server, then resume.');
        }

        if ($run->kind === 'produce' && config('studio.voice_configured') === true
            && blank($settings['voice_id']) && blank(config('studio.voice_id'))) {
            return $run->storyboard_decision === null
                ? __('Add a narration voice in Studio settings, then resume.')
                : __('Configure the Studio server’s default narration voice, then resume this approved storyboard.');
        }

        return null;
    }

    private function produce(StudioRun $run): void
    {
        $disk = Storage::disk('local');
        $directory = $disk->path($run->directory());
        $disk->makeDirectory($run->directory());
        chmod($directory, 0700);

        $input = $directory.'/request.json';
        if ($run->storyboard_decision === null) {
            $this->writeRequest($run, $directory, $input);
        } else {
            if (! is_file($input) || is_link($input)) {
                throw new RuntimeException('The saved Studio storyboard request is missing.');
            }

            $this->writePrivateJson($directory.'/storyboard-approval.json', $run->storyboard_decision);
        }

        $process = new Process([
            config('studio.python', 'python3'),
            config('studio.runner', base_path('scripts/studio/run.py')),
            $input,
            $directory,
        ], base_path(), env: config('studio.environment', []), timeout: 1380);
        $process->disableOutput();
        $process->run();
    }

    private function writeRequest(StudioRun $run, string $directory, string $input): void
    {
        $disk = Storage::disk('local');
        $payload = $run->request;
        if ($run->kind === 'revise') {
            $parent = $run->project->revisions()->with('run')->findOrFail($payload['revision']['parent_id']);
            $payload['revision']['parent_manifest'] = $parent->manifest;
            $payload['revision']['parent_dir'] = $disk->path($parent->run->directory());
        } else {
            $payload['settings']['logo_path'] = $this->workspaceLogo($run, $directory, $input);
        }

        $this->writePrivateJson($input, $payload);
    }

    private function writePrivateJson(string $path, array $payload): void
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        if (file_put_contents($path.'.tmp', $encoded, LOCK_EX) === false || ! rename($path.'.tmp', $path)) {
            throw new RuntimeException('Unable to save a private Studio run file.');
        }

        chmod($path, 0600);
    }

    private function workspaceLogo(StudioRun $run, string $directory, string $input): ?string
    {
        $path = $directory.'/workspace-logo';
        if (! is_file($input) && ! is_file($path)) {
            $logo = $run->project->workspace->getFirstMedia('logo');
            if ($logo !== null && $logo->size <= 2097152) {
                $content = Storage::get($logo->path);
                if ($content !== null && strlen($content) <= 2097152
                    && (file_put_contents($path.'.tmp', $content, LOCK_EX) === false || ! rename($path.'.tmp', $path))) {
                    throw new RuntimeException('Unable to save the workspace logo for this run.');
                }
            }
        }

        return is_file($path) && ! is_link($path) ? $path : null;
    }

    private function verifiedArtifacts(StudioRun $run, array $manifest): array
    {
        if (($manifest['version'] ?? null) !== 1 || ($manifest['status'] ?? null) !== 'ready_for_review'
            || ($manifest['verification']['full_decode_passed'] ?? null) !== true
            || ! is_numeric($manifest['duration_seconds'] ?? null) || (float) $manifest['duration_seconds'] <= 0
            || (float) $manifest['duration_seconds'] > 600 || ! is_string($manifest['title'] ?? null)
            || ! is_array($manifest['artifacts'] ?? null)) {
            throw new RuntimeException('Invalid Studio manifest.');
        }

        $allowed = [
            'video' => ['video/mp4'],
            'cover' => ['image/jpeg', 'image/png'],
            'captions' => ['text/vtt', 'application/x-subrip', 'text/plain'],
            'source' => ['application/zip', 'application/json'],
        ];
        $disk = Storage::disk('local');
        $directory = realpath($disk->path($run->directory()));
        $artifacts = [];
        foreach ($manifest['artifacts'] as $artifact) {
            $kind = $artifact['kind'] ?? '';
            $file = $artifact['file'] ?? '';
            $mime = $artifact['mime'] ?? '';
            $hash = $artifact['sha256'] ?? '';
            if (! isset($allowed[$kind]) || isset($artifacts[$kind]) || ! in_array($mime, $allowed[$kind], true)
                || ! preg_match('/^[a-zA-Z0-9][a-zA-Z0-9._-]{0,127}$/D', $file) || ! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
                throw new RuntimeException('Invalid Studio artifact descriptor.');
            }

            $relative = $run->directory().'/'.$file;
            $absolute = $disk->path($relative);
            $path = realpath($absolute);
            if ($directory === false || $path === false || dirname($path) !== $directory || is_link($absolute) || ! is_file($path)) {
                throw new RuntimeException('Missing Studio artifact.');
            }

            $size = filesize($path);
            if ($size === false || $size < 1 || $size > 1073741824 || (int) ($artifact['bytes'] ?? 0) !== $size
                || ! hash_equals($hash, (string) hash_file('sha256', $path))) {
                throw new RuntimeException('Invalid Studio artifact checksum.');
            }

            $artifacts[$kind] = [
                'kind' => $kind,
                'title' => mb_substr($artifact['title'] ?? ucfirst($kind), 0, 120),
                'path' => $relative,
                'mime' => $mime,
                'sha256' => $hash,
                'bytes' => $size,
            ];
        }

        if (! isset($artifacts['video'], $artifacts['cover'], $artifacts['captions'])) {
            throw new RuntimeException('Missing deliverable in Studio output.');
        }

        return array_values($artifacts);
    }

    private function saveRevision(StudioRun $run, array $manifest, array $artifacts): void
    {
        DB::transaction(function () use ($run, $manifest, $artifacts): void {
            $project = StudioProject::query()->lockForUpdate()->findOrFail($run->project_id);
            $run = $project->runs()->lockForUpdate()->findOrFail($run->id);
            if ($run->status !== 'running') {
                return;
            }

            $revision = $project->revisions()->create([
                'run_id' => $run->id,
                'version' => 1 + (int) $project->revisions()->max('version'),
                'parent_id' => $run->request['revision']['parent_id'] ?? null,
                'duration_seconds' => $manifest['duration_seconds'],
                'manifest' => $manifest,
            ]);
            $revision->artifacts()->createMany($artifacts);
            $run->update([
                'status' => 'ready_for_review',
                'stage' => 'ready_for_review',
                'message' => __('Verified cut ready for review'),
                'usage' => $manifest['usage'] ?? null,
                'events' => $run->readPrivateJson('progress.json')['events'] ?? null,
                'finished_at' => now(),
            ]);
            $project->update(['status' => 'ready_for_review']);
        });
    }

    private static function failureMessage(string $code): string
    {
        return match ($code) {
            'budget_exhausted' => __('The run reached its production budget. The saved cut is unchanged.'),
            'provider_not_configured', 'voice_not_configured', 'invalid_provider_config' => __('A production provider needs setup on the Studio server.'),
            'provider_auth' => __('A production provider declined this connection. Check the account and model settings.'),
            'needs_credits' => __('A production provider needs credits or an active billing plan.'),
            'provider_quota' => __('A production provider reached its usage limit.'),
            'provider_outcome_unknown' => __('A provider request ended without confirmation. Studio paused to avoid repeating the request.'),
            'invalid_editorial_output', 'duplicate_treatments' => __('The writer or reviewer delivered an unusable result.'),
            'invalid_storyboard_approval' => __('Studio could not match the saved storyboard to its approval. Restore the original storyboard before resuming.'),
            'film_verification_failed', 'shot_verification_failed' => __('The cut failed video, audio or timing verification.'),
            'invalid_brand_mark' => __('The workspace logo must be a still PNG, JPEG or WebP up to 4096 pixels in either dimension.'),
            'render_not_configured', 'media_tool_failed' => __('The render tools could not complete this cut. Check the Studio worker.'),
            'provider_config_changed', 'run_input_changed' => __('A saved production step uses different provider settings. Restore the original settings before resuming.'),
            'unsupported_revision' => __('This revision can change the visuals and hold timing while preserving the existing voice. Add a new visual note to request another change.'),
            'missing_paid_voice', 'invalid_source' => __('A saved voice take or source shot is missing or has changed.'),
            default => __('Production ended without a verified cut. Resume to continue from the saved checkpoints.'),
        };
    }
}
