# DDP Inspector

A read-only viewer for DDP (Data Donation Package) extracts, generalized across
donated platforms: participant → platform → tables. Renders each table faithfully —
with scope (per-table row counts, date ranges), seeded samples, and an optional
transcript view — so a researcher can verify that donations match expectations.
Table titles and descriptions are drawn from the donation flow's own documentation
when available, falling back to the raw table names.

A **Study** page shows the study's documentation, from two optional uploads:
- the **study setup**, exported from [Next](https://next.eyra.co/) (study page,
  ⋮ → Export): what participants saw: information pages, consent, instructions
  and tasks;
- the **donation flow** from the
  [script selector](https://datadonation.eu/software/script-selector/), one per
  platform: what was collected: the tables and what each variable means.

The **donations** themselves (what participants gave) come from Research Drive,
Yoda or a folder on the workspace.

Two ways to run it:
- **Provisioned (storage mode)** — point `config.php` at the inspector's namespace
  dir on a storage volume; Setup at `/setup.php` has three steps: describe the
  study (its name, the study setup, the donation flows; all optional), connect
  the donations (gocmd/rclone), and fetch (now, or every day).
- **Dev mode (unchanged)** — point `config.php` at a plain DDP JSON directory, as
  before; see `./run-dev.sh` and `examples/` below.

An analyses seam lets per-row modules (e.g. the bundled transcripts module) attach
extra content to table rows without touching the core render path.

Companion to the `ddp-transcribe` pipeline; **not** part of it.

Requires **PHP ≥ 8.1** (uses `array_is_list`), with the `sqlite3` and `zip`
extensions (`zip` reads the study setup; the tests need it too).

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
