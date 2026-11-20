"""Render a constrained scene plan to a native vertical film with reusable shot assets."""

from functools import lru_cache
import json
import math
import os
from pathlib import Path
import subprocess
from tempfile import TemporaryFile
import zipfile

from errors import StudioError

try:
    from PIL import Image, ImageDraw, ImageFont, features
except ImportError:
    features = None

WIDTH, HEIGHT, FPS = 1080, 1920, 30
FONT_PATH = Path(__file__).parent / 'assets/NotoSerifBengali.ttf'
SETTINGS = {
    'kitchen': ('#183630', '#71a488', '#e1bd80', '#324e45'),
    'living_room': ('#332b46', '#a47689', '#e1ae84', '#5d495d'),
    'dining_room': ('#4a2a31', '#bd7667', '#efba90', '#634043'),
    'hallway': ('#173a48', '#589495', '#ffc888', '#325c61'),
    'bedroom': ('#302947', '#8d80ae', '#edb690', '#514963'),
    'courtyard': ('#274331', '#83ad77', '#f5cc89', '#597556'),
}
PROPS = ('phone', 'plate', 'cup', 'book', 'shoe', 'bag', 'garment', 'bowl', 'clock', 'none')
CHARACTERS = ('mother', 'father', 'girl', 'boy', 'elder')
CAMERAS = ('wide', 'close', 'reaction', 'insert')
ACTIONS = ('speak', 'point', 'lift', 'hand_over', 'look', 'recoil', 'show', 'wait')
EXPRESSIONS = ('neutral', 'smile', 'suspicious', 'surprised', 'annoyed')
ACTORS = {
    'mother': ('#c7865e', '#261a21', '#b74554'),
    'father': ('#b87952', '#25212a', '#246b74'),
    'girl': ('#ce8e62', '#2d2226', '#71569b'),
    'boy': ('#d29165', '#28222b', '#a16a38'),
    'elder': ('#bd805c', '#c3c4c1', '#60784a'),
}


def tool(name):
    return os.environ.get(f'STUDIO_{name.upper()}_BINARY', name)


def run_media(name, *arguments, timeout=120):
    try:
        return subprocess.run([tool(name), *map(str, arguments)], capture_output=True, check=True,
                              timeout=timeout).stdout
    except (OSError, subprocess.CalledProcessError, subprocess.TimeoutExpired):
        raise StudioError('media_tool_failed', f'{name} could not process the Studio media. Check the runtime and input files.') from None


def available():
    if features is None or not features.check('raqm'):
        raise StudioError('render_not_configured', 'Install Pillow with RAQM so Bengali captions shape correctly.')
    if not FONT_PATH.is_file():
        raise StudioError('render_not_configured', 'The Bengali typeface is missing from the Studio runtime.')
    encoders = run_media('ffmpeg', '-hide_banner', '-encoders', timeout=10)
    if b' libx264 ' not in encoders or b' aac ' not in encoders:
        raise StudioError('render_not_configured', 'The FFmpeg build must provide libx264 and AAC encoding.')
    run_media('ffprobe', '-version', timeout=10)


@lru_cache(maxsize=16)
def font(size):
    return ImageFont.truetype(str(FONT_PATH), size, layout_engine=ImageFont.Layout.RAQM)


def stream_info(path):
    return json.loads(run_media('ffprobe', '-v', 'error', '-show_streams', '-show_format',
                                '-of', 'json', path, timeout=25))


def audio_duration(path):
    value = stream_info(path)
    audio = next((stream for stream in value.get('streams', []) if stream['codec_type'] == 'audio'), None)
    duration = float(value.get('format', {}).get('duration', '0'))
    if not audio or not math.isfinite(duration) or not 0.25 <= duration <= 24:
        raise StudioError('voice_duration', 'A voice take has an unsupported duration. Studio preserves its full performance.')
    run_media('ffmpeg', '-v', 'error', '-xerror', '-i', path, '-map', '0:a', '-f', 'null', '-', timeout=25)
    return duration


def interpolate(a, b, value):
    return round(a + (b - a) * value)


