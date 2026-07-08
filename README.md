# DDP Inspector

A read-only, per-participant viewer for TikTok DDP (Data Donation Package) extracts.
Renders each participant's five DDP sections faithfully — with scope (counts, date
ranges, unique videos), sampled content, and an optional transcript view — so a
researcher can verify that donations match expectations.

Companion to the `ddp-transcribe` pipeline; **not** part of it. See the design spec
in `docs/superpowers/specs/` and the plan in `docs/superpowers/plans/`.

Requires **PHP ≥ 8.1** (uses `array_is_list`).

## Run locally
```bash
cp config.php.example config.php   # set ddp_dir to your DDP JSON directory
./run-dev.sh                       # http://127.0.0.1:8110
```

## Test
```bash
php tests/run.php
```

## Deploy (SURF Research Cloud, behind nginx + SRAM)
See `deploy/PROVISION.md`.

## Notes
- Reads raw DDP JSON files directly (not the pipeline's state DB).
- Comments are shown standalone — the export carries no comment↔video link.
- Transcripts are optional; unset `transcripts_dir` and the tool still works fully.
