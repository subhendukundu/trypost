<?php

declare(strict_types=1);

namespace App\Studio;

use App\Enums\Post\ScheduleMode;
use App\Enums\Post\Status;
use App\Jobs\PublishPost;
use App\Models\Post;
use App\Models\User;
use App\Support\PostCompositionValidator;
use App\Support\PostPlatformMetaRules;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StudioRelease
{
    public const string MEDIA_ORIGIN = 'studio_revision_id';

    public const string APPROVAL_REQUIRED = 'Open Studio release review to approve this post as the account owner.';

    public static function record(Post $post, ?int $revisionId): void
    {
        self::forPost($post)->insertOrIgnore(['post_id' => $post->id, 'revision_id' => $revisionId]);
    }

    public static function draftFor(int $revisionId, string $channelId): ?string
    {
        return DB::table('studio_post_releases as releases')
            ->join('posts', 'posts.id', '=', 'releases.post_id')
            ->where('releases.revision_id', $revisionId)
            ->where('posts.social_account_id', $channelId)
            ->where('posts.status', '!=', Status::Failed->value)
            ->orderBy('posts.created_at')
            ->value('posts.id');
    }

    public static function tracks(Post $post): bool
    {
        return self::forPost($post)->exists();
    }

    public static function copyTo(Post $original, Post $copy): void
    {
        $origin = self::forPost($original)->first();

        if ($origin !== null) {
            self::record($copy, $origin->revision_id);
        }
    }

    /**
     * @param  list<int>  $revisionIds  Revisions the caller has already scoped to the workspace.
     * @return array<int, list<array{url: string, channel: string, status: string}>>
     */
    public static function forRevisions(array $revisionIds): array
    {
        return DB::table('studio_post_releases as releases')
            ->join('posts', 'posts.id', '=', 'releases.post_id')
            ->leftJoin('social_accounts as channels', 'channels.id', '=', 'posts.social_account_id')
            ->whereIn('releases.revision_id', $revisionIds)
            ->orderByDesc('posts.created_at')
            ->get(['releases.revision_id', 'posts.id', 'posts.status', 'channels.display_name', 'channels.username', 'posts.platform'])
            ->groupBy('revision_id')
            ->map(fn ($posts) => $posts->map(fn ($post) => [
                'url' => route('app.studio.release', $post->id, absolute: false),
                'channel' => $post->display_name ?: ($post->username ?: $post->platform),
                'status' => $post->status,
            ])->all())
            ->all();
    }

    public static function requiresOwnerReview(): never
    {
        throw ValidationException::withMessages(['status' => self::APPROVAL_REQUIRED]);
    }

    public static function approve(Post $post, User $owner, string $reviewed, ?CarbonImmutable $scheduledAt): Post
    {
        return DB::transaction(function () use ($post, $owner, $reviewed, $scheduledAt): Post {
            $current = Post::query()->lockForUpdate()->findOrFail($post->id);
            abort_unless($owner->ownsAccountOf($current->workspace), 403);
            $release = self::forPost($current)->lockForUpdate()->first();
            abort_if($release === null, 404);

            if ($release->revision_id === null || $current->status !== Status::Draft
                || ! hash_equals(self::fingerprint($current), $reviewed)) {
                throw ValidationException::withMessages(['status' => 'This draft changed. Open it again to review the current version.']);
            }

            $status = $scheduledAt === null ? Status::Publishing : Status::Scheduled;
            PostCompositionValidator::forSinglePost(fn () => PostCompositionValidator::validate($current->workspace, [
                'status' => $status->value,
                'scheduled_at' => $scheduledAt?->toIso8601String(),
                'content' => $current->content,
                'media' => $current->media ?? [],
                'destinations' => [[
                    'social_account_id' => $current->social_account_id,
                    'content_type' => $current->content_type->value,
                    'meta' => $current->meta ?? [],
                ]],
            ], $current->media ?? []));

            $current->update([
                ...Post::withoutRecurrence(),
                'status' => $status,
                'scheduled_at' => $scheduledAt ?? now(),
                'schedule_mode' => $scheduledAt === null ? null : ScheduleMode::Custom,
            ]);
            self::forPost($current)->update([
                'approved_by' => $owner->id,
                'approved_at' => now(),
                'fingerprint' => self::fingerprint($current->refresh()),
            ]);

            if ($status === Status::Publishing) {
                PublishPost::dispatch($current)->afterCommit();
            }

            return $current;
        });
    }

    public static function permits(Post $post): bool
    {
        $release = self::forPost($post)->first();

        return $release === null || (
            $release->revision_id !== null
            && $release->approved_by !== null
            && $release->approved_by === $post->workspace->account?->owner_id
            && in_array($post->status, [Status::Scheduled, Status::Publishing], true)
            && $post->scheduled_at !== null
            && ! $post->scheduled_at->isFuture()
            && is_string($release->fingerprint)
            && hash_equals($release->fingerprint, self::fingerprint($post))
        );
    }

    public static function fingerprint(Post $post): string
    {
        $data = [
            'workspace' => $post->workspace_id,
            'id' => $post->id,
            'destination' => $post->social_account_id,
            'network_account' => $post->socialAccount?->platform_user_id,
            'platform' => $post->platform?->value,
            'type' => $post->content_type?->value,
            'content' => $post->content,
            'media' => $post->media,
            'meta' => Arr::except($post->meta ?? [], PostPlatformMetaRules::SYSTEM_KEYS),
            'scheduled_at' => $post->scheduled_at?->toISOString(),
        ];

        return hash('sha256', json_encode(self::ordered($data), JSON_THROW_ON_ERROR));
    }

    private static function ordered(array $data): array
    {
        if (! array_is_list($data)) {
            ksort($data);
        }

        return array_map(fn (mixed $value) => is_array($value) ? self::ordered($value) : $value, $data);
    }

    private static function forPost(Post $post): Builder
    {
        return DB::table('studio_post_releases')->where('post_id', $post->id);
    }
}
