# DDP Inspector

A read-only viewer for DDP (Data Donation Package) extracts, generalized across
donated platforms: participant → platform → tables. Renders each table faithfully —
with scope (per-table row counts, date ranges), seeded samples, and an optional
transcript view — so a researcher can verify that donations match expectations.
Table titles and descriptions are drawn from the donation flow's own export docs
when available, falling back to the raw table names.

Two ways to run it:
- **Provisioned (storage mode)** — point `config.php` at the inspector's namespace
  dir on a storage volume; a setup wizard at `/setup.php` walks through connecting
  the study (gocmd/rclone) and manages refresh cadence from there.
- **Dev mode (unchanged)** — point `config.php` at a plain DDP JSON directory, as
  before; see `./run-dev.sh` and `examples/` below.

An analyses seam lets per-row modules (e.g. the bundled transcripts module) attach
extra content to table rows without touching the core render path.

Companion to the `ddp-transcribe` pipeline; **not** part of it. See the design spec
in `docs/superpowers/specs/` and the plan in `docs/superpowers/plans/`.

Requires **PHP ≥ 8.1** (uses `array_is_list`).

## Run locally
```bash
cp config.php.example config.php   # dev mode: set ddp_dir to your DDP JSON directory
./run-dev.sh                       # http://127.0.0.1:8110
```

Or try it against the bundled synthetic dataset without touching your own config:
```bash
DDP_INSPECTOR_CONFIG=examples/config.examples.php ./run-dev.sh
```

## Test
```bash
php tests/run.php
```

## Deploy (SURF Research Cloud, behind nginx + SRAM)
See `deploy/PROVISION.md`.

Provisioning for SURF Research Cloud lives in `provisioning/` (the repo is
also the SRC catalog component — see `provisioning/README.md`).

## Notes
- Reads raw DDP JSON files directly (not the pipeline's state DB).
- TikTok comments are shown standalone — the export carries no comment↔video link.
- Transcripts are optional; unset `transcripts_dir` and the tool still works fully.
