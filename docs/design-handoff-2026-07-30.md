# Design handoff — DDP Inspector UI pass

*Written 2026-07-30 at the end of the provisioning/live-fetch session. The app
is functionally complete and demo-ready on the sandbox; the visual layer got
a series of quick functional patches today and needs a coherent design pass.
The transcript page in particular was called "absolutely hideous" by the
product owner — treat that as the brief's energy level.*

## What this app is

A study-blind PHP viewer for data-donation packages (DDPs), used by research
teams (currently the Crime Perceptions study) to browse participants'
donated TikTok data and read Whisper transcripts of the videos they watched.
Users are social-science researchers, not developers. It runs server-rendered
behind a SURF Research Cloud SRAM login; there is **no JS framework, no build
step, no CDN access** on the deployed box — one hand-written stylesheet and
plain PHP templates.

## Run it locally with real(istic) data

```bash
cp config.php.example config.php   # then set:
# 'ddp_dir'         => '/home/dmm/data/d3i/uu_tiktok/research-tiktok-crime-policing/pilot-tiktok-inbox',
# 'transcripts_dir' => '/home/dmm/data/d3i/uu_tiktok/inspector-demo/transcripts-enriched',
./run-dev.sh                        # php -S on http://127.0.0.1:8110
```

The enriched transcripts tree gives you real captions, uploader names,
view/like/comment counts, and pretty dates to design against. For a fuller
participant list, point `ddp_dir` at
`…/research-tiktok-crime-policing/inbox` (142 files, up to 24 MB each —
first index load computes summaries; fine locally).

`config.php` is gitignored — edit freely.

## The design surface

Four pages, one stylesheet:

| File | Page | What's on it |
|---|---|---|
| `public/index.php` | Participant list | Stats/refresh header, classified skipped-files `<details>` fold, hidden-participants filter line, table: participant / platforms / total rows / transcribed-videos coverage ("M of N") / earliest / latest |
| `public/participant.php` | One participant | Sample-size picker, untranscribed-rows toggle line, per-platform scope table, then per-table sections (h3 with row counts + reshuffle link, description/deleted-rows meta lines, sampled data table with per-row transcript links) |
| `public/transcript.php` | One video | **The problem child.** "videometa" card (caption with muted hashtags, byline `@uploader · posted date · language`, compact counts, watch-on-TikTok link), "spoken transcript · m:ss" eyebrow + serif blockquote, segment-confidence table, raw-JSON `<details>` |
| `public/setup.php` | Researcher setup wizard | Flow-zip upload, study name, source-mode radios (Research Drive / Yoda / local folder with datalist suggestions), check & fetch button, status line, technical-log fold |
| `public/assets/style.css` | Everything | ~35 lines. Georgia serif, paper background `#f9f9f7`, dark-red accent `#8a1a1a`, css vars in `:root`. Today's additions (`.videometa`, `.eyebrow`, `.transcript-block`, `.skipped` details) were bolted on without a system. |

## What the transcript page has to convey (the content model)

- The **uploader's caption** (marketing-speak, hashtags) vs the **spoken
  transcript** (Whisper output, sometimes long, sometimes empty) — two very
  different registers that today look confusingly similar.
- Metadata: `@uploader`, posted date, detected language, duration,
  view/like/comment counts (already formatted compact: `11.4M`, full value
  in `title` attrs), source URL.
- Some videos have **no metadata block** (unenriched) — the page must
  degrade without looking broken.
- Segment-confidence table (avg token probability per segment; `<0.5`
  flagged with `.low`) — a QA affordance for researchers, secondary.
- Raw JSON fold — power-user escape hatch, keep it out of the way.

## Hard constraints

1. **Self-contained**: no external fonts/CDNs (deployed box has no internet
   for browsers… it does, but keep it dependency-free anyway — single CSS
   file, system/standard fonts, no build step). Tasteful inline SVG is fine.
2. **Server-rendered PHP**: keep `h()` escaping on every output; tiny
   progressive-enhancement JS is acceptable but nothing has needed it yet.
3. **Tests grep markup.** `php tests/run.php` must stay green (260 checks).
   Assertions that pin markup/strings live in `tests/PagesTest.php` —
   notably: transcript text inside the page, `<td>Hello world</td>` segment
   cells, `'Fixture video about testing'`, `'@fixtureuser'`,
   `'posted 19 Apr 2026, 10:28'`, `'11.4M views'`/`'1.1M likes'`/`'3,181
   comments'`, `title="11,400,000"`, the `watch on TikTok` link regex
   (`rel="noreferrer noopener" target="_blank"`), skipped-notice presence,
   participant/index link hrefs. Redesigning markup is allowed — update the
   assertions honestly when you do.
4. **Formatting helpers already exist** (`src/bootstrap.php`): `fmt_compact`,
   `fmt_date_iso`, `lang_name`, `fmt_ts`; hashtag muting is a
   `preg_replace` in `transcript.php`. Reuse rather than reinvent.
5. Accessibility basics: it's a reading tool — keep contrast, focus states,
   and semantic structure honest.

## Deploying to the live sandbox (optional, after design lands)

The demo box serves `/opt/ddp-inspector/{public,src}`; deploy = merge to
`main`, then from the repo root:

```bash
rsync -aR --delete -e "ssh -i ~/.ssh/ddp-sandbox_ed25519 -o IdentitiesOnly=yes" \
  --rsync-path="sudo rsync" public src \
  dmccool@test.develop-data-do.src.surf-hosted.nl:/opt/ddp-inspector/
```

## Git state

Branch `refresh-get-ticket` (PR #6) carries today's functional work: ticketed
`get -f` refresh fix, real-scale summary cache, coverage filter + column,
participant row filter, metadata panel, and the current (disliked) styling.
Design work should branch from wherever that lands — don't redesign on a
stale base.

## The ask

A coherent visual system for the four pages — typography scale, spacing,
color, table treatment, and a transcript page where caption, metadata,
spoken text, and QA data each read instantly as what they are. The current
"academic paper" direction (serif, muted, restrained) is liked in spirit;
the execution is piecemeal. Redesign freely within the constraints above.
