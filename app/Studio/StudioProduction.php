<?php

declare(strict_types=1);

namespace App\Studio;

use App\Enums\Analytics\PublicationContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\User;
use App\Models\Workspace;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StudioProduction
{
    public static function settings(Workspace $workspace): StudioSetting
    {
        return StudioSetting::query()->firstOrNew(['workspace_id' => $workspace->id]);
    }

    /** @return list<array{id: string, label: string, configured: bool}> */
    public static function providers(): array
    {
        $configured = config('studio.providers', []);

        return collect([
            'codex' => 'OpenAI Codex',
            'claude' => 'Claude Code',
            'openai' => 'OpenAI API',
            'anthropic' => 'Anthropic API',
            'gemini' => 'Gemini API',
            'grok' => 'Grok API',
        ])->map(fn (string $label, string $id): array => [
            'id' => $id,
            'label' => $label,
            'configured' => (bool) ($configured[$id] ?? false),
        ])->values()->all();
    }

    /** @return list<array{id: string, label: string, platform: string}> */
    public static function channels(Workspace $workspace): array
    {
        return $workspace->socialAccounts()->connected()->whereIn('platform', [
            Platform::Instagram, Platform::InstagramFacebook, Platform::Facebook, Platform::YouTube, Platform::Threads,
        ])->get()->map(fn ($channel): array => [
            'id' => $channel->id,
            'label' => ($channel->display_name ?: $channel->username ?: $channel->platform->label()),
            'platform' => $channel->platform->value,
        ])->all();
    }

    public static function start(Workspace $workspace, User $actor, array $input): StudioRun
    {
        return DB::transaction(function () use ($workspace, $actor, $input): StudioRun {
            self::ensureWorkspaceIdle($workspace->id);

            return self::createProject($workspace, $actor, $input, self::settings($workspace));
        });
    }

    public static function schedule(StudioSetting $setting, CarbonInterface $now): ?StudioRun
    {
        return DB::transaction(function () use ($setting, $now): ?StudioRun {
            $workspace = Workspace::query()->lockForUpdate()->find($setting->workspace_id);
            $setting = StudioSetting::query()->lockForUpdate()->find($setting->id);
            if ($workspace === null || $setting === null || ! $setting->enabled) {
                return null;
            }

            $local = $now->avoidMutation()->setTimezone($setting->timezone);
            $date = $local->toDateString();
            if ($setting->last_scheduled_date === $date || $local->format('H:i') < $setting->run_at
                || blank($setting->creative_brief) || blank($setting->provider) || $setting->max_model_calls < 3
                || $setting->max_voice_characters < 36 || empty(config('studio.providers', [])[$setting->provider])) {
                return null;
            }

            $owner = $workspace->account?->owner;
            if ($owner === null || self::workspaceBusy($workspace->id)) {
                return null;
            }

            $run = self::createProject($workspace, $owner, [
                'title' => $workspace->name.' · '.$date,
                'brief' => $setting->creative_brief,
                'format' => 'reel',
            ], $setting);
            $setting->update(['last_scheduled_date' => $date]);

            return $run;
        });
    }

    public static function revise(StudioProject $project, User $actor, array $input): StudioNote
    {
        return DB::transaction(function () use ($project, $actor, $input): StudioNote {
            self::ensureWorkspaceIdle($project->workspace_id);
            $project = StudioProject::query()->lockForUpdate()->findOrFail($project->id);
            $revision = $project->revisions()->findOrFail($input['revision_id']);
            if (round((float) $input['time_seconds'], 3) >= $revision->duration_seconds) {
                throw ValidationException::withMessages(['time_seconds' => __('Pick a timestamp within this cut.')]);
            }

            $run = self::queue($project, 'revise', self::settings($project->workspace), [
                'note' => $input['note'],
                'time_seconds' => (float) $input['time_seconds'],
                'parent_id' => $revision->id,
            ]);

            return $project->notes()->create([
                'run_id' => $run->id,
                'revision_id' => $revision->id,
                'created_by' => $actor->id,
                'time_seconds' => $input['time_seconds'],
                'note' => $input['note'],
            ]);
        });
    }

    public static function retry(StudioProject $project, int $runId): StudioRun
    {
        return DB::transaction(function () use ($project, $runId): StudioRun {
            self::ensureWorkspaceIdle($project->workspace_id);
            $project = StudioProject::query()->lockForUpdate()->findOrFail($project->id);
            $run = $project->runs()->lockForUpdate()->findOrFail($runId);
            if (! in_array($run->status, ['failed', 'blocked', 'interrupted'], true)) {
                throw ValidationException::withMessages(['run_id' => __('This run cannot be resumed.')]);
            }

            if ($run->started_at === null && $run->storyboard_decision === null) {
                $payload = $run->request;
                $payload['settings'] = self::snapshot(self::settings($project->workspace));
                $run->request = $payload;
            }

            return self::requeue($project, $run);
        });
    }

    public static function approveStoryboard(StudioProject $project, int $runId, int $treatment, string $fingerprint): StudioRun
    {
        return DB::transaction(function () use ($project, $runId, $treatment, $fingerprint): StudioRun {
            self::ensureWorkspaceIdle($project->workspace_id);
            $project = StudioProject::query()->lockForUpdate()->findOrFail($project->id);
            $run = $project->runs()->lockForUpdate()->findOrFail($runId);
            $storyboard = $run->status === 'awaiting_storyboard' ? $run->storyboard() : null;
            if ($storyboard === null || ! hash_equals($storyboard['fingerprint'], $fingerprint)) {
                throw ValidationException::withMessages(['fingerprint' => __('This storyboard has changed or was already approved. Reload Studio to check its status.')]);
            }

            $characters = array_sum(array_map(
                fn (array $shot): int => mb_strlen($shot['speech'], 'UTF-8'),
                $storyboard['treatments'][$treatment]['shots'],
            ));
            if ($characters > $run->request['settings']['max_voice_characters']) {
                throw ValidationException::withMessages([
                    'treatment' => __('This treatment exceeds the saved narration budget. Choose the other treatment or raise the budget and start a new run.'),
                ]);
            }

            $run->storyboard_decision = ['treatment' => $treatment, 'fingerprint' => $fingerprint];

            return self::requeue($project, $run);
        });
    }

    private static function requeue(StudioProject $project, StudioRun $run): StudioRun
    {
        $run->fill(['status' => 'queued', 'stage' => 'queued', 'message' => null, 'error' => null, 'finished_at' => null])->save();
        $project->update(['status' => 'queued']);
        StudioRunJob::dispatch($run->id)->afterCommit();

        return $run;
    }

    private static function ensureWorkspaceIdle(string $workspaceId): void
    {
        Workspace::query()->lockForUpdate()->findOrFail($workspaceId);
        if (self::workspaceBusy($workspaceId)) {
            throw ValidationException::withMessages(['run_id' => __('This workspace already has an active production run.')]);
        }
    }

    private static function workspaceBusy(string $workspaceId): bool
    {
        return StudioRun::query()->active()->whereHas('project', fn ($query) => $query->forWorkspace($workspaceId))->exists();
    }

    private static function createProject(Workspace $workspace, User $actor, array $input, StudioSetting $settings): StudioRun
    {
        $title = filled($input['title'] ?? null) ? trim($input['title']) : Str::limit(trim($input['brief']), 96);
        $project = StudioProject::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $actor->id,
            'title' => $title,
            'brief' => $input['brief'],
            'format' => $input['format'],
            'status' => 'queued',
        ]);

        return self::queue($project, 'produce', $settings, reviewStoryboard: (bool) ($input['review_storyboard'] ?? false));
    }

    private static function queue(StudioProject $project, string $kind, StudioSetting $settings, ?array $revision = null, bool $reviewStoryboard = false): StudioRun
    {
        $key = (string) Str::uuid();
        $run = $project->runs()->create([
            'execution_key' => $key,
            'kind' => $kind,
            'status' => 'queued',
            'stage' => 'queued',
            'request' => [
                'version' => 1,
                'run_id' => $key,
                'kind' => $kind,
                'project' => [
                    'id' => $project->id,
                    'title' => $project->title,
                    'brief' => $project->brief,
                    'format' => $project->format,
                    'workspace_name' => Str::limit($project->workspace->name, 55, ''),
                ],
                'settings' => self::snapshot($settings),
                'evidence' => $kind === 'produce' ? self::evidence($project->workspace_id) : [],
                'revision' => $revision,
            ] + ($reviewStoryboard ? ['review_storyboard' => true] : []),
        ]);
        $project->update(['status' => 'queued']);
        StudioRunJob::dispatch($run->id)->afterCommit();

        return $run;
    }

    private static function snapshot(StudioSetting $setting): array
    {
        return $setting->only([
            'timezone', 'brand_guide', 'creative_brief', 'provider', 'alternative_provider',
            'max_model_calls', 'max_voice_characters', 'voice_id',
        ]);
    }

    /** @return list<array<string, mixed>> */
    private static function evidence(string $workspaceId): array
    {
        $publications = AnalyticsPublication::query()->available()
            ->where('workspace_id', $workspaceId)->where('network', Platform::Instagram->network())
            ->whereIn('content_type', [PublicationContentType::Reel, PublicationContentType::Video])
            ->where('provider_published_at', '>=', now()->subDays(30))->latest('provider_published_at')->limit(12)
            ->get(['id', 'excerpt', 'permalink', 'provider_published_at', 'content_type']);
        $snapshots = AnalyticsPublicationDailySnapshot::query()->whereIn('publication_id', $publications->modelKeys())
            ->latest('date')->latest('collected_at')
            ->get(['publication_id', 'collected_at', 'views_count', 'reach_count', 'shares_count', 'average_watch_time_milliseconds'])
            ->unique('publication_id')->keyBy('publication_id');
        $evidence = $publications->toBase()->map(function (AnalyticsPublication $publication) use ($snapshots): array {
            $snapshot = $snapshots->get($publication->id);

            return [
                'type' => 'instagram_publication',
                'title' => Str::limit((string) $publication->excerpt, 220),
                'url' => $publication->permalink,
                'published_at' => $publication->provider_published_at?->toIso8601String(),
                'collected_at' => $snapshot?->collected_at?->toIso8601String(),
                'content_type' => $publication->content_type?->value,
                'views' => $snapshot?->views_count,
                'reach' => $snapshot?->reach_count,
                'shares' => $snapshot?->shares_count,
                'average_watch_time_ms' => $snapshot?->average_watch_time_milliseconds,
            ];
        });
        $notes = StudioNote::query()
            ->whereIn('project_id', StudioProject::query()->forWorkspace($workspaceId)->select('id'))
            ->latest()->limit(5)->get(['note', 'created_at']);

        return $evidence->merge($notes->map(fn (StudioNote $note): array => [
            'type' => 'owner_review_note',
            'note' => Str::limit($note->note, 350),
            'recorded_at' => $note->created_at?->toIso8601String(),
        ]))->all();
    }
}