def bg_for(scene):
    wall, accent, light, ground = SETTINGS[scene['setting']]
    image = Image.new('RGB', (WIDTH, HEIGHT), wall)
    d = ImageDraw.Draw(image)
    d.polygon([(0, 1335), (1080, 1270), (1080, 1920), (0, 1920)], fill=ground)
    for offset in range(8):
        y = 1355 + 92 * offset
        d.line((0, y, WIDTH, y - 68), fill=wall, width=2)
    d.rounded_rectangle((80, 288, 448, 855), 25, fill=ground, outline=accent, width=11)
    d.rounded_rectangle((104, 313, 424, 830), 13, fill=light)
    d.line((263, 325, 263, 818), fill=wall, width=12)
    d.line((113, 570, 412, 570), fill=wall, width=12)
    d.polygon([(114, 614), (254, 586), (254, 810), (114, 813)], fill=accent)
    d.polygon([(275, 647), (413, 600), (413, 813), (275, 813)], fill=accent)
    d.polygon([(426, 333), (942, 1275), (1080, 1215), (425, 569)], fill=ground)
    d.arc((650, 428, 934, 697), 198, 353, fill=accent, width=10)
    d.ellipse((752, 511, 811, 570), fill=light)
    d.line((786, 540, 804, 525), fill=ground, width=4)
    d.line((787, 540, 762, 539), fill=ground, width=4)
    if scene['setting'] == 'kitchen':
        for index in range(4):
            x = 618 + 100 * index
            d.rounded_rectangle((x, 942, x + 78, 1030), 13, fill=accent)
            d.rectangle((x + 9, 944, x + 69, 957), fill=light)
        d.rounded_rectangle((510, 1032, 1080, 1125), 14, fill=accent)
    elif scene['setting'] in {'dining_room', 'living_room'}:
        d.rounded_rectangle((482, 907, 1074, 1353), 42, fill=accent)
        d.line((505, 1139, 1064, 1139), fill=wall, width=14)
    elif scene['setting'] == 'courtyard':
        for x in (640, 892):
            d.line((x, 1038, x + 35, 776), fill=light, width=12)
            d.ellipse((x - 34, 922, x + 80, 1100), fill=ground)
            d.ellipse((x + 9, 802, x + 133, 949), fill=accent)
    else:
        d.rounded_rectangle((592, 736, 989, 1270), 28, fill=ground, outline=accent, width=10)
        d.ellipse((908, 970, 933, 995), fill=light)
    return image


def text_lines(d, text, face, max_width, limit):
    rows = []
    words = text.split()
    while words and len(rows) < limit:
        row = words.pop(0)
        if d.textlength(row, font=face) > max_width:
            raise StudioError('caption_too_wide', 'A Bengali caption word exceeds the available screen width.')
        while words and d.textlength(f'{row} {words[0]}', font=face) <= max_width:
            row += ' ' + words.pop(0)
        rows.append(row)
    if words:
        raise StudioError('caption_too_wide', 'A text label will not fit the vertical review cut.')
    return rows


def draw_prop(draw, prop, x, y, scale):
    def box(*values):
        return tuple(round((x if index % 2 == 0 else y) + value * scale)
                     for index, value in enumerate(values))

    if prop == 'none':
        return
    if prop == 'phone':
        draw.rounded_rectangle(box(-65, -101, 65, 101), round(15 * scale), fill='#202630', outline='#c0d4db', width=round(8 * scale))
        draw.rounded_rectangle(box(-51, -83, 51, 70), round(9 * scale), fill='#6db1a4')
        draw.line(box(-45, -43, 38, -43), fill='#f7f0da', width=round(9 * scale))
        draw.line(box(-45, -14, 24, -14), fill='#d1e5c2', width=round(9 * scale))
        draw.ellipse(box(-10, 80, 10, 96), fill='#c6bdc1')
    elif prop in ('plate', 'bowl', 'cup'):
        if prop == 'cup':
            draw.rounded_rectangle(box(38, -46, 89, 30), round(21 * scale), fill='#ffd9b0')
            draw.rounded_rectangle(box(52, -29, 73, 11), round(8 * scale), fill='#795247')
        draw.rounded_rectangle(box(-86, -41, 62, 50), round(27 * scale), fill='#eac3a6')
        draw.ellipse(box(-100, -58, 76, -6), fill='#fff0d4', outline='#c59177', width=round(5 * scale))
        draw.ellipse(box(-73, -46, 48, -24), fill='#ac6542')
    elif prop == 'clock':
        draw.ellipse(box(-78, -78, 78, 78), fill='#fae6c2', outline='#d7935b', width=round(9 * scale))
        draw.line(box(2, 7, 2, -44), fill='#50342f', width=round(8 * scale))
        draw.line(box(0, 6, 47, 27), fill='#50342f', width=round(8 * scale))
    elif prop in ('book', 'garment'):
        draw.rounded_rectangle(box(-83, -71, 85, 71), round(11 * scale), fill='#edbf79', outline='#8d493c', width=round(6 * scale))
        draw.line(box(-68, -52, -68, 55), fill='#924b46', width=round(6 * scale))
        draw.line(box(-49, -33, 52, -33), fill='#805446', width=round(6 * scale))
        draw.line(box(-49, -9, 36, -9), fill='#805446', width=round(6 * scale))
    elif prop == 'bag':
        draw.arc(box(-43, -105, 53, -17), 183, 357, fill='#f8bc87', width=round(10 * scale))
        draw.rounded_rectangle(box(-93, -51, 103, 72), round(17 * scale), fill='#aa7059', outline='#f8bc87', width=round(6 * scale))
        draw.line(box(-91, -15, 102, -15), fill='#e9ad81', width=round(5 * scale))
    else:
        draw.polygon([box(-88, 0)[0:2], box(-54, -51)[0:2], box(25, -33)[0:2],
                      box(45, -12)[0:2], box(92, 0)[0:2], box(91, 28)[0:2], box(-91, 28)[0:2]], fill='#d99d82')
        draw.line(box(-87, 22, 89, 24), fill='#58403a', width=round(11 * scale))


