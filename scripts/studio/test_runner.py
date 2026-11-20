"""Offline acceptance for render and revision. The fixture audio is a tone, never speech."""

import base64
from hashlib import sha256
import json
from pathlib import Path
import subprocess
import sys
from tempfile import TemporaryDirectory
import unittest
from unittest.mock import patch
from zipfile import ZipFile

from PIL import Image

import render
import run
from errors import StudioError


class RunnerTest(unittest.TestCase):
    def setUp(self):
        temporary = TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        self.request = {
            'version': 1, 'run_id': 'fixture-produce-1', 'kind': 'produce', 'evidence': [],
            'project': {'id': 17, 'title': 'বিস্কুট গেল কোথায়', 'brief': 'A short family comedy about a missing biscuit.',
                        'format': 'reel', 'workspace_name': 'Offline Test', 'locale': 'bn-IN'},
            'settings': {'provider': 'fake_writer_a', 'alternative_provider': 'fake_writer_b',
                         'max_model_calls': 3, 'max_voice_characters': 800, 'voice_id': 'test-only'},
        }
        self.calls = []
        self.voices_resolved = []
        self.spoken = []

    def engine(self, request, directory):
        directory.mkdir(exist_ok=True)
        return run.Engine(request, directory, model_resolver=lambda name: (name, 'test-only'),
                          voice_resolver=self.voices_resolved.append, model=self.fake_model, voice=self.fake_voice)

    def fake_model(self, config, prompt, schema):
        self.calls.append(config[0])
        if schema is run.REVIEW_SCHEMA:
            return {'selected': 0, 'rationale': 'The bowl is a visible callback.',
                    'assessment_a': 'The reversal happens in the kitchen.',
                    'assessment_b': 'The reveal depends on a line at the end.'}
        base = {'setting': 'kitchen', 'camera': 'wide', 'character': 'mother', 'secondary': 'boy',
                'expression': 'suspicious', 'action': 'show', 'prop': 'bowl', 'speech': 'বিস্কুট গেল কোথায়?'}
        if schema is run.REVISION_SCHEMA:
            return {'applicable': True, 'shot': {**base, 'camera': 'insert'}, 'rationale': 'Hold on a close insert of the bowl.'}
        if config[0] == 'fake_writer_a':
            return {'title': 'বিস্কুট গেল কোথায়', 'angle': 'Maa catches a secret biscuit thief.',
                    'hook': 'A bowl slides out from under the plate.', 'payoff': 'The boy forgot to sweep up the crumbs.',
                    'caption': 'বিস্কুট গেল কোথায়? মেঝেটা দেখেছ? 🍪',
                    'shots': [base, dict(base, camera='close', character='boy', secondary='mother', speech='বাটিটা খালি ছিল।'),
                              dict(base, camera='reaction', action='look', speech='তা হলে মেঝেতে গুঁড়ো কেন?')]}
        return {'title': 'দ্বিতীয় কাপ', 'angle': 'Maa gives up her cup.', 'hook': 'A cup appears under the table.',
                'payoff': 'Baba is caught sharing the hidden cup.', 'caption': 'দ্বিতীয় কাপটা কোথায় গেল? ☕',
                'shots': [dict(base, camera='close', speech='আর এক কাপ বানাই?'),
                          dict(base, camera='insert', speech='তোমার কাপ তো এখানেই।'),
                          dict(base, camera='reaction', speech='চুপ, বাবা আসছে।')]}

    def fake_voice(self, config, text, language):
        self.assertEqual(language, 'bn')
        self.spoken.append(text)
        mp3 = render.run_media('ffmpeg', '-v', 'error', '-f', 'lavfi', '-i',
                               'sine=frequency=520:duration=0.7', '-f', 'mp3', '-')
        return ({'audio_base64': base64.b64encode(mp3).decode('ascii'),
                 'alignment': {'characters': list(text),
                               'character_end_times_seconds': [.7 * (i + 1) / len(text) for i in range(len(text))]}},
                {'provider': 'OFFLINE TEST ONLY', 'model': 'sine tone, no human speech'})

    def test_native_workspace_jpeg_is_accepted_as_a_brand_mark(self):
        path = self.root / 'workspace-logo'
        Image.new('RGB', (216, 108), 'white').save(path, format='JPEG')
        mark = render.load_mark(path)
        self.assertEqual(mark.size, (108, 54))
        self.assertEqual(mark.mode, 'RGBA')

    def test_verified_portrait_render_and_exact_source_revision(self):
        render.available()
        original = self.root / 'original'
        manifest = self.engine(self.request, original).produce()
        self.assertEqual(manifest['status'], 'ready_for_review')
        self.assertEqual(manifest['caption'], manifest['treatments'][0]['caption'])
        self.assertEqual([item['provider'] for item in manifest['treatments']], ['fake_writer_a', 'fake_writer_b'])
        self.assertEqual(manifest['usage']['model_calls'], 3)
        self.assertEqual(manifest['verification']['width'], 1080)
        self.assertEqual(manifest['verification']['height'], 1920)
        self.assertTrue(manifest['verification']['full_decode_passed'])
        self.assertIn('বাটিটা', (original / 'captions.srt').read_text())
        revise = dict(self.request, run_id='fixture-revise-1', kind='revise',
                      revision={'time_seconds': .3, 'note': 'Show the bowl as an insert.',
                                'parent_dir': str(original), 'parent_manifest': manifest})
        revised = self.root / 'revised'
        child = self.engine(revise, revised).revise()
        self.assertEqual(child['usage'], {'model_calls': 1, 'voice_characters': 0})
        self.assertEqual(child['verification']['duration_seconds'], manifest['verification']['duration_seconds'])
        self.assertEqual(child['caption'], manifest['caption'])
        self.assertEqual(child['revision']['index'], 0)
        self.assertEqual(child['revision']['preserved_shots'], 2)
        self.assertNotEqual(child['source_files']['source/clips/shot-00.mp4'], manifest['source_files']['source/clips/shot-00.mp4'])
        retained = [name for name in manifest['source_files'] if 'take-' in name or 'shot-0' in name and 'shot-00' not in name]
        self.assertEqual(len(retained), 5)
        for name in retained:
            self.assertEqual(child['source_files'][name], manifest['source_files'][name])
        self.assertEqual((original / 'captions.srt').read_bytes(), (revised / 'captions.srt').read_bytes())
        extracted = self.root / 'rebuilt'
        with ZipFile(revised / 'editable-source.zip') as archive:
            self.assertIn('rebuild.py', archive.namelist())
            self.assertIsNone(archive.testzip())
            archive.extractall(extracted)
        result = subprocess.run([sys.executable, extracted / 'rebuild.py'], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual((revised / 'captions.srt').read_bytes(), (extracted / 'captions.srt').read_bytes())
        self.assertTrue((extracted / 'film.mp4').is_file())
        # The normal CLI recognizes a verified on-disk result without resolving live providers.
        request_path = self.root / 'request.json'
        run.put_json(request_path, self.request)
        result = subprocess.run([sys.executable, run.__file__, request_path, original], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())

    def test_storyboard_choice_resumes_the_original_run(self):
        request = dict(self.request, review_storyboard=True)
        request_path = self.root / 'request.json'
        run.put_json(request_path, request)
        output = self.root / 'run'
        storyboard_path = output / 'storyboard.json'
        approval_path = output / 'storyboard-approval.json'

        with patch.object(render, 'available') as renderer:
            storyboard = self.engine(request, output).produce()
            renderer.assert_not_called()
        self.assertEqual(storyboard['status'], 'awaiting_storyboard')
        self.assertEqual(storyboard['review']['selected'], 0)
        self.assertEqual([item['selected'] for item in storyboard['treatments']], [True, False])
        self.assertEqual(len(self.calls), 3)
        original_bytes = storyboard_path.read_bytes()
        original_progress = (output / 'progress.json').read_bytes()
        self.assertFalse((output / 'source').exists())
        self.assertFalse((output / 'manifest.json').exists())

        # Repeating a paused run through the CLI uses live files but needs no providers.
        result = subprocess.run([sys.executable, run.__file__, request_path, output], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(json.loads(result.stdout), {'status': 'awaiting_storyboard'})
        self.assertEqual((output / 'progress.json').read_bytes(), original_progress)

        fingerprint = sha256(original_bytes).hexdigest()
        for treatment, approval_fingerprint in [(1, '0' * 64), (True, fingerprint)]:
            run.put_json(approval_path, {'treatment': treatment, 'fingerprint': approval_fingerprint})
            with self.assertRaises(StudioError) as error:
                self.engine(request, output).produce()
            self.assertEqual(error.exception.code, 'invalid_storyboard_approval')

        approved = {'treatment': 1, 'fingerprint': fingerprint}
        run.put_json(approval_path, approved)
        with patch.object(render, 'available', side_effect=StudioError('render_not_configured', 'Fixture: unavailable renderer.')):
            with self.assertRaises(StudioError) as error:
                self.engine(request, output).produce()
        self.assertEqual(error.exception.code, 'render_not_configured')
        run.put_json(approval_path, dict(approved, treatment=0))
        with self.assertRaises(StudioError) as error:
            self.engine(request, output).produce()
        self.assertEqual(error.exception.code, 'invalid_storyboard_approval')
        self.assertEqual(self.voices_resolved, [])
        self.assertEqual(self.spoken, [])
        self.assertEqual(len(self.calls), 3)

        run.put_json(approval_path, approved)
        manifest = self.engine(request, output).produce()
        self.assertEqual(manifest['status'], 'ready_for_review')
        self.assertEqual(manifest['review'], storyboard['review'])
        self.assertEqual([item['selected'] for item in manifest['treatments']], [False, True])
        self.assertEqual(manifest['title'], storyboard['treatments'][1]['title'])
        self.assertEqual(manifest['caption'], storyboard['treatments'][1]['caption'])
        self.assertEqual(manifest['summary'], storyboard['treatments'][1]['assessment'])
        self.assertIn('তোমার কাপ', (output / 'captions.srt').read_text(encoding='utf-8'))
        self.assertEqual(self.voices_resolved, ['test-only'])
        self.assertEqual(self.spoken, [shot['speech'] for shot in storyboard['treatments'][1]['shots']])
        self.assertEqual(manifest['usage']['model_calls'], 3)
        self.assertEqual(len(self.calls), 3)
        self.assertEqual(storyboard_path.read_bytes(), original_bytes)
        result = subprocess.run([sys.executable, run.__file__, request_path, output], capture_output=True)
        self.assertEqual(result.returncode, 0, result.stderr.decode())
        self.assertEqual(json.loads(result.stdout), {'status': 'ready_for_review'})

    def test_interrupted_request_is_not_replayed(self):
        output = self.root / 'run'
        engine = self.engine(self.request, output)

        def interrupted():
            self.calls.append('request-sent')
            raise StudioError('provider_outcome_unknown', 'Fixture: response lost.')

        for attempt in range(2):
            with self.assertRaises(StudioError) as error:
                engine.request_paid_once('editorial-review', 'model', 1, interrupted)
            self.assertEqual(error.exception.code, 'provider_outcome_unknown', attempt)
        self.assertEqual(self.calls, ['request-sent'])
        self.assertFalse((output / 'manifest.json').exists())


if __name__ == '__main__':
    unittest.main()
