import asyncio
import logging
import re
import shutil
import tempfile
from dataclasses import dataclass
from pathlib import Path
from urllib.parse import urlparse

import yt_dlp

logger = logging.getLogger(__name__)

SUPPORTED_DOMAINS = {
    "tiktok.com", "vm.tiktok.com", "vt.tiktok.com",
    "instagram.com", "instagr.am",
    "youtube.com", "youtu.be", "m.youtube.com",
    "twitter.com", "x.com", "mobile.twitter.com",
    "facebook.com", "fb.watch", "fb.com", "m.facebook.com",
}

URL_RE = re.compile(r"https?://\S+", re.IGNORECASE)


def extract_url(text: str) -> str | None:
    match = URL_RE.search(text or "")
    return match.group(0).rstrip(".,);]") if match else None


def is_supported_url(url: str) -> bool:
    try:
        host = (urlparse(url).hostname or "").lower()
        if host.startswith("www."):
            host = host[4:]
        return any(
            host == d or host.endswith("." + d) for d in SUPPORTED_DOMAINS
        )
    except Exception:
        return False


@dataclass
class DownloadResult:
    file_path: Path
    work_dir: Path
    title: str
    uploader: str | None
    duration: int | None
    size: int


class DownloadError(Exception):
    pass


def _run_ytdlp(url: str, work_dir: Path, max_size_bytes: int) -> DownloadResult:
    outtmpl = str(work_dir / "%(id)s.%(ext)s")
    ydl_opts = {
        "outtmpl": outtmpl,
        "format": (
            f"best[filesize<{max_size_bytes}]/"
            f"bestvideo[filesize<{max_size_bytes}]+bestaudio/"
            f"best"
        ),
        "noplaylist": True,
        "quiet": True,
        "no_warnings": True,
        "max_filesize": max_size_bytes,
        "merge_output_format": "mp4",
        "concurrent_fragment_downloads": 4,
        "retries": 3,
    }

    with yt_dlp.YoutubeDL(ydl_opts) as ydl:
        info = ydl.extract_info(url, download=True)
        if info is None:
            raise DownloadError("Failed to extract media information.")

        candidate = Path(ydl.prepare_filename(info))
        file_path = candidate
        if not file_path.exists():
            mp4_variant = candidate.with_suffix(".mp4")
            if mp4_variant.exists():
                file_path = mp4_variant
            else:
                matches = list(work_dir.glob(f"{candidate.stem}.*"))
                if not matches:
                    raise DownloadError("Downloaded file not found.")
                file_path = matches[0]

        size = file_path.stat().st_size
        if size > max_size_bytes:
            raise DownloadError(
                f"File too large: {size / (1024 * 1024):.1f} MB"
            )

        return DownloadResult(
            file_path=file_path,
            work_dir=work_dir,
            title=(info.get("title") or "video")[:200],
            uploader=info.get("uploader"),
            duration=info.get("duration"),
            size=size,
        )


async def download(
    url: str, max_size_bytes: int, timeout: int
) -> DownloadResult:
    work_dir = Path(tempfile.mkdtemp(prefix="ytdl_"))
    loop = asyncio.get_running_loop()
    try:
        return await asyncio.wait_for(
            loop.run_in_executor(None, _run_ytdlp, url, work_dir, max_size_bytes),
            timeout=timeout,
        )
    except asyncio.TimeoutError:
        shutil.rmtree(work_dir, ignore_errors=True)
        raise DownloadError("Download timed out.")
    except yt_dlp.utils.DownloadError as e:
        shutil.rmtree(work_dir, ignore_errors=True)
        raise DownloadError(_clean_ytdlp_error(str(e))) from e
    except DownloadError:
        shutil.rmtree(work_dir, ignore_errors=True)
        raise
    except Exception as e:
        shutil.rmtree(work_dir, ignore_errors=True)
        logger.exception("Unexpected download error")
        raise DownloadError(f"Unexpected error: {e}") from e


def cleanup(result: DownloadResult) -> None:
    shutil.rmtree(result.work_dir, ignore_errors=True)


def _clean_ytdlp_error(msg: str) -> str:
    msg = msg.replace("ERROR: ", "").strip()
    if "Unsupported URL" in msg:
        return "هذا الرابط غير مدعوم."
    if "Private video" in msg or "login" in msg.lower():
        return "هذا المحتوى خاص أو يتطلب تسجيل دخول."
    if "Video unavailable" in msg:
        return "الفيديو غير متاح."
    if "geo" in msg.lower() or "region" in msg.lower():
        return "هذا المحتوى محجوب جغرافياً."
    return msg[:300]
