<?php

declare(strict_types=1);

$environment = [];

foreach (['OPENAI', 'ANTHROPIC', 'GEMINI', 'XAI', 'ELEVENLABS'] as $provider) {
    $environment['STUDIO_'.$provider.'_API_KEY'] = env('STUDIO_'.$provider.'_API_KEY', env($provider.'_API_KEY'));
    $environment['STUDIO_'.$provider.'_MODEL'] = env('STUDIO_'.$provider.'_MODEL');
}

foreach (['CODEX', 'CLAUDE'] as $provider) {
    foreach (['BINARY', 'PROFILE', 'MODEL'] as $option) {
        $environment['STUDIO_'.$provider.'_'.$option] = env('STUDIO_'.$provider.'_'.$option);
    }
}

foreach (['FFMPEG', 'FFPROBE'] as $binary) {
    $environment['STUDIO_'.$binary.'_BINARY'] = env('STUDIO_'.$binary.'_BINARY');
}

$voiceId = env('STUDIO_ELEVENLABS_VOICE_ID');
$environment['STUDIO_ELEVENLABS_VOICE_ID'] = $voiceId;

return [
    'python' => env('STUDIO_PYTHON', 'python3'),
    'runner' => base_path('scripts/studio/run.py'),
    'environment' => array_filter($environment, fn (mixed $value): bool => is_string($value) && $value !== ''),
    'voice_configured' => filled($environment['STUDIO_ELEVENLABS_API_KEY']),
    'voice_id' => $voiceId,
    'providers' => [
        'codex' => filled($environment['STUDIO_CODEX_BINARY']) && filled($environment['STUDIO_CODEX_PROFILE']),
        'claude' => filled($environment['STUDIO_CLAUDE_BINARY']) && filled($environment['STUDIO_CLAUDE_PROFILE']),
        'openai' => filled($environment['STUDIO_OPENAI_API_KEY']),
        'anthropic' => filled($environment['STUDIO_ANTHROPIC_API_KEY']),
        'gemini' => filled($environment['STUDIO_GEMINI_API_KEY']),
        'grok' => filled($environment['STUDIO_XAI_API_KEY']),
    ],
];