def draw_actor(draw, scene, x, y, scale, t, speaking, look):
    skin, hair, clothes = ACTORS[scene['character']]
    child = scene['character'] in ('girl', 'boy')
    feminine = scene['character'] in ('mother', 'girl')
    gesture = scene['action']
    x += math.sin(t * 1.5) * 8
    y += math.sin(t * 2) * 4
    if gesture == 'recoil':
        x -= 58 * math.sin(min(1, t / .7) * math.pi / 2)
        y -= 22 * math.sin(min(1, t / .7) * math.pi / 2)
    elif gesture == 'look':
        look = .9 + .9 * math.sin(t * 2.4)

    def box(*values):
        return tuple(round((x if index % 2 == 0 else y) + value * scale)
                     for index, value in enumerate(values))

    def points(*values):
        coords = box(*values)
        return list(zip(coords[::2], coords[1::2]))

    draw.ellipse(box(-205, 388, 204, 460), fill='#243730')
    draw.rounded_rectangle(box(-120, 190, -23, 418), round(23 * scale), fill='#303345')
    draw.rounded_rectangle(box(18, 190, 110, 421), round(23 * scale), fill='#303345')
    draw.rounded_rectangle(box(-158, 405, -21, 450), round(23 * scale), fill='#242732')
    draw.rounded_rectangle(box(8, 405, 139, 450), round(23 * scale), fill='#242732')
    draw.rounded_rectangle(box(-175, -62, 175, 253), round(94 * scale), fill=clothes)
    draw.arc(box(-181, -69, 180, 254), 177, 354, fill='#f2b88c', width=max(2, round(4 * scale)))
    if feminine:
        draw.polygon(points(-154, -49, -65, -73, 153, 190, 122, 250), fill='#dfad72')
        if not child:
            draw.polygon(points(-163, 163, 174, 173, 162, 421, -164, 419), fill=clothes)
            draw.line(box(-144, 413, 157, 413), fill='#dfad72', width=round(8 * scale))
    hand = {'point': (273, -21), 'lift': (106, -105), 'hand_over': (271, 41),
            'show': (167, -33), 'recoil': (216, -71)}.get(gesture, (151, 149))
    arm = math.sin(min(1, t / 1.1) * math.pi / 2)
    thumb_x, thumb_y = interpolate(151, hand[0], arm), interpolate(149, hand[1], arm)
    draw.line([*points(-138, 19, -199, 123, -150, 193)], fill=skin, width=round(42 * scale), joint='curve')
    draw.line([*points(140, 24, 184, 112, thumb_x, thumb_y)], fill=skin, width=round(42 * scale), joint='curve')
    draw.ellipse(box(thumb_x - 23, thumb_y - 25, thumb_x + 27, thumb_y + 30), fill=skin)
    draw.rounded_rectangle(box(-46, -103, 48, -28), round(25 * scale), fill='#ad6b4c')
    if feminine:
        draw.ellipse(box(-139, -305, 144, -69), fill=hair)
        if not child:
            draw.ellipse(box(-172, -252, -107, -174), fill=hair)
    draw.ellipse(box(-119, -303, 123, -81), fill=skin)
    draw.ellipse(box(-135, -210, -95, -151), fill=skin)
    draw.ellipse(box(95, -211, 134, -151), fill=skin)
    draw.pieslice(box(-126, -316, 129, -185), 180, 360, fill=hair)
    draw.polygon(points(-116, -237, -85, -277, -36, -257, 101, -269, 126, -237, 113, -280,
                        66, -303, -63, -303, -119, -275), fill=hair)
    if feminine:
        draw.ellipse(box(-127, -171, -106, -151), fill='#edc680')
        draw.ellipse(box(108, -172, 129, -151), fill='#edc680')
        if not child:
            draw.ellipse(box(-7, -241, 9, -225), fill='#a13542')
    emotion = scene['expression']
    blink = math.sin(t * 2.5 + 1.5) > 0.99
    eyebrows = -234 if emotion in ('annoyed', 'suspicious') else -242
    for eye in (-55, 48):
        draw.line(box(eye - 24, eyebrows + (-7 if emotion == 'surprised' else 0), eye + 22, eyebrows + 2),
                  fill=hair, width=max(4, round(7 * scale)))
        if blink:
            draw.line(box(eye - 21, -208, eye + 21, -208), fill=hair, width=max(4, round(7 * scale)))
            continue
        draw.ellipse(box(eye - 22, -222, eye + 22, -191), fill='#fff3e7')
        shift = 7 * look
        draw.ellipse(box(eye + shift - 9, -217, eye + shift + 11, -195), fill='#372c31')
        draw.ellipse(box(eye + shift - 3, -214, eye + shift + 3, -208), fill='#fff9ea')
        if scene['character'] == 'father':
            draw.rounded_rectangle(box(eye - 34, -229, eye + 34, -187), round(9 * scale), outline='#453b44', width=round(6 * scale))
    draw.line(box(-2, -207, -10, -181), fill='#ab6c51', width=round(6 * scale))
    draw.line(box(-9, -182, 10, -175), fill='#ab6c51', width=round(5 * scale))
    if speaking:
        stretch = 6 + abs(math.sin(t * 20)) * 24
        draw.ellipse(box(-34, -150 - stretch / 2, 34, -150 + stretch), fill='#63333d')
        draw.arc(box(-25, -154, 25, -135), 0, 180, fill='#f7dcc8', width=max(3, round(5 * scale)))
    elif emotion == 'smile':
        draw.arc(box(-38, -183, 42, -124), 1, 179, fill='#703743', width=max(4, round(7 * scale)))
    elif emotion == 'surprised':
        draw.ellipse(box(-17, -162, 21, -133), fill='#713844')
    else:
        draw.line(box(-29, -142, 29, -144), fill='#713943', width=max(4, round(6 * scale)))
    if scene['prop'] != 'none':
        draw_prop(draw, scene['prop'], x + (thumb_x + 19) * scale, y + (thumb_y - 11) * scale, scale * .55)


