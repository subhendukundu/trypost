<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Studio;

use App\Mcp\Concerns\AuthorizesMcpTool;
use App\Models\Workspace;
use App\Studio\StudioProduction;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;
use Laravel\Mcp\Server\Tools\Annotations\IsOpenWorld;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly(false)]
#[IsDestructive(false)]
#[IsOpenWorld(true)]
#[Description('Queue a private Reel production within the workspace budgets. May call configured model and speech providers. Requires permission to manage this workspace. Does not schedule or publish a social post.')]
class StartStudioRunTool extends Tool
{
    use AuthorizesMcpTool;

    public function handle(Request $request): Response|ResponseFactory
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:160'],
            'brief' => ['required', 'string', 'max:6000'],
        ]);
        $workspace = $this->authorizeCurrentWorkspace($request, 'update');

        if (! $workspace instanceof Workspace) {
            return $workspace;
        }

        try {
            $run = StudioProduction::start($workspace, $request->user(), [...$data, 'format' => 'reel']);
        } catch (ValidationException $exception) {
            return Response::error($exception->getMessage());
        }

        return Response::structured([
            'project_id' => $run->project_id,
            'run_id' => $run->id,
            'review_url' => route('app.studio.index', ['project' => $run->project_id]),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('An optional title for the private project.'),
            'brief' => $schema->string()->required()->description('The creative brief for the Reel, up to 6000 characters.'),
        ];
    }
}
