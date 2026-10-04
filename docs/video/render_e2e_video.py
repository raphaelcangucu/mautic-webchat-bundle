#!/usr/bin/env python3
"""Render a captioned E2E video from browser-validated screenshots."""

from __future__ import annotations

import shutil
import subprocess
import tempfile
import textwrap
from pathlib import Path

from PIL import Image, ImageDraw, ImageFilter, ImageFont, ImageOps


ROOT = Path(__file__).resolve().parents[2]
SCREENSHOTS = ROOT / "docs" / "screenshots"
OUTPUT = ROOT / "docs" / "video"
FONT_DIR = Path("/System/Library/Fonts/Supplemental")
FONT_REGULAR = FONT_DIR / "Arial.ttf"
FONT_BOLD = FONT_DIR / "Arial Bold.ttf"

if not FONT_REGULAR.exists():
    FONT_REGULAR = Path("/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf")
    FONT_BOLD = Path("/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf")

WIDTH, HEIGHT = 1920, 1080
NAVY = "#171A24"
MUTED = "#5D6677"
PURPLE = "#5668B3"
SURFACE = "#F6F7FB"

SCENES: list[tuple[str, str, int]] = [
    ("webchat-admin.jpg", "1. Widget configurável no Mautic: domínio, aparência, Inbox e agente inicial", 5),
    ("ai-pi-configuration.jpg", "2. Pi/Codex validado com agente, modelo econômico, documentos e limite 0 = ilimitado", 5),
    ("inbox-visitor-typing-final.jpg", "3. O Inbox recebe em tempo real o indicador de digitação do visitante", 5),
    ("widget-read-receipt.jpg", "4. O widget confirma entrega e leitura com recibos ✓✓", 5),
    ("widget-agent-named-typing.jpg", "5. O visitante vê qual agente de IA está digitando", 5),
    ("inbox-ai-answer.jpg", "6. O agente consulta o relatório publicado e responde dentro do escopo", 6),
    ("widget-followup-answer.jpg", "7. A conversa mantém contexto para perguntas de acompanhamento", 5),
    ("widget-final-conversation.jpg", "8. Histórico completo no site, autores identificados e mensagens lidas", 6),
    ("inbox-resolved-final.jpg", "9. O agente encerra: Resolved, Closed by agent e 4 respostas ilimitadas", 7),
]


def font(path: Path, size: int) -> ImageFont.FreeTypeFont:
    return ImageFont.truetype(str(path), size=size)


def fit_cover(image: Image.Image, size: tuple[int, int]) -> Image.Image:
    return ImageOps.fit(image, size, method=Image.Resampling.LANCZOS)


def fit_contain(image: Image.Image, size: tuple[int, int]) -> Image.Image:
    return ImageOps.contain(image, size, method=Image.Resampling.LANCZOS).convert("RGB")


def wrap(text: str, width: int) -> str:
    return "\n".join(textwrap.wrap(text, width=width, break_long_words=False))


def title_card(title: str, subtitle: str) -> Image.Image:
    canvas = Image.new("RGB", (WIDTH, HEIGHT), SURFACE)
    draw = ImageDraw.Draw(canvas)
    draw.rounded_rectangle((130, 180, 144, 760), radius=7, fill=PURPLE)
    draw.text((195, 260), title, font=font(FONT_BOLD, 78), fill=NAVY)
    draw.multiline_text(
        (200, 505),
        subtitle,
        font=font(FONT_REGULAR, 38),
        fill=MUTED,
        spacing=18,
    )
    draw.text((200, 780), "Macro Markets · Mautic", font=font(FONT_BOLD, 30), fill=PURPLE)
    return canvas


