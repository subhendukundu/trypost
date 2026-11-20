<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Actions\Post\CreatePosts;
use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Enums\SocialAccount\Platform;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Studio\StudioProject;
use App\Studio\StudioRelease;
use App\Studio\StudioRevision;
use App\Support\PostPlatformMetaRules;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class StudioReleaseController extends Controller
{
    public function draft(Request $request, int $project): JsonResponse
    {
        $actor = $request->user();
        $workspace = $actor->currentWorkspace;
        $this->authorize('createPost', $workspace);
        $data = $request->validate([
            'revision_id' => ['required', 'integer'],
            'channel_id' => ['required', 'uuid'],
        ]);
        $source = StudioProject::forWorkspace($workspace->id)->findOrFail($project);
        $revision = $source->revisions()->findOrFail($data['revision_id']);

        if ($source->finalist_revision_id !== $revision->id) {
            throw ValidationException::withMessages(['revision_id' => 'Select this cut as the finalist before sending it to the composer.']);
        }

        $channel = $workspace->socialAccounts()->connected()->findOrFail($data['channel_id']);
        $type = match ($channel->platform) {
            Platform::Instagram, Platform::InstagramFacebook => ContentType::InstagramReel,
            Platform::Facebook => ContentType::FacebookReel,
            Platform::YouTube => ContentType::YouTubeShort,
            Platform::Threads => ContentType::ThreadsPost,
            default => throw ValidationException::withMessages(['channel_id' => 'Choose Instagram, Facebook, YouTube or Threads.']),
        };

        $key = "studio:draft:{$revision->id}:{$channel->id}";
        $postId = Cache::lock($key, 180)->block(10, fn () => StudioRelease::draftFor($revision->id, $channel->id)
            ?? $this->createDraft($actor, $source, $revision, $channel, $type)->id);

        return response()->json(['redirect_url' => route('app.studio.release', $postId, absolute: false)], 201);
    }

    private function createDraft(User $actor, StudioProject $source, StudioRevision $revision, SocialAccount $channel, ContentType $type): Post
    {
        $workspace = $source->workspace;
        $video = $revision->artifacts()->where('kind', 'video')->firstOrFail();
        $cover = $revision->artifacts()->where('kind', 'cover')->first();
        $videoPath = $video->verifiedPath();
        $coverPath = $cover?->verifiedPath();
        $videoUpload = $workspace->addMediaFromPath($videoPath, 'studio-reel.mp4', Media::COLLECTION_UPLOADS, meta: [StudioRelease::MEDIA_ORIGIN => $revision->id], mimeType: $video->mime);
        $videoUpload->issueUploadToken();
        $media = [...MediaItem::fromMedia($videoUpload)->toArray(), 'upload_token' => $videoUpload->upload_token];

        if ($coverPath !== null && in_array($type, [ContentType::InstagramReel, ContentType::YouTubeShort], true)) {
            $extension = $cover->mime === 'image/png' ? 'png' : 'jpg';
            $coverUpload = $workspace->addMediaFromPath($coverPath, 'studio-cover.'.$extension, Media::COLLECTION_UPLOADS, mimeType: $cover->mime);
            $coverUpload->issueUploadToken();
            $media['meta'] = [
                ...($media['meta'] ?? []),
                'cover_source' => 'image',
                'cover' => [...MediaItem::fromMedia($coverUpload)->toArray(), 'upload_token' => $coverUpload->upload_token],
            ];
        }

        $caption = data_get($revision->manifest, 'caption');
        $meta = match ($type) {
            ContentType::YouTubeShort => [
                'title' => mb_substr(str_replace(['<', '>'], '', $source->title), 0, 100),
                'privacy_status' => 'public',
                'is_ai_generated' => true,
            ],
            ContentType::InstagramReel => ['is_ai_generated' => true],
            default => [],
        };

        $posts = CreatePosts::execute($workspace, $actor, [
            'status' => Status::Draft->value,
            'content' => $this->caption($caption, $source->title, $type),
            'media' => [$media],
            'destinations' => [[
                'social_account_id' => $channel->id,
                'content_type' => $type->value,
                'meta' => $meta,
            ]],
        ]);

        return $posts->sole();
    }

    public function show(Request $request, Post $post): Response
    {
        $actor = $request->user();
        $this->authorize('view', $post);
        abort_unless(StudioRelease::tracks($post), 404);

        $channel = $post->socialAccount;
        $media = $post->mediaItems->first();

        return Inertia::render('studio/Release', [
            'release' => [
                'id' => $post->id,
                'fingerprint' => StudioRelease::fingerprint($post),
                'channel' => $channel?->display_label,
                'handle' => $channel?->handle_label,
                'type' => $post->content_type?->value,
                'platform' => $post->platform?->value,
                'content' => $post->content,
                'media_url' => $media?->url,
                'cover_url' => $media?->cover()?->url,
                'options' => (object) Arr::except($post->meta ?? [], PostPlatformMetaRules::SYSTEM_KEYS),
                'status' => $post->status->value,
                'scheduled_at' => $post->scheduled_at?->toIso8601String(),
                'owner' => $actor->ownsAccountOf($actor->currentWorkspace),
                'edit_url' => route('app.posts.edit', $post),
            ],
        ]);
    }

    public function approve(Request $request, Post $post): RedirectResponse
    {
        $this->authorize('view', $post);
        abort_unless($request->user()->ownsAccountOf($post->workspace), 403);
        $data = $request->validate([
            'fingerprint' => ['required', 'string', 'size:64'],
            'scheduled_at' => ['nullable', 'date', 'after:now', 'before:2038-01-19'],
        ]);
        $scheduledAt = isset($data['scheduled_at']) ? CarbonImmutable::parse($data['scheduled_at'])->utc() : null;
        StudioRelease::approve($post, $request->user(), $data['fingerprint'], $scheduledAt);

        return redirect()->route('app.studio.release', $post);
    }

    private function caption(mixed $caption, string $title, ContentType $type): string
    {
        $text = is_string($caption) && filled($caption) ? $caption : $title;

        return $type === ContentType::ThreadsPost ? Str::limit($text, 500, '') : $text;
    }
}
