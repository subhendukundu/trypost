<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Studio;

use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Studio\StudioProject;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(true)]
#[IsDestructive(false)]
#[IsOpenWorld(false)]
#[Description('Read a private Studio project, run status and saved cuts in the connected workspace. Media playback and owner approval are in the Studio web app.')]
class GetStudioProjectTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate(['project_id' => ['required', 'integer', 'min:1']]);
        $workspace = $this->authorizeCurrentWorkspace($request, 'view');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        $project = StudioProject::forWorkspace($workspace->id)->find($data['project_id']);

        if ($project === null) {
            return Response::error('Studio project not found.');
        }

        return Response::structured([
            ...$project->only(['id', 'title', 'brief', 'status', 'finalist_revision_id']),
            'review_url' => route('app.studio.index', ['project' => $project->id]),
            'runs' => $project->runs()->latest('id')->limit(20)->get(['id', 'kind', 'stage', 'status', 'error'])->toArray(),
            'revisions' => $project->revisions()->latest('id')->limit(20)->get(['id', 'parent_id', 'version', 'duration_seconds'])->toArray(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return ['project_id' => $schema->integer()->required()->description('The Studio project ID.')];
    }
}