def screenshot_card(path: Path, caption: str) -> Image.Image:
    source = Image.open(path).convert("RGB")
    background = fit_cover(source, (WIDTH, HEIGHT)).filter(ImageFilter.GaussianBlur(34))
    shade = Image.new("RGBA", (WIDTH, HEIGHT), (18, 25, 45, 122))
    canvas = Image.alpha_composite(background.convert("RGBA"), shade)

    foreground = fit_contain(source, (1824, 842))
    x = (WIDTH - foreground.width) // 2
    y = 158 + (842 - foreground.height) // 2

    shadow = Image.new("RGBA", (foreground.width + 36, foreground.height + 36), (0, 0, 0, 0))
    shadow_draw = ImageDraw.Draw(shadow)
    shadow_draw.rounded_rectangle((18, 18, foreground.width + 18, foreground.height + 18), radius=24, fill=(0, 0, 0, 105))
    shadow = shadow.filter(ImageFilter.GaussianBlur(16))
    canvas.alpha_composite(shadow, (x - 18, y - 10))
    canvas.alpha_composite(foreground.convert("RGBA"), (x, y))

    draw = ImageDraw.Draw(canvas)
    draw.rectangle((0, 0, WIDTH, 124), fill=(17, 24, 39, 244))
    draw.multiline_text(
        (54, 31),
        wrap(caption, 92),
        font=font(FONT_BOLD, 36),
        fill="white",
        spacing=6,
    )
    return canvas.convert("RGB")


def encode_still(ffmpeg: str, image: Path, target: Path, duration: int) -> None:
    subprocess.run(
        [
            ffmpeg,
            "-hide_banner",
            "-loglevel",
            "error",
            "-y",
            "-loop",
            "1",
            "-t",
            str(duration),
            "-i",
            str(image),
            "-an",
            "-c:v",
            "libx264",
            "-preset",
            "medium",
            "-crf",
            "19",
            "-pix_fmt",
            "yuv420p",
            "-r",
            "30",
            str(target),
        ],
        check=True,
    )


def main() -> None:
    ffmpeg = shutil.which("ffmpeg")
    if ffmpeg is None:
        raise SystemExit("ffmpeg is required")

    missing = [name for name, _, _ in SCENES if not (SCREENSHOTS / name).exists()]
    if missing:
        raise SystemExit(f"missing screenshots: {', '.join(missing)}")

    OUTPUT.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix="mautic-webchat-video-") as temporary:
        temp = Path(temporary)
        frames: list[tuple[Image.Image, int]] = [
            (
                title_card(
                    "Mautic Realtime Web Chat",
                    "Validação end-to-end da versão 1.0\nWidget, Inbox multicanal e agente Pi/Codex",
                ),
                4,
            )
        ]
        frames.extend((screenshot_card(SCREENSHOTS / name, caption), duration) for name, caption, duration in SCENES)
        frames.append(
            (
                title_card(
                    "Fluxo validado em produção",
                    "WebSocket + fallback HTTP\nDigitação · entrega · leitura · IA · resolução",
                ),
                4,
            )
        )

        clips: list[Path] = []
        for index, (frame, duration) in enumerate(frames):
            image_path = temp / f"frame-{index:02d}.png"
            clip_path = temp / f"clip-{index:02d}.mp4"
            frame.save(image_path, optimize=True)
            encode_still(ffmpeg, image_path, clip_path, duration)
            clips.append(clip_path)

        concat = temp / "concat.txt"
        concat.write_text("".join(f"file '{clip}'\n" for clip in clips), encoding="utf-8")
        video = OUTPUT / "mautic-webchat-e2e.mp4"
        subprocess.run(
            [
                ffmpeg,
                "-hide_banner",
                "-loglevel",
                "error",
                "-y",
                "-f",
                "concat",
                "-safe",
                "0",
                "-i",
                str(concat),
                "-c",
                "copy",
                "-movflags",
                "+faststart",
                str(video),
            ],
            check=True,
        )

        cover = OUTPUT / "mautic-webchat-e2e-cover.jpg"
        frames[0][0].save(cover, quality=92, optimize=True)

    print(video)


if __name__ == "__main__":
    main()