def load_mark(path):
    if path is None or not path.is_file():
        return None
    with Image.open(path) as image:
        if image.format != 'PNG' or image.width > 4096 or image.height > 4096:
            raise StudioError('invalid_brand_mark', 'The workspace mark must be a PNG smaller than 4096 pixels in each dimension.')
        mark = image.convert('RGBA')
        mark.thumbnail((108, 108))
        return mark


def plate(d, active_caption, caption_face):
    if not active_caption:
        return
    rows = active_caption.splitlines()
    h = 46 + 58 * len(rows)
    top = 1575 - h // 2
    d.rounded_rectangle((86, top, 994, top + h), radius=28, fill='#171e25')
    for i, row in enumerate(rows):
        d.text((540, top + 30 + 58 * i), row, font=caption_face, fill='#fdf7ee', anchor='mt')


def draw_frame(background, scene, moment, speaking, caption, caption_face, brand, mark):
    image = background.copy()
    d = ImageDraw.Draw(image)
    if scene['camera'] == 'insert':
        d.ellipse((178, 1265, 902, 1370), fill='#242d31')
        d.rounded_rectangle((177, 811, 899, 1298), 96, fill='#291f37', outline='#e4b889', width=7)
        prop = scene['prop'] if scene['prop'] != 'none' else 'clock'
        draw_prop(d, prop, 538, 1050 - math.sin(moment * 2) * 22, 2.65 + min(moment, 1.2) * .16)
    else:
        scale, center = {'close': (1.44, 522), 'reaction': (1.31, 565), 'wide': (1.02, 506)}[scene['camera']]
        if scene['camera'] == 'reaction':
            scale += min(moment, .7) * .14
        if scene['secondary'] != 'none':
            side = dict(scene, character=scene['secondary'], action='wait', prop='none', expression='suspicious')
            draw_actor(d, side, 769, 1066, .74, moment, False, -1)
            center -= 135 if scene['camera'] == 'wide' else 96
        draw_actor(d, scene, center, 1060, scale, moment, speaking, 1 if scene['secondary'] != 'none' else 0)
    if mark is not None:
        image.paste(mark, (round((WIDTH - mark.width) / 2), 62), mark)
    else:
        d.text((540, 99), brand, font=font(37), fill='#fff0de', anchor='mt')
    plate(d, caption, caption_face)
    return image


