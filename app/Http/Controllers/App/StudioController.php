<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Models\Workspace;
use App\Studio\StudioArtifact;
use App\Studio\StudioNote;
use App\Studio\StudioProduction;
use App\Studio\StudioProject;
use App\Studio\StudioRelease;
use App\Studio\StudioRevision;
use App\Studio\StudioRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StudioController extends Controller
{
    public function index(Request $request): Response
    {
        $workspace = $this->workspace($request, 'view');
        $selected = $request->query('project');
        $projects = StudioProject::query()->forWorkspace($workspace->id)->latest('updated_at')->latest('id')->limit(40)->get();
        $active = $selected === null
            ? $projects->first()
            : StudioProject::query()->forWorkspace($workspace->id)->findOrFail(
                filter_var($selected, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: abort(404)
            );
        $canManage = Gate::allows('update', $workspace);
        $settings = StudioProduction::settings($workspace);

        return Inertia::render('studio/Index', [
            'studio' => [
                'settings' => $settings->only([
                    'enabled', 'run_at', 'timezone', 'brand_guide', 'creative_brief', 'provider', 'alternative_provider',
                    'max_model_calls', 'max_voice_characters', 'voice_id',
                ]) + ['can_manage' => $canManage],
                'projects' => $projects->map(fn (StudioProject $project) => $this->project($project))->values(),
                'activeProject' => $active === null ? null : $this->activeProject($active),
                'providers' => StudioProduction::providers(),
                'channels' => StudioProduction::channels($workspace),
                'can_create' => $canManage,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request, 'update');
        $input = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:5000'],
            'format' => ['required', Rule::in(['reel'])],
            'review_storyboard' => ['sometimes', 'required', 'boolean'],
        ]);
        $run = StudioProduction::start($workspace, $request->user(), $input);

        return response()->json(['project_id' => $run->project_id, 'run_id' => $run->id], 202);
    }

    public function settings(Request $request): JsonResponse
    {
        $workspace = $this->workspace($request, 'update');
        $providers = array_column(StudioProduction::providers(), 'id');
        $input = $request->validate([
            'enabled' => ['sometimes', 'required', 'boolean'],
            'run_at' => ['sometimes', 'required', 'date_format:H:i'],
            'timezone' => ['sometimes', 'required', 'timezone:all'],
            'brand_guide' => ['nullable', 'string', 'max:5000'],
            'creative_brief' => ['nullable', 'string', 'max:5000'],
            'provider' => ['nullable', Rule::in($providers)],
            'alternative_provider' => ['nullable', Rule::in($providers)],
            'max_model_calls' => ['sometimes', 'required', 'integer', 'min:0', 'max:8'],
            'max_voice_characters' => ['sometimes', 'required', 'integer', 'min:0', 'max:4000'],
            'voice_id' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_-]{8,80}$/D'],
        ]);

        DB::transaction(function () use ($workspace, $input): void {
            Workspace::query()->lockForUpdate()->findOrFail($workspace->id);
            $setting = StudioProduction::settings($workspace)->fill($input);

            if ($setting->enabled) {
                if (blank($setting->creative_brief)) {
                    throw ValidationException::withMessages(['creative_brief' => __('Add a brief before enabling the daily run.')]);
                }

                if (blank($setting->provider)) {
                    throw ValidationException::withMessages(['provider' => __('Select a model provider before enabling the daily run.')]);
                }

                if ($setting->max_model_calls < 3 || $setting->max_voice_characters < 36) {
                    throw ValidationException::withMessages(['max_model_calls' => __('Set a budget for model calls and voice before enabling the daily run.')]);
                }
            }

            $setting->save();
        });

        return response()->json(['saved' => true]);
    }

    public function note(Request $request, int $project): JsonResponse
    {
        $project = $this->findProject($request, $project, 'update');
        $input = $request->validate([
            'revision_id' => ['required', 'integer', 'min:1'],
            'time_seconds' => ['required', 'numeric', 'min:0', 'max:600'],
            'note' => ['required', 'string', 'max:1600'],
        ]);
        $note = StudioProduction::revise($project, $request->user(), $input);

        return response()->json(['note_id' => $note->id, 'run_id' => $note->run_id], 202);
    }

    public function retry(Request $request, int $project): JsonResponse
    {
        $project = $this->findProject($request, $project, 'update');
        $input = $request->validate(['run_id' => ['required', 'integer', 'min:1']]);
        $run = StudioProduction::retry($project, (int) $input['run_id']);

        return response()->json(['run_id' => $run->id], 202);
    }

    public function storyboard(Request $request, int $project, int $run): JsonResponse
    {
        $project = $this->findProject($request, $project, 'update');
        $input = $request->validate([
            'treatment' => ['required', 'integer', 'between:0,1'],
            'fingerprint' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D'],
        ]);
        $run = StudioProduction::approveStoryboard($project, $run, (int) $input['treatment'], $input['fingerprint']);

        return response()->json(['run_id' => $run->id], 202);
    }

    public function finalist(Request $request, int $project): JsonResponse
    {
        $project = $this->findProject($request, $project, 'update');
        $input = $request->validate(['revision_id' => ['required', 'integer', 'min:1']]);
        $revision = $project->revisions()->findOrFail($input['revision_id']);
        $project->update(['finalist_revision_id' => $revision->id]);

        return response()->json(['revision_id' => $revision->id]);
    }

    public function media(Request $request, int $project, int $artifact): BinaryFileResponse
    {
        $project = $this->findProject($request, $project, 'view');
        $artifact = StudioArtifact::query()->with('revision.run')
            ->whereHas('revision', fn ($query) => $query->where('project_id', $project->id))->findOrFail($artifact);
        $path = $artifact->privatePath();

        return response()->file($path, [
            'Content-Type' => $artifact->mime,
            'Content-Disposition' => $artifact->kind === 'source' ? 'attachment' : 'inline',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function workspace(Request $request, string $ability): Workspace
    {
        $workspace = $request->user()->currentWorkspace;
        abort_unless($workspace instanceof Workspace, 404);
        Gate::authorize($ability, $workspace);

        return $workspace;
    }

    private function findProject(Request $request, int $project, string $ability): StudioProject
    {
        return StudioProject::query()->forWorkspace($this->workspace($request, $ability)->id)->findOrFail($project);
    }

    private function project(StudioProject $project): array
    {
        return $project->only(['id', 'title', 'brief', 'format', 'status']) + [
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    private function activeProject(StudioProject $project): array
    {
        $revisions = $project->revisions()->with('artifacts')->latest('version')->limit(20)->get();
        if ($project->finalist_revision_id !== null && ! $revisions->contains('id', $project->finalist_revision_id)) {
            $finalist = $project->revisions()->with('artifacts')->find($project->finalist_revision_id);
            if ($finalist !== null) {
                $revisions->push($finalist);
            }
        }

        $releases = StudioRelease::forRevisions($revisions->modelKeys());
        $runs = $project->runs()->with('revision:id,run_id')->latest('id')->limit(20)->get();

        return $this->project($project) + [
            'finalist_revision_id' => $project->finalist_revision_id,
            'runs' => $runs->map(fn (StudioRun $run) => $this->run($run)),
            'storyboard' => $project->status === 'awaiting_storyboard'
                ? $runs->firstWhere('status', 'awaiting_storyboard')?->storyboard() : null,
            'revisions' => $revisions->map(fn (StudioRevision $revision) => $this->revision($project, $revision, $releases[$revision->id] ?? [])),
            'notes' => $project->notes()->latest('id')->limit(50)->get()->map(fn (StudioNote $note) => [
                'id' => $note->id,
                'revision_id' => $note->revision_id,
                'time_seconds' => $note->time_seconds,
                'note' => $note->note,
                'created_at' => $note->created_at?->toIso8601String(),
            ]),
        ];
    }

    private function run(StudioRun $run): array
    {
        $progress = $run->status === 'running' ? $run->readPrivateJson('progress.json') : null;

        return $run->only(['id', 'kind', 'status', 'error']) + [
            'stage' => $progress['stage'] ?? $run->stage,
            'message' => $progress['message'] ?? $run->message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'revision_id' => $run->revision?->id,
            'events' => $progress['events'] ?? $run->events ?? [],
        ];
    }

    private function revision(StudioProject $project, StudioRevision $revision, array $releases): array
    {
        $manifest = $revision->manifest;

        return $revision->only(['id', 'parent_id', 'version', 'duration_seconds']) + [
            'title' => $manifest['title'],
            'summary' => $manifest['summary'] ?? '',
            'caption' => $manifest['caption'] ?? null,
            'verification' => $manifest['verification'] ?? null,
            'usage' => $manifest['usage'] ?? null,
            'treatments' => $manifest['treatments'] ?? [],
            'review' => $manifest['review'] ?? null,
            'artifacts' => $revision->artifacts->map(fn (StudioArtifact $artifact) => $artifact->only(['id', 'kind', 'title', 'sha256']) + [
                'url' => route('app.studio.media', ['project' => $project->id, 'artifact' => $artifact->id], absolute: false),
            ]),
            'releases' => $releases,
            'created_at' => $revision->created_at?->toIso8601String(),
        ];
    }
}
