#!/usr/bin/env python3
"""Generate the synthetic example dataset in examples/ddp and examples/transcripts.

Deterministic (seeded): re-running reproduces the same files byte-for-byte.
Row shapes, key order, date formats, and file naming mirror real TikTok DDP
extracts as produced by the d3i donation flow.
"""
import json
import random
from datetime import datetime, timedelta
from pathlib import Path

HERE = Path(__file__).resolve().parent
DDP_DIR = HERE / "ddp"
TRANSCRIPTS_DIR = HERE / "transcripts"

rng = random.Random(20260718)

SHARE_METHODS = ["chat_head", "sms", "facebook", "snapchat_chats"]

COMMENT_OPENERS = [
    "no way this happened in my town",
    "the way the officer just walks past the camera",
    "my neighbour swears she saw this live",
    "this is the third one this month",
    "posting this before it gets taken down",
    "the council meeting about this was wild",
    "respect to the bystander who filmed calmly",
    "our street had the same thing last winter",
    "local news is so late on this",
    "can someone confirm which precinct this is",
    "the dispatcher audio makes it so much clearer",
    "this deserves way more coverage",
    "watched this three times and still confused",
    "the body cam angle tells a different story",
    "grateful nobody got hurt here",
]
COMMENT_CLOSERS = [
    "",
    " 😳",
    " fr",
    " ngl",
    " — following for updates",
    " (source in bio? anyone?)",
    " stay safe out there",
    " 2/2",
]


def video_id() -> str:
    return "7" + "".join(str(rng.randint(0, 9)) for _ in range(18))


def hex_id() -> str:
    return "".join(rng.choice("0123456789abcdef") for _ in range(24))


def fmt(ts: datetime) -> str:
    return ts.strftime("%Y-%m-%d %H:%M:%S")


def times(n: int, start: datetime, end: datetime) -> list[datetime]:
    span = (end - start).total_seconds()
    stamps = sorted(rng.uniform(0, span) for _ in range(n))
    return [start + timedelta(seconds=round(s)) for s in stamps]


def link(vid: str) -> str:
    return f"https://www.tiktokv.com/share/video/{vid}/"


def make_participant(n_watch, n_fav, n_like, n_share, n_comment,
                     start, end, pool, deleted="0", quirks=False):
    """Build the five DDP sections. `pool` is a shared video-id pool so ids
    overlap across sections and participants (exercises unique-video count)."""
    def pick_vid():
        if pool and rng.random() < 0.6:
            return rng.choice(pool)
        vid = video_id()
        pool.append(vid)
        return vid

    watch = [{"Date": fmt(t), "Link": link(pick_vid())}
             for t in times(n_watch, start, end)]
    fav = [{"Date": fmt(t), "Link": link(pick_vid())}
           for t in times(n_fav, start, end)]
    like = [{"Date": fmt(t), "Link": link(pick_vid())}
            for t in times(n_like, start, end)]
    share = [{"Date": fmt(t), "SharedContent": "share_video",
              "Link": link(pick_vid()), "Method": rng.choice(SHARE_METHODS)}
             for t in times(n_share, start, end)]
    comments = [{"Date": fmt(t) + " UTC",
                 "Comment": rng.choice(COMMENT_OPENERS) + rng.choice(COMMENT_CLOSERS)}
                for t in times(n_comment, start, end)]

    if quirks and watch:
        # Real exports occasionally carry oddities; keep one of each so the
        # inspector's fallback paths stay visible in the example.
        watch[0]["Date"] = "not a date"
        watch[1]["Link"] = "https://www.tiktok.com/@examplecreator/video/" + pick_vid()
        watch[2]["Link"] = "https://www.tiktokv.com/share/music/123456/"

    return [
        {"deleted row count": deleted, "tiktok_watch_history": watch},
        {"deleted row count": "0", "tiktok_favorite_videos": fav},
        {"deleted row count": "0", "tiktok_like_list": like},
        {"deleted row count": "0", "tiktok_share_history": share},
        {"deleted row count": "0", "tiktok_comments": comments},
    ]


def write_ddp(sections, key_ms):
    pid = hex_id()
    name = (f"assignment=406_task=954_participant={pid}"
            f"_source=tiktok_key={key_ms}-tiktok.json")
    (DDP_DIR / name).write_text(
        json.dumps(sections, ensure_ascii=False, indent=1) + "\n")
    return name


