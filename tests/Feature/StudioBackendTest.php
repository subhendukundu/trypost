<?php

declare(strict_types=1);

use App\Enums\Analytics\PublicationContentType;
use App\Models\AnalyticsPublication;
use App\Models\AnalyticsPublicationDailySnapshot;
use App\Models\User;
use App\Models\Workspace;
use App\Studio\StudioProduction;
use App\Studio\StudioRun;
use App\Studio\StudioRunJob;
use App\Studio\StudioSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config([
        'trypost.self_hosted' => true,
        'studio.providers' => [],
    ]);
    Queue::fake();
    Storage::fake('local');
    $this->owner = User::factory()->create();
    $this->workspace = Workspace::factory()->create([
        'account_id' => $this->owner->account_id,
        'user_id' => $this->owner->id,
    ]);
    $this->owner->update(['current_workspace_id' => $this->workspace->id]);
});

test('owner can queue one private production that uses only recent workspace reel evidence', function () {
    $reel = AnalyticsPublication::factory()->create([
        'workspace_id' => $this->workspace->id,
        'content_type' => PublicationContentType::Reel,
        'excerpt' => 'Our reference Reel',
    ]);
    AnalyticsPublication::factory()->create(['content_type' => PublicationContentType::Reel]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $reel->id,
        'date' => today()->subDay(),
        'views_count' => 111,
    ]);
    AnalyticsPublicationDailySnapshot::factory()->create([
        'publication_id' => $reel->id,
        'views_count' => 330,
        'reach_count' => 200,
        'average_watch_time_milliseconds' => 8200,
    ]);

    $brief = ['brief' => 'Compare two short Bengali family satire treatments.', 'format' => 'reel'];
    $response = $this->actingAs($this->owner)->postJson(route('app.studio.store'), $brief)->assertAccepted();
    $run = StudioRun::query()->findOrFail($response->json('run_id'));
    expect($run->project->created_by)->toBe($this->owner->id)
        ->and($run->status)->toBe('queued')
        ->and($run->request['review_storyboard'] ?? false)->toBeFalse()
        ->and($run->request['evidence'])->toHaveCount(1)
        ->and($run->request['evidence'][0])->toMatchArray([
            'title' => 'Our reference Reel',
            'views' => 330,
            'reach' => 200,
            'average_watch_time_ms' => 8200,
        ]);

    $this->postJson(route('app.studio.store'), $brief)->assertUnprocessable()->assertJsonValidationErrors('run_id');
    Queue::assertPushed(StudioRunJob::class, 1);
    $this->assertDatabaseCount('posts', 0);
});

test('a fresh project can use prior review notes before social analytics is connected', function () {
    $previous = StudioProduction::start($this->workspace, $this->owner, ['brief' => 'First private Reel.', 'format' => 'reel']);
    saveStudioManifest($previous);
    (new StudioRunJob($previous->id))->handle();
    $previous->project->notes()->create([
        'revision_id' => $previous->revision()->sole()->id,
        'run_id' => $previous->id,
        'time_seconds' => 3,
        'note' => 'Keep the prop in the wide shot.',
    ]);

    $response = $this->actingAs($this->owner)->postJson(route('app.studio.store'), [
        'brief' => 'The next private Reel.', 'format' => 'reel',
    ])->assertAccepted();
    $run = StudioRun::query()->findOrFail($response->json('run_id'));
    expect($run->request['evidence'])->toHaveCount(1)
        ->and($run->request['evidence'][0])->toMatchArray([
            'type' => 'owner_review_note',
            'note' => 'Keep the prop in the wide shot.',
        ]);
});