def verify_shot(path, seconds):
    value = stream_info(path)
    video = next((stream for stream in value.get('streams', []) if stream['codec_type'] == 'video'), None)
    if (not video or (video['width'], video['height']) != (WIDTH, HEIGHT)
            or video.get('codec_name') != 'h264' or video.get('r_frame_rate') != '30/1'):
        raise StudioError('shot_verification_failed', 'The rendered shot failed vertical frame verification.')
    real = float(value['format']['duration'])
    if abs(real - seconds) >= .065:
        raise StudioError('shot_verification_failed', 'The rendered shot does not match the voice timeline.')
    return real


def render_shot(scene, captions, seconds, clip, brand, mark):
    temp = clip.with_suffix('.partial.mp4')
    background = bg_for(scene)
    face = font(48)
    frames = round(seconds * FPS)
    command = [tool('ffmpeg'), '-y', '-v', 'error', '-f', 'rawvideo', '-pixel_format', 'rgb24',
               '-video_size', f'{WIDTH}x{HEIGHT}', '-framerate', str(FPS), '-i', '-', '-an', '-c:v', 'libx264',
               '-preset', 'ultrafast', '-crf', '20', '-pix_fmt', 'yuv420p', '-threads', '2', str(temp)]
    try:
        with TemporaryFile() as diagnostics:
            process = subprocess.Popen(command, stdin=subprocess.PIPE, stdout=subprocess.DEVNULL,
                                       stderr=diagnostics)
            try:
                for index in range(frames):
                    moment = index / FPS
                    cue = next((value for value in captions if value['start'] <= moment < value['end']), None)
                    frame = draw_frame(background, scene, moment, cue is not None,
                                       cue['screen_text'] if cue is not None else None, face, brand, mark)
                    process.stdin.write(frame.tobytes())
                process.stdin.close()
                if process.wait(timeout=60):
                    raise StudioError('media_tool_failed', 'FFmpeg could not encode the illustrated shot.')
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
                if not process.stdin.closed:
                    process.stdin.close()
        verify_shot(temp, seconds)
        temp.replace(clip)
    except (OSError, subprocess.TimeoutExpired):
        raise StudioError('media_tool_failed', 'FFmpeg could not encode the illustrated shot.') from None
    finally:
        temp.unlink(missing_ok=True)


def poster(scene, title, path, brand, mark):
    deep_scene = dict(scene, camera='close', secondary='none')
    image = draw_frame(bg_for(deep_scene), deep_scene, 1, False, None, font(48), brand, mark)
    d = ImageDraw.Draw(image)
    face = font(65)
    rows = text_lines(d, title, face, 860, 2)
    top = 1510 - 43 * len(rows) - 44
    d.rounded_rectangle((75, top, 1005, top + 86 * len(rows) + 88), 32, fill='#202b2a')
    for index, row in enumerate(rows):
        d.text((540, top + 47 + 86 * index), row, font=face, fill='#f8e2c1', anchor='mt')
    image.save(path, quality=92)


