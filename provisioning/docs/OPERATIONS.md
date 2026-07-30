# DDP Inspector — operator runbook

Everything in this file is OPERATOR territory (SRC portal, SSH, tickets).
Researchers never need any of it: their surface is the web UI. Snag numbers
(S7, S8, …) refer to the transcribe repo's
`d3i-infra/researchcloud-ddp-transcribe/docs/catalog-item.md` §5.

## 1. Portal registration (component)

- Portal → Development → Components → Add component.
- Repository: `https://github.com/daniellemccool/ddp-inspector.git`
- Path: `provisioning/deploy-ddp-inspector.yaml`
- Tag: `main` (single version pin — the repo is app + provisioning, D2).
- Owner CO: D3I data donation (permanent).
- Declare BOTH parameters by hand at the component (S8 — undeclared params
  silently do nothing):

| Parameter | Source type | Overwritable | Default |
|---|---|---|---|
| `inspector_base_path` | Fixed | ✓ | `/inspector` |
| `storage_path` | Fixed | ✓ | `/home/<username>/data/<volume-name>` (placeholder = auto-detect, S9) |

- No secrets, no study identity, no interactive parameters. A failed
  provision log is never censored (S17 avoided by construction).
- Edit the component in place forever after (S7 — recreating orphans every
  item reference).

## 2. Assembling items (the component is the product — no product item)

Component order in ANY item that includes the inspector:

    SRC-OS → SRC-CO → SRC-Nginx → SRC-External plugin → (externals: … → ddp-inspector)

- External (non-SURF) components ALL execute at the SRC-External plugin's
  slot, in their item order among themselves — a component's own list
  position does NOT delay it past later SURF components (verified live
  2026-07-30: an item listing SRC-Nginx after SRC-External never ran nginx
  before the inspector, and preflight aborted the launch). So SRC-Nginx
  must be LISTED before SRC-External plugin; ddp-inspector goes last among
  the externals (preflight fails otherwise, naming the fix).
- "Research Drive by Link" is OPTIONAL and not the researcher path (D9);
  add it only when an operator prefers a provision-time mount, and use the
  app's "folder on this workspace" mode pointed at `/data/<mountdir>`.
- Piggyback notes:
  - **mono items**: mono needs no volume, the inspector DOES — attach a
    small one at launch or preflight fails (by design; no boot-disk
    fallback).
  - **ddp-transcribe items**: add SRC-Nginx (that item is SSH-only today).
    The inspector shares the pipeline volume safely — everything it writes
    lives under `<volume>/ddp-inspector/`. For a co-located viewer, use the
    app's local mode pointed at the pipeline's `<volume>/inbox`.
  - Components attach to ITEMS, not running workspaces — piggybacking means
    editing the item and launching fresh.
- Firewall: item defaults (22/80/443) suffice; everything the inspector
  runs binds localhost. 3389 open with nothing listening is S15 — leave it.
- Access button: declare a component-level access format — label
  `DDP Inspector`, format `https://==REVERSE_PROXY==/inspector/` — so the
  workspace card deep-links into the app (the domain root serves 403; no
  root location exists). Static text: if an item overrides
  `inspector_base_path`, update the format to match.

## 3. Volumes

Create in the LAUNCHING CO (portal → CREATE NEW → Storage), attach at the
launch wizard's storage step. Sizing (S11 — size generously, check
growability first):

| Study shape | Volume |
|---|---|
| Donation-only (mono studies; MBs of JSON) | 10 GB |
| Crime-policing (transcripts: tars + extracted ≈ 2× tree) | 60–90 GB at campaign end |

Two volumes attached (e.g. a team's analyses drive alongside ours) → set
`storage_path` explicitly at launch (S10); auto-detect refuses to guess.
fuse mounts (RD-by-Link) never qualify as the storage volume.

Auto-detect reads the MOUNT TABLE under `/data/` (where SRC mounts external
volumes) — necessary because provisioning runs as the cloud user before any
SRAM user's `~/data` symlink exists (live finding 2026-07-30); the home
data-dir scan remains as a fallback for shared-mount shapes.

## 4. Yoda read tickets (the "access code")

Minted by the data manager on a machine with an authenticated gocmd
(e.g. the pipeline workspace or an operator laptop after `gocmd init`):

    gocmd mkticket -t read /nluu10p/home/<collection>     # mint
    gocmd modticket <ticket> --expiry 2026-09-01          # MANDATORY on real hand-offs
    gocmd lsticket                                        # audit
    gocmd rmticket <ticket>                               # revoke

Defaults are permissive (unlimited uses, NO expiry) — never hand off a
ticket without `modticket` an expiry. Hand the researcher ONLY the ticket
string + collection path; they paste both into the app's Setup. Rotation =
mint new, paste new, `rmticket` old. The workspace never holds a Yoda
account, DAP, or CO membership (anonymous ticket access, verified
2026-07-13).

## 5. Research Drive share links

Researcher-self-serve in the RD web portal: share the donations folder by
link, READ-ONLY, with a password. The app's Setup asks for the link and
password and syncs via WebDAV — no CO secrets, no provision-time wiring.
Rotation = create a new link, paste it in Setup.

## 6. Debugging a refresh

Operator path (all researcher-visible state lives on the volume):

    <storage_root>/ddp-inspector/state/refresh-status.json   # what the UI shows
    <storage_root>/ddp-inspector/state/refresh.log           # full last-run log
    sudo systemctl status ddp-refresh.service                # unit-level view
    sudo -u www-data /opt/ddp-inspector/bin/ddp-refresh.sh   # manual run, same env
    sudo -u www-data /opt/ddp-inspector/bin/ddp-probe.sh yoda|rd-link|local

The refresh is flock-serialized and idempotent — re-running after any
failure is always safe.

## 7. Validation ladder (per spec §8)

1. Sandbox item (SRC-OS/SRC-CO/SRC-External/SRC-Nginx) — hand-prototype.
2. Append the ddp-inspector component (Development) to the sandbox item —
   first integration launch; verify auto-detect ignores the RD-by-Link
   fuse mount if present.
3. Crime-study viewer: yoda mode against the campaign collection; verify
   participant `69f39df5fdef5b2e2a84011b` shows watch 90,466 /
   favorites 5,384 / likes 2,973 / shares 55 / comments 30.
4. Record a validation log from day one.

Live-validation results (2026-07-30, sandbox): `gocmd sync` REJECTS `-T`
("unknown shorthand flag") in v0.12.2 — only `ls`/`get` accept tickets, so
the refresh script pulls with `get -f` everywhere (re-download, not delta
sync). SRC-Nginx on Ubuntu 24.04 and the php8.3-fpm socket path are
verified live on the deployed image.
