"""Fixed provider endpoints and restricted native CLI adapters for Studio."""

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
from tempfile import TemporaryDirectory
from urllib.error import HTTPError, URLError
from urllib.parse import quote
from urllib.request import HTTPRedirectHandler, Request, build_opener

from errors import StudioError


class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, request, file, code, message, headers, target):
        return None


def secret(name):
    return os.environ.get(f'STUDIO_{name}') or os.environ.get(name)


def configured_model(name, default):
    model = os.environ.get(f'STUDIO_{name}_MODEL') or default
    if not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._:-]{0,120}', model):
        raise StudioError('invalid_provider_config', f'The configured {name} model name is invalid.')
    return model


def post_json(url, data, headers, label, timeout=100, maximum=2_000_000):
    request = Request(url, data=json.dumps(data, allow_nan=False).encode('utf-8'),
                      headers={'Content-Type': 'application/json', **headers})
    try:
        with build_opener(NoRedirect()).open(request, timeout=timeout) as response:
            body = response.read(maximum + 1)
            response_headers = response.headers
    except HTTPError as error:
        code = error.code
        error.close()
        if code in (401, 403):
            raise StudioError('provider_auth', f'{label} refused the configured account or model.') from None
        if code == 402:
            raise StudioError('needs_credits', f'{label} needs prepaid credit or an active billing plan.') from None
        if code == 429:
            raise StudioError('provider_quota', f'{label} reached its usage limit.') from None
        raise StudioError('provider_rejected', f'{label} refused the request (HTTP {code}).') from None
    except (URLError, TimeoutError, OSError):
        raise StudioError('provider_outcome_unknown', f'{label} did not return a verifiable result. The paid request will not be repeated automatically.') from None
    if len(body) > maximum:
        raise StudioError('invalid_provider_response', f'{label} returned a response that exceeded the Studio size limit.')
    try:
        decoded = json.loads(body)
    except ValueError:
        raise StudioError('invalid_provider_response', f'{label} returned an unreadable result.') from None
    if not isinstance(decoded, dict):
        raise StudioError('invalid_provider_response', f'{label} returned an invalid result.')
    return decoded, response_headers


NATIVE = {
    'codex': ('CODEX', 'gpt-6.1-sol'),
    'claude': ('CLAUDE', 'claude-sonnet-5-5'),
}
API_NAMES = {
    'openai': ('OPENAI', 'gpt-5-mini'),
    'claude': ('ANTHROPIC', 'claude-sonnet-5-5'),
    'anthropic': ('ANTHROPIC', 'claude-sonnet-5-5'),
    'gemini': ('GEMINI', 'gemini-3.1-pro-preview'),
    'grok': ('XAI', 'grok-4-fast-non-reasoning'),
    'xai': ('XAI', 'grok-4-fast-non-reasoning'),
}


def native_config(provider):
    prefix, default = NATIVE[provider]
    binary = os.environ.get(f'STUDIO_{prefix}_BINARY')
    path = os.environ.get(f'STUDIO_{prefix}_PROFILE')
    if binary and path and shutil.which(binary) and Path(path).is_dir():
        return shutil.which(binary), Path(path).resolve(), configured_model(prefix, default)
    if provider == 'codex' or binary or path:
        raise StudioError('provider_not_configured', f'Connect the native {prefix.title()} profile on the Studio server.')
    return None


def resolve_model(provider):
    if provider in NATIVE:
        native = native_config(provider)
        if native is not None:
            return provider, native[2], native
    if provider not in API_NAMES:
        raise StudioError('provider_not_configured', 'Choose a supported, configured Studio model provider.')
    prefix, default = API_NAMES[provider]
    key = secret(f'{prefix}_API_KEY')
    if not key:
        raise StudioError('provider_not_configured', f'Configure the {prefix.title()} API credential on the Studio server.')
    return prefix.lower(), configured_model(prefix, default), key


def resolve_voice(voice):
    key = secret('ELEVENLABS_API_KEY')
    if not key:
        raise StudioError('provider_not_configured', 'Connect the ElevenLabs API credential on the Studio server.')
    voice = voice or os.environ.get('STUDIO_ELEVENLABS_VOICE_ID', '')
    if not isinstance(voice, str) or not re.fullmatch(r'[A-Za-z0-9_-]{8,80}', voice):
        raise StudioError('voice_not_configured', 'Set an ElevenLabs voice ID for this workspace.')
    return voice, configured_model('ELEVENLABS', 'eleven_v4'), key