def assemble(output, shots):
    clips_path = output / 'source/clips.txt'
    frame_manifest = []
    pcm_paths = []
    for index, shot in enumerate(shots):
        name = f'shot-{index:02d}.mp4'
        frame_manifest.append(f"file 'clips/{name}'\n")
        pcm = output / f'source/takes/timing-{index:02d}.wav'
        run_media('ffmpeg', '-y', '-v', 'error', '-i', output / shot['voice'], '-af', 'adelay=200:all=1,apad',
                  '-t', round(shot['duration'], 6), '-ar', 48000, '-ac', 1, '-c:a', 'pcm_s16le', pcm)
        pcm_paths.append(pcm)
    clips_path.write_text(''.join(frame_manifest), encoding='utf-8')
    voices_path = output / 'source/voices.txt'
    voices_path.write_text(''.join(f"file 'takes/{pcm.name}'\n" for pcm in pcm_paths), encoding='utf-8')
    partial = output / 'film.partial.mp4'
    final = output / 'film.mp4'
    run_media('ffmpeg', '-y', '-v', 'error', '-f', 'concat', '-safe', '1', '-i', clips_path,
              '-f', 'concat', '-safe', '1', '-i', voices_path, '-map', '0:v:0', '-map', '1:a:0',
              '-c:v', 'copy', '-c:a', 'aac', '-b:a', '160k', '-movflags', '+faststart', partial, timeout=150)
    info = stream_info(partial)
    streams = info.get('streams', [])
    video = next((stream for stream in streams if stream['codec_type'] == 'video'), None)
    audio = next((stream for stream in streams if stream['codec_type'] == 'audio'), None)
    duration = float(info['format']['duration'])
    timeline = sum(shot['duration'] for shot in shots)
    if (not video or (video['width'], video['height']) != (WIDTH, HEIGHT)
            or video.get('r_frame_rate') != '30/1' or video.get('codec_name') != 'h264'
            or not audio or audio.get('codec_name') != 'aac' or abs(duration - timeline) > .12):
        raise StudioError('film_verification_failed', 'The final film did not pass the portrait, audio and timing checks.')
    run_media('ffmpeg', '-v', 'error', '-xerror', '-i', partial, '-map', '0:v', '-map', '0:a',
              '-f', 'null', '-', timeout=100)
    partial.replace(final)
    return {'width': WIDTH, 'height': HEIGHT, 'fps': FPS, 'duration_seconds': duration,
            'video_codec': video.get('codec_name'), 'audio_codec': audio.get('codec_name'),
            'full_decode_passed': True, 'renderer': 'Studio illustrated scene renderer / FFmpeg'}


def timestamp(seconds):
    total = round(seconds * 1000)
    whole, millis = divmod(total, 1000)
    minutes, sec = divmod(whole, 60)
    hour, mins = divmod(minutes, 60)
    return f'{hour:02}:{mins:02}:{sec:02},{millis:03}'


def write_captions(path, shots):
    lines = []
    offset = 0
    count = 1
    for shot in shots:
        for caption in shot['captions']:
            lines.extend([str(count), f'{timestamp(offset + caption["start"])} --> {timestamp(offset + caption["end"])}',
                          caption['screen_text'], ''])
            count += 1
        offset += shot['duration']
    path.write_text('\n'.join(lines), encoding='utf-8')


def source_archive(output, filenames):
    into = output / 'editable-source.zip'
    with zipfile.ZipFile(into, 'w', compression=zipfile.ZIP_DEFLATED, compresslevel=3) as archive:
        for name in filenames:
            archive.write(output / name, name)
        archive.write(FONT_PATH, f'assets/{FONT_PATH.name}')
        archive.write(FONT_PATH.with_name('OFL-NotoSerifBengali.txt'), 'assets/OFL-NotoSerifBengali.txt')
        for name in ('rebuild.py', 'render.py', 'errors.py', 'requirements.txt'):
            archive.write(Path(__file__).with_name(name), name)
        archive.writestr('README.txt', 'Edit the scenes in source/plan.json. Retain each scene’s original dialogue and take.\n'
                         'Install FFmpeg and Python 3.11 or later, then install requirements.txt with pip.\n'
                         'Run python rebuild.py inside this folder to render film.mp4, cover.jpg, and captions.srt.\n'
                         'The rebuild renders every shot locally and uses no model or voice services.\n')
    return into
