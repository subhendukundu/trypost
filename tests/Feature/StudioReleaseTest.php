<?php

declare(strict_types=1);

use App\Actions\Post\DuplicatePost;
use App\Dto\MediaItem;
use App\Enums\Post\Status;
use App\Enums\PostPlatform\ContentType;
use App\Jobs\PublishPost;
use App\Jobs\PublishToSocialPlatform;
use App\Mcp\Servers\TryPostServer;
use App\Mcp\Tools\Post\PublishPostTool;
use App\Models\Media;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Studio\StudioProject;
use App\Studio\StudioRelease;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake();
    Queue::fake();

    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'user_id' => $this->owner->id,
        'account_id' => $this->owner->account_id,
    ]);
    $this->workspace->members()->attach($this->owner->id, membershipPivot('admin'));
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);

    $this->channel = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->post = Post::factory()->forAccount($this->channel, ContentType::InstagramReel)->create([
        'user_id' => $this->owner->id,
        'status' => Status::Draft,
        'scheduled_at' => null,
        'content' => 'Private Studio draft.',
        'meta' => ['is_ai_generated' => true],
    ]);
    $video = Media::factory()->video()->ownedByPost($this->post)->stored()->create(['meta' => ['duration' => 12]]);
    $this->post->update(['media' => [MediaItem::fromMedia($video)->toArray()]]);
    $project = StudioProject::query()->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
        'title' => 'Private studio project',
        'brief' => 'Play out a misunderstanding.',
        'format' => 'reel',
        'status' => 'ready_for_review',
    ]);
    $run = $project->runs()->create([
        'execution_key' => (string) Str::uuid(),
        'kind' => 'produce',
        'status' => 'ready_for_review',
        'stage' => 'ready_for_review',
        'request' => [],
    ]);
    $this->cut = $project->revisions()->create([
        'run_id' => $run->id,
        'version' => 1,
        'duration_seconds' => 12,
        'manifest' => ['title' => 'Private studio project', 'caption' => 'This is the branch caption.'],
    ]);
    StudioRelease::record($this->post, $this->cut->id);
});

test('Studio posts reject the native composer and MCP publish paths', function () {
    $this->actingAs($this->owner)
        ->putJson(route('app.posts.update', $this->post), ['status' => 'publishing'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['status' => StudioRelease::APPROVAL_REQUIRED]);

    TryPostServer::actingAs($this->owner)->tool(PublishPostTool::class, [
        'post_id' => $this->post->id,
    ])->assertHasErrors([StudioRelease::APPROVAL_REQUIRED]);

    expect($this->post->fresh()->status)->toBe(Status::Draft);
    Queue::assertNotPushed(PublishPost::class);
});

test('an account admin and a stale owner review cannot authorize release', function () {
    $fingerprint = StudioRelease::fingerprint($this->post);
    $admin = workspaceMember($this->workspace, 'admin');

    $this->actingAs($admin)->postJson(route('app.studio.approve', $this->post), [
        'fingerprint' => $fingerprint,
    ])->assertForbidden();

    $this->post->update(['meta' => ['is_ai_generated' => false]]);
    $this->actingAs($this->owner)->postJson(route('app.studio.approve', $this->post), [
        'fingerprint' => $fingerprint,
    ])->assertUnprocessable()->assertJsonValidationErrors('status');

    Queue::assertNotPushed(PublishPost::class);
});

test('owner approval binds the scheduled instant and stored shipping payload', function () {
    $this->freezeSecond();
    $due = now()->addHour();
    $this->actingAs($this->owner)
        ->post(route('app.studio.approve', $this->post), [
            'fingerprint' => StudioRelease::fingerprint($this->post),
            'scheduled_at' => $due->toIso8601String(),
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('app.studio.release', $this->post));

    $post = $this->post->fresh();
    expect($post->status)->toBe(Status::Scheduled)
        ->and($post->scheduled_at->equalTo($due))->toBeTrue()
        ->and(StudioRelease::permits($post))->toBeFalse();

    $this->travelTo($due);
    expect(StudioRelease::permits($post))->toBeTrue();

    $post->update(['content' => 'A different caption.']);
    expect(StudioRelease::permits($post->fresh()))->toBeFalse();
});

test('the delivery worker stops an unapproved Studio post before a network call', function () {
    $this->post->update(['status' => Status::Publishing, 'scheduled_at' => now()]);
    (new PublishToSocialPlatform($this->post))->handle();

    expect($this->post->fresh()->status)->toBe(Status::Failed)
        ->and($this->post->fresh()->error_message)->toBe(StudioRelease::APPROVAL_REQUIRED);
});

test('copies retain their Studio origin and need their own approval', function () {
    $copy = DuplicatePost::execute($this->post, $this->owner);

    expect(StudioRelease::tracks($copy))->toBeTrue()
        ->and(StudioRelease::permits($copy))->toBeFalse();
});

test('the selected cut becomes one native draft with its caption and cover', function () {
    $project = $this->cut->project;
    $project->update(['finalist_revision_id' => $this->cut->id]);
    $directory = $this->cut->run->directory();
    Storage::fake('local');
    $disk = Storage::disk('local');
    $disk->put($directory.'/film.mp4', 'fixture review bytes');
    $cover = UploadedFile::fake()->image('cover.png', 20, 20);
    $disk->put($directory.'/cover.png', file_get_contents($cover->getPathname()));

    foreach (['video' => ['film.mp4', 'video/mp4'], 'cover' => ['cover.png', 'image/png']] as $kind => [$file, $mime]) {
        $path = $directory.'/'.$file;
        $this->cut->artifacts()->create([
            'kind' => $kind,
            'title' => $file,
            'path' => $path,
            'mime' => $mime,
            'bytes' => $disk->size($path),
            'sha256' => hash_file('sha256', $disk->path($path)),
        ]);
    }

    $channel = SocialAccount::factory()->instagram()->create(['workspace_id' => $this->workspace->id]);
    $this->actingAs($this->owner);
    $data = ['channel_id' => $channel->id, 'revision_id' => $this->cut->id];
    $url = $this->postJson(route('app.studio.draft', ['project' => $project->id]), $data)
        ->assertCreated()->json('redirect_url');
    $this->postJson(route('app.studio.draft', ['project' => $project->id]), $data)
        ->assertCreated()->assertJsonPath('redirect_url', $url);

    $draft = $channel->posts()->sole();
    expect($draft->status)->toBe(Status::Draft)
        ->and($draft->content)->toBe('This is the branch caption.')
        ->and(data_get($draft->media, '0.meta.cover.id'))->toBeString()
        ->and($draft->meta['is_ai_generated'])->toBeTrue()
        ->and(StudioRelease::tracks($draft))->toBeTrue();

    Queue::assertNotPushed(PublishPost::class);
});