def native_generate(provider, native, prompt, schema):
    binary, profile, model = native
    names = ('HOME', 'PATH', 'LANG', 'LC_ALL', 'LC_CTYPE', 'TMPDIR', 'SSL_CERT_FILE', 'SSL_CERT_DIR')
    environment = {name: os.environ[name] for name in names if name in os.environ}
    with TemporaryDirectory(prefix='trypost-studio-model-') as directory:
        output = Path(directory) / 'response.json'
        if provider == 'codex':
            environment['CODEX_HOME'] = str(profile)
            settings = (
                'cli_auth_credentials_store="file"', 'forced_login_method="chatgpt"',
                'web_search="disabled"', 'features.shell_tool=false', 'features.shell_snapshot=false',
                'features.multi_agent=false', 'features.view_image=false', 'features.apps=false',
                'apps._default.enabled=false', 'plugins._default.enabled=false', 'mcp_servers={}',
            )
            options = [part for item in settings for part in ('-c', item)]
            schema_path = Path(directory) / 'schema.json'
            schema_path.write_text(json.dumps(schema), encoding='utf-8')
            command = [binary, *options, '-a', 'never', 'exec', '--sandbox', 'read-only',
                       '--skip-git-repo-check', '--ignore-user-config', '--ignore-rules',
                       '--ephemeral', '--color', 'never', '--output-schema', str(schema_path),
                       '--output-last-message', str(output), '--model', model, '-']
        else:
            environment.update(CLAUDE_CONFIG_DIR=str(profile), CLAUDE_CODE_MAX_OUTPUT_TOKENS='4500',
                               CLAUDE_CODE_DISABLE_NONESSENTIAL_TRAFFIC='1', DISABLE_AUTOUPDATER='1')
            command = [binary, '--restricted', '-p', '--output-format', 'json', '--tools', '',
                       '--disallowedTools', 'mcp__*', '--strict-mcp-config',
                       '--mcp-config', '{"mcpServers":{}}', '--setting-sources', '',
                       '--no-chrome', '--no-session-persistence', '--max-turns', '3',
                       '--model', model, '--json-schema', json.dumps(schema)]
        try:
            with TemporaryDirectory(prefix='trypost-studio-model-logs-') as diagnostics:
                log_path = Path(diagnostics) / 'stdout.json'
                with log_path.open('wb') as stdout:
                    measured = subprocess.run(command, cwd=directory, env=environment,
                                              input=prompt.encode('utf-8'), stdout=stdout,
                                              stderr=subprocess.DEVNULL, timeout=150, check=False)
                if measured.returncode:
                    raise StudioError('provider_rejected', f'{provider.title()} could not complete the structured request.')
                path = output if provider == 'codex' else log_path
                if path.stat().st_size > 2_000_000:
                    raise StudioError('invalid_provider_response', f'{provider.title()} returned an oversized result.')
                value = json.loads(path.read_text(encoding='utf-8'))
            if provider == 'claude':
                if value.get('is_error'):
                    raise StudioError('provider_rejected', 'Claude Code did not complete the structured request.')
                return value['structured_output']
            return value
        except (ValueError, KeyError, OSError, AttributeError):
            raise StudioError('invalid_provider_response', f'{provider.title()} did not return the requested structured result.') from None
        except subprocess.TimeoutExpired:
            raise StudioError('provider_outcome_unknown', f'{provider.title()} timed out. The paid request will not be repeated automatically.') from None


def generate(config, prompt, schema):
    provider, model, credential = config
    label = f'{provider.title()} / {model}'
    if isinstance(credential, tuple):
        return native_generate(provider, credential, prompt, schema)
    try:
        if provider == 'gemini':
            data, _ = post_json(f'https://generativelanguage.googleapis.com/v1beta/models/{quote(model, safe="")}:generateContent',
                                {'contents': [{'parts': [{'text': prompt}]}],
                                 'generationConfig': {'maxOutputTokens': 6500, 'responseMimeType': 'application/json'}},
                                {'x-goog-api-key': credential}, label)
            content = ''.join(part.get('text', '') for part in data['candidates'][0]['content']['parts']
                              if not part.get('thought'))
        elif provider == 'anthropic':
            data, _ = post_json('https://api.anthropic.com/v1/messages',
                                {'model': model, 'max_tokens': 4500, 'messages': [{'role': 'user', 'content': prompt}]},
                                {'x-api-key': credential, 'anthropic-version': '2023-06-01'}, label)
            content = ''.join(part.get('text', '') for part in data['content'] if part.get('type') == 'text')
        elif provider == 'xai':
            data, _ = post_json('https://api.x.ai/v1/chat/completions',
                                {'model': model, 'messages': [{'role': 'user', 'content': prompt}],
                                 'max_tokens': 4500, 'response_format': {'type': 'json_object'}},
                                {'Authorization': f'Bearer {credential}'}, label)
            content = data['choices'][0]['message']['content']
        else:
            data, _ = post_json('https://api.openai.com/v1/responses',
                                {'model': model, 'input': prompt, 'max_output_tokens': 6500, 'store': False,
                                 'text': {'format': {'type': 'json_schema', 'name': 'studio_response',
                                                     'schema': schema, 'strict': True}}},
                                {'Authorization': f'Bearer {credential}'}, label)
            content = ''.join(part.get('text', '') for item in data['output']
                              if item.get('type') == 'message' for part in item.get('content', [])
                              if part.get('type') == 'output_text')
        return json.loads(content)
    except (IndexError, ValueError, KeyError, TypeError, AttributeError):
        raise StudioError('invalid_provider_response', f'{label} did not return the requested structured result.') from None


def synthesize(config, text, language):
    voice, model, key = config
    payload = {'text': text, 'model_id': model}
    if model != 'eleven_multilingual_v2':
        payload['language_code'] = language
    body, headers = post_json(
        f'https://api.elevenlabs.io/v1/text-to-speech/{quote(voice, safe="")}/with-timestamps?output_format=mp3_44100_128',
        payload,
        {'xi-api-key': key}, 'ElevenLabs', timeout=100, maximum=15_000_000,
    )
    return body, {'provider': 'ElevenLabs', 'model': model, 'voice_id': voice,
                  'request_id': headers.get('request-id'), 'billed_characters': headers.get('character-cost')}