def write_transcript(vid, txt=None, meta=None):
    shard = TRANSCRIPTS_DIR / vid[-2:]
    shard.mkdir(parents=True, exist_ok=True)
    if txt is not None:
        (shard / f"{vid}.txt").write_text(txt + "\n")
    if meta is not None:
        (shard / f"{vid}.json").write_text(
            json.dumps(meta, ensure_ascii=False, indent=1) + "\n")


def transcript_meta(vid, sentences, low_confidence=False):
    segments = []
    for i, sentence in enumerate(sentences):
        words = sentence.split(" ")
        tokens = [{"id": 50365, "text": "[_BEG_]", "p": 0.62, "plog": -0.478}]
        for w, word in enumerate(words):
            p = round(rng.uniform(0.15, 0.45) if low_confidence
                      else rng.uniform(0.72, 0.99), 4)
            tokens.append({"id": 1000 + i * 50 + w,
                           "text": word if w == 0 else " " + word,
                           "p": p, "plog": round(rng.uniform(-0.9, -0.01), 4)})
        tokens.append({"id": 50579, "text": f"[_TT_{200 + i}]",
                       "p": 0.51, "plog": -0.67})
        segments.append({"no_speech_prob": rng.uniform(1e-12, 1e-9),
                         "tokens": tokens})
    return {
        "video_id": vid,
        "source_url": link(vid),
        "duration_s": round(rng.uniform(8.0, 62.0), 1),
        "language_detected": "en",
        "transcribed_at": "2026-07-10T09:30:00Z",
        "fetcher": "ytdlp",
        "transcript_source": "whisper-rs",
        "model": "small",
        "raw_signals": {"schema_version": "1", "language": "en",
                        "lang_probs": None, "segments": segments},
    }


def main():
    DDP_DIR.mkdir(parents=True, exist_ok=True)
    TRANSCRIPTS_DIR.mkdir(parents=True, exist_ok=True)
    for old in DDP_DIR.glob("*.json"):
        old.unlink()

    start = datetime(2026, 1, 5, 6, 0, 0)
    end = datetime(2026, 7, 12, 23, 45, 0)
    pool: list[str] = []

    heavy = make_participant(2500, 180, 900, 60, 120, start, end, pool)
    light = make_participant(400, 12, 80, 5, 8,
                             datetime(2026, 4, 1, 8, 0, 0), end, pool)
    edge = make_participant(60, 0, 25, 3, 10, start, end, pool,
                            deleted="3", quirks=True)

    files = [write_ddp(heavy, 1783300000001),
             write_ddp(light, 1783300000002),
             write_ddp(edge, 1783300000003)]

    # Transcripts for a handful of watched videos: the common case (txt+json),
    # a txt-only, a json-only, a low-confidence one, and a pre-Epic-1 artifact
    # without raw_signals. Everything else renders as "Not transcribed yet."
    vids = [row["Link"].rstrip("/").rsplit("/", 1)[-1]
            for row in heavy[0]["tiktok_watch_history"][:40]]
    vids = list(dict.fromkeys(vids))[:5]

    write_transcript(vids[0],
                     txt="Tonight on the neighbourhood watch update: the bridge "
                         "closure, the missing bike that was never missing, and "
                         "why the community meeting ran long again.",
                     meta=transcript_meta(vids[0], [
                         "Tonight on the neighbourhood watch update",
                         "the bridge closure and the missing bike",
                         "and why the community meeting ran long again",
                     ]))
    write_transcript(vids[1],
                     txt="Quick explainer: what actually happens after you file "
                         "a report at the local station, step by step.")
    write_transcript(vids[2],
                     meta=transcript_meta(vids[2], [
                         "what actually happens after you file a report",
                         "step one talk to the desk officer",
                     ]))
    write_transcript(vids[3],
                     txt="muffled audio near the parade, hard to make out.",
                     meta=transcript_meta(vids[3], [
                         "muffled audio near the parade hard to make out",
                     ], low_confidence=True))
    write_transcript(vids[4],
                     txt="Archived clip from the winter storm coverage.",
                     meta={"video_id": vids[4], "source_url": link(vids[4]),
                           "duration_s": 21.0, "language_detected": "en",
                           "transcribed_at": "2026-05-02T14:00:00Z",
                           "fetcher": "ytdlp", "transcript_source": "whisper-rs",
                           "model": "small"})

    for name in files:
        print("wrote", name)
    print("transcripts for:", ", ".join(vids))


if __name__ == "__main__":
    main()