test('workspace members can review their studio but only administrators can start or configure production', function () {
    $member = workspaceMember($this->workspace, 'approval');
    $this->actingAs($member)->get(route('app.studio.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('studio/Index')
        ->where('studio.can_create', false)
        ->where('studio.settings.can_manage', false)
    );
    $this->postJson(route('app.studio.store'), ['brief' => 'A Reel.', 'format' => 'reel'])->assertForbidden();
    $this->patchJson(route('app.studio.settings'), ['enabled' => true])->assertForbidden();
    Queue::assertNotPushed(StudioRunJob::class);
});

test('a missing provider blocks the run without binding or dispatching production a second time', function () {
    $run = StudioProduction::start($this->workspace, $this->owner, ['brief' => 'A private Reel.', 'format' => 'reel']);
    $job = new StudioRunJob($run->id);
    $job->handle();
    $job->handle();

    expect($run->fresh()->status)->toBe('blocked')
        ->and($run->fresh()->started_at)->toBeNull()
        ->and($run->project->fresh()->status)->toBe('blocked');
    Storage::disk('local')->assertMissing($run->directory().'/request.json');

    $this->actingAs($this->owner)->patchJson(route('app.studio.settings'), [
        'provider' => 'openai', 'max_model_calls' => 3, 'max_voice_characters' => 500,
    ])->assertOk();
    $this->postJson(route('app.studio.retry', $run->project_id), ['run_id' => $run->id])->assertAccepted();
    expect($run->fresh()->execution_key)->toBe($run->execution_key)
        ->and($run->fresh()->request['settings']['provider'])->toBe('openai');
    Queue::assertPushed(StudioRunJob::class, 2);
});

test('a private storyboard waits without narration credentials and resumes the same request after a choice', function () {
    $disk = Storage::disk('local');
    $runner = $disk->path('studio-runner.php');
    $disk->put('studio-runner.php', <<<'PHP'
        <?php
        [, $request, $output] = $argv;
        if (! is_file($output.'/storyboard-approval.json')) {
            copy($output.'/fixture-storyboard.json', $output.'/storyboard.json');
            exit;
        }
        $approval = json_decode(file_get_contents($output.'/storyboard-approval.json'), true);
        if ($approval === ['treatment' => 1, 'fingerprint' => hash_file('sha256', $output.'/storyboard.json')]) {
            copy($output.'/fixture-manifest.json', $output.'/manifest.json');
        }
        PHP);
    config([
        'studio.providers.openai' => true,
        'studio.voice_configured' => false,
        'studio.python' => PHP_BINARY,
        'studio.runner' => $runner,
    ]);

    $this->actingAs($this->owner)->patchJson(route('app.studio.settings'), [
        'provider' => 'openai', 'max_model_calls' => 3, 'max_voice_characters' => 90,
        'voice_id' => 'fixture_voice',
    ])->assertOk();
    $response = $this->postJson(route('app.studio.store'), [
        'title' => 'চায়ে চিনি দিইনি',
        'brief' => 'Compare two private treatments.',
        'format' => 'reel',
        'review_storyboard' => true,
    ])->assertAccepted();
    $run = StudioRun::query()->findOrFail($response->json('run_id'));
    $directory = $run->directory();
    $storyboard = studioStoryboardFixture();
    $fingerprint = hash('sha256', $storyboard);
    $disk->put($directory.'/fixture-storyboard.json', $storyboard);
    saveStudioManifest($run);
    $disk->move($directory.'/manifest.json', $directory.'/fixture-manifest.json');

    $job = new StudioRunJob($run->id);
    $job->handle();
    $request = $disk->get($directory.'/request.json');
    expect($run->fresh()->status)->toBe('awaiting_storyboard')
        ->and($run->project->fresh()->status)->toBe('awaiting_storyboard')
        ->and($run->fresh()->finished_at)->toBeNull()
        ->and($run->fresh()->error)->toBeNull()
        ->and($run->revision()->exists())->toBeFalse()
        ->and(json_decode($request, true)['review_storyboard'])->toBeTrue();
    $disk->assertMissing($directory.'/storyboard-approval.json');

    $this->get(route('app.studio.index', ['project' => $run->project_id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('studio.activeProject.storyboard.run_id', $run->id)
        ->where('studio.activeProject.storyboard.fingerprint', $fingerprint)
        ->where('studio.activeProject.storyboard.review.selected', 0)
        ->where('studio.activeProject.storyboard.treatments.1.title', 'A different angle')
        ->has('studio.activeProject.storyboard.treatments.1.shots', 3)
        ->missing('studio.activeProject.storyboard.treatments.0.untrusted')
        );
    $approval = ['treatment' => 1, 'fingerprint' => $fingerprint];
    $approvalUrl = route('app.studio.storyboard', [$run->project_id, $run->id]);
    $this->postJson($approvalUrl, ['treatment' => 0] + $approval)
        ->assertUnprocessable()->assertJsonValidationErrors('treatment');
    $this->postJson($approvalUrl, $approval)->assertAccepted()->assertJsonPath('run_id', $run->id);
    expect($run->fresh()->storyboard_decision)->toBe($approval)
        ->and($run->fresh()->request)->toBe($run->request)
        ->and($run->fresh()->execution_key)->toBe($run->execution_key);

    $job->handle();
    expect($run->fresh()->status)->toBe('blocked');
    $disk->assertMissing($directory.'/storyboard-approval.json');
    config(['studio.voice_configured' => true, 'studio.providers' => []]);
    $this->postJson(route('app.studio.retry', $run->project_id), ['run_id' => $run->id])->assertAccepted();
    $job->handle();
    expect($run->fresh()->status)->toBe('ready_for_review')
        ->and($run->revision()->count())->toBe(1)
        ->and($disk->get($directory.'/request.json'))->toBe($request)
        ->and($disk->get($directory.'/storyboard.json'))->toBe($storyboard);
    $this->postJson($approvalUrl, $approval)->assertUnprocessable()->assertJsonValidationErrors('fingerprint');
    Queue::assertPushed(StudioRunJob::class, 3);
    $this->assertDatabaseCount('posts', 0);
});

test('storyboard approval rejects stale choices and remains scoped to a workspace project and manager', function () {
    $brief = ['brief' => 'A private Reel.', 'format' => 'reel', 'review_storyboard' => true];
    $run = StudioProduction::start($this->workspace, $this->owner, $brief);
    $storyboard = studioStoryboardFixture();
    $storyboardPath = $run->directory().'/storyboard.json';
    Storage::disk('local')->put($storyboardPath, $storyboard);
    (new StudioRunJob($run->id))->handle();
    $otherRun = StudioProduction::start($this->workspace, $this->owner, $brief);
    StudioRunJob::finish($otherRun, 'failed', 'Fixture complete.');

    $approval = ['treatment' => 1, 'fingerprint' => hash('sha256', $storyboard)];
    $approvalUrl = route('app.studio.storyboard', [$run->project_id, $run->id]);
    $this->actingAs(workspaceMember($this->workspace, 'approval'))->postJson($approvalUrl, $approval)->assertForbidden();
    $this->actingAs($this->owner)
        ->postJson(route('app.studio.storyboard', [$otherRun->project_id, $run->id]), $approval)->assertNotFound();
    $this->postJson($approvalUrl, ['treatment' => 2] + $approval)
        ->assertUnprocessable()->assertJsonValidationErrors('treatment');
    Storage::disk('local')->put($storyboardPath, $storyboard."\n");
    $this->postJson($approvalUrl, $approval)->assertUnprocessable()->assertJsonValidationErrors('fingerprint');

    $otherWorkspace = Workspace::factory()->create(['user_id' => $this->owner->id]);
    $this->owner->update(['current_workspace_id' => $otherWorkspace->id]);
    $this->actingAs($this->owner->fresh())->postJson($approvalUrl, $approval)->assertNotFound();
    expect($run->fresh()->status)->toBe('awaiting_storyboard')
        ->and($run->fresh()->storyboard_decision)->toBeNull();
    Queue::assertPushed(StudioRunJob::class, 2);
});

test('a rendered cut cannot bypass the requested storyboard approval', function () {
    $run = StudioProduction::start($this->workspace, $this->owner, [
        'brief' => 'A private Reel.', 'format' => 'reel', 'review_storyboard' => true,
    ]);
    saveStudioManifest($run);
    (new StudioRunJob($run->id))->handle();

    expect($run->fresh()->status)->toBe('failed')
        ->and($run->revision()->exists())->toBeFalse();
});

test('daily scheduler honors local time and queues once per workspace even after the run ends', function () {
    config(['studio.providers.openai' => true]);
    $this->actingAs($this->owner)->patchJson(route('app.studio.settings'), [
        'enabled' => true,
        'run_at' => '09:00',
        'timezone' => 'Asia/Kolkata',
        'creative_brief' => 'Pick a sharp family satire from the brief and recent evidence.',
        'provider' => 'openai',
        'max_model_calls' => 3,
        'max_voice_characters' => 500,
        'voice_id' => 'fixture_voice',
    ])->assertOk();

    $this->travelTo(CarbonImmutable::parse('2026-11-20 03:29:00 UTC'));
    $this->artisan('studio:run-schedule')->assertSuccessful();
    Queue::assertNotPushed(StudioRunJob::class);

    $this->travelTo(CarbonImmutable::parse('2026-11-20 03:30:00 UTC'));
    $this->artisan('studio:run-schedule')->assertSuccessful();
    StudioRunJob::finish(StudioRun::query()->sole(), 'blocked', 'Fixture complete.');
    $this->artisan('studio:run-schedule')->assertSuccessful();

    expect(StudioSetting::query()->sole()->last_scheduled_date)->toBe('2026-11-20');
    $this->assertDatabaseCount('studio_projects', 1);
    Queue::assertPushed(StudioRunJob::class, 1);
});

test('a verified cut can be viewed privately, chosen and revised from a timestamp', function () {
    $run = StudioProduction::start($this->workspace, $this->owner, ['brief' => 'A private Reel.', 'format' => 'reel']);
    saveStudioManifest($run);
    (new StudioRunJob($run->id))->handle();
    $revision = $run->revision()->sole();
    $video = $revision->artifacts()->where('kind', 'video')->sole();

    $this->actingAs($this->owner)->get(route('app.studio.index', ['project' => $run->project_id]))
        ->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('studio.activeProject.revisions.0.id', $revision->id)
        ->where('studio.activeProject.runs.0.status', 'ready_for_review')
        ->missing('studio.activeProject.runs.0.request')
        );
    $this->get(route('app.studio.media', [$run->project_id, $video->id]))
        ->assertOk()->assertHeader('Content-Type', 'video/mp4');
    $this->patchJson(route('app.studio.finalist', $run->project_id), ['revision_id' => $revision->id])->assertOk();
    expect($run->project->fresh()->finalist_revision_id)->toBe($revision->id);

    $noteUrl = route('app.studio.notes', $run->project_id);
    $this->postJson($noteUrl, ['revision_id' => $revision->id, 'time_seconds' => 33, 'note' => 'Hold the look.'])
        ->assertUnprocessable()->assertJsonValidationErrors('time_seconds');
    $response = $this->postJson($noteUrl, ['revision_id' => $revision->id, 'time_seconds' => 5.25, 'note' => 'Hold the look.'])
        ->assertAccepted();
    $revisionRun = StudioRun::query()->findOrFail($response->json('run_id'));
    expect($revisionRun->request['revision'])->toMatchArray([
        'parent_id' => $revision->id,
        'time_seconds' => 5.25,
        'note' => 'Hold the look.',
    ])->and($run->project->revisions()->count())->toBe(1);
    $this->assertDatabaseCount('posts', 0);

    $second = Workspace::factory()->create(['user_id' => $this->owner->id]);
    $this->owner->update(['current_workspace_id' => $second->id]);
    $this->actingAs($this->owner->fresh())->get(route('app.studio.media', [$run->project_id, $video->id]))->assertNotFound();
    $this->get(route('app.studio.index', ['project' => $run->project_id]))->assertNotFound();
    $this->postJson($noteUrl, ['revision_id' => $revision->id, 'time_seconds' => 5.25, 'note' => 'Hold the look.'])->assertNotFound();
});

test('a checksum change cannot become a review revision', function () {
    $run = StudioProduction::start($this->workspace, $this->owner, ['brief' => 'A private Reel.', 'format' => 'reel']);
    saveStudioManifest($run);
    Storage::disk('local')->put($run->directory().'/film.mp4', 'different');
    $job = new StudioRunJob($run->id);
    expect(fn () => $job->handle())->toThrow(RuntimeException::class);
    $job->failed(null);
    expect($run->fresh()->status)->toBe('failed')->and($run->revision()->exists())->toBeFalse();
});

function saveStudioManifest(StudioRun $run): void
{
    $artifacts = [];
    foreach ([
        'video' => ['film.mp4', 'video/mp4'],
        'cover' => ['cover.jpg', 'image/jpeg'],
        'captions' => ['captions.srt', 'application/x-subrip'],
    ] as $kind => [$file, $mime]) {
        $content = 'fixture:'.$kind;
        Storage::disk('local')->put($run->directory().'/'.$file, $content);
        $artifacts[] = [
            'kind' => $kind,
            'title' => ucfirst($kind),
            'file' => $file,
            'mime' => $mime,
            'bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
        ];
    }

    Storage::disk('local')->put($run->directory().'/manifest.json', json_encode([
        'version' => 1,
        'status' => 'ready_for_review',
        'title' => 'A fixture cut',
        'summary' => 'A verified cut from the production runner.',
        'verification' => ['full_decode_passed' => true],
        'duration_seconds' => 32.75,
        'artifacts' => $artifacts,
    ], JSON_THROW_ON_ERROR));
}

function studioStoryboardFixture(): string
{
    $shot = [
        'setting' => 'kitchen',
        'camera' => 'wide',
        'character' => 'mother',
        'secondary' => 'none',
        'expression' => 'neutral',
        'action' => 'speaks',
        'prop' => 'tea',
        'speech' => 'চায়ে চিনি দিইনি।',
    ];
    $first = [
        'title' => 'চায়ে চিনি দিইনি',
        'angle' => 'The family debate',
        'hook' => 'Who changed the tea?',
        'payoff' => 'Everyone adds sugar.',
        'caption' => 'A familiar argument.',
        'script' => 'চায়ে চিনি দিইনি।',
        'provider' => 'openai',
        'model' => 'fixture',
        'assessment' => 'A visible payoff',
        'selected' => true,
        'shots' => array_fill(0, 3, $shot),
        'untrusted' => 'Runner-only metadata',
    ];

    return json_encode([
        'version' => 1,
        'status' => 'awaiting_storyboard',
        'treatments' => [
            ['shots' => array_fill(0, 3, ['speech' => 'চায়ে চিনি দিইনি। সবাই কেন ভেবেছে যে আমি চিনি দিয়েছি?'] + $shot)] + $first,
            ['title' => 'A different angle', 'selected' => false] + $first,
        ],
        'review' => [
            'selected' => 0,
            'rationale' => 'The ending has a visual punch.',
            'assessment_a' => 'A visible payoff',
            'assessment_b' => 'Clear opening',
        ],
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
