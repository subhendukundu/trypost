"""Rebuild the illustrated cut from an extracted editable source archive."""

import json
from pathlib import Path

import render


def rebuild(root):
    render.available()
    source = root / 'source'
    plan = json.loads((source / 'plan.json').read_text(encoding='utf-8'))
    if plan.get('format') != 'studio-illustration/v1':
        raise ValueError('Choose an editable Studio illustration source archive.')
    mark = render.load_mark(source / 'brand.png')
    for index, shot in enumerate(plan['shots']):
        take = f'source/takes/take-{index:02d}.mp3'
        if shot['voice'] != take or not (root / take).resolve().is_relative_to(source.resolve()):
            raise ValueError('Each shot needs the original take included in the editable archive.')
        render.render_shot(shot['scene'], shot['captions'], shot['duration'],
                           source / f'clips/shot-{index:02d}.mp4', plan['brand'], mark)
    render.assemble(root, plan['shots'])
    render.poster(plan['shots'][0]['scene'], plan['title'], root / 'cover.jpg', plan['brand'], mark)
    render.write_captions(root / 'captions.srt', plan['shots'])


if __name__ == '__main__':
    rebuild(Path(__file__).resolve().parent)
