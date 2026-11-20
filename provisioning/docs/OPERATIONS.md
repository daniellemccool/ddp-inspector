# DDP Inspector — operator runbook

Everything in this file is OPERATOR territory (SRC portal, SSH, Yoda logins).
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
| Transcript-heavy (tars + extracted ≈ 2× tree) | 2–3× the transcript tars |

Two volumes attached (e.g. a team's analyses drive alongside ours) → set
`storage_path` explicitly at launch (S10); auto-detect refuses to guess.
fuse mounts (RD-by-Link) never qualify as the storage volume.

Auto-detect reads the MOUNT TABLE under `/data/` (where SRC mounts external
volumes) — necessary because provisioning runs as the cloud user before any
SRAM user's `~/data` symlink exists (live finding 2026-07-30); the home
data-dir scan remains as a fallback for shared-mount shapes.

## 4. Yoda login (researcher's own account)

**Correction (2026-11-20):** Yoda has no anonymous login and never had.
The original design (anonymous user + read ticket, "verified 2026-07-13")
never worked: a live attempt fails with `Authentication failed (auth
scheme: "native", username: "anonymous")`. Yoda mode now logs in as the
researcher, who must be a member of the study's Yoda group.

The researcher supplies three things in Setup:

1. **Their Yoda configuration**, from the Yoda portal's data transfer
   instructions: "Download as JSON" (iCommands/iBridges
   `irods_environment.json`) or "Download as YAML" (GoCommands
   `config.yml`), uploaded, or the block pasted. It is stored unchanged
   (`irods_*` keys only, password keys dropped) as `source.json`'s `env`
   and written out as gocmd's `irods_environment.json`. No defaults: each
   Yoda instance publishes its own host, zone and auth settings (e.g.
   `pam_password` login).
2. **A data access password (DAP)** created in the Yoda portal (not the
   UU password; DAPs expire). Stored in `source.json` (0600, service user)
   and handed to gocmd only via `IRODS_USER_PASSWORD`, never in a file or
   argv. Rotation = create a new DAP, paste it in Setup.
3. **The folder path**: the full iRODS path, zone and home included
   (`/nluu10p/home/research-<group>`).

What the refresh reads under the folder:

    inbox/*.json                     loose donation files (original layout), or
    donations/<YYYYMMDD>_<study>.zip Next exports; the newest zip wins and the
                                     inbox mirrors its *_source=*.json files
                                     (no onboarding, no participant=preview)
    transcripts/                     optional, extracted transcript tree, or
    transcripts-tars/shard-*.tar     optional, pulled whole then unpacked

`inbox/` is preferred when both exist. Size the volume for the transcript
tars: all shards are pulled before any is unpacked, so the volume needs
roughly the tar total plus the unpacked tree.

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

**Site down with ERR_CONNECTION_REFUSED:** check `sudo systemctl status
nginx`. SRC-Nginx's `authentication.conf` proxies to
`gw.live.surfresearchcloud.nl`, which nginx resolves at start; a DNS hiccup
during any restart leaves nginx failed with `host not found in upstream`
(seen 2026-11-20 06:38, reported to SURF). `sudo systemctl start nginx`
recovers it. The playbook installs a `Restart=on-failure` drop-in
(`/etc/systemd/system/nginx.service.d/ddp-inspector-restart.conf`) so this
self-heals on workspaces provisioned or updated after that fix.

## 7. Validation ladder (per spec §8)

1. Sandbox item (SRC-OS/SRC-CO/SRC-External/SRC-Nginx) — hand-prototype.
2. Append the ddp-inspector component (Development) to the sandbox item —
   first integration launch; verify auto-detect ignores the RD-by-Link
   fuse mount if present.
3. First real study: yoda mode against its collection; verify one
   hand-checked participant's per-section row counts.
4. Record a validation log from day one.

Live-validation results (2026-07-30, sandbox): `gocmd sync` REJECTS `-T`
("unknown shorthand flag") in v0.12.2 — only `ls`/`get` accept tickets, so
the refresh script pulls with `get -f` everywhere (re-download, not delta
sync). SRC-Nginx on Ubuntu 24.04 and the php8.3-fpm socket path are
verified live on the deployed image.

Live-validation results (2026-11-20): Yoda login with the researcher's own
portal configuration + DAP via `IRODS_USER_PASSWORD` connects from a
workspace and fetches; a real Next export zip extracts correctly (test
participants in an export show up like any other — hide them in the UI).
The 2026-07 ticket-era results above are
client-side flag findings only — anonymous login never worked.

## 8. Updating a running workspace

The playbook is idempotent and all study configuration lives on the
volume, so updating = re-running it on the workspace from a fresh clone
(the repo is public; the roles use only `ansible.builtin`, so plain
ansible-core 2.18, SRC's version, is enough; `uv` is on SRC images):

    git clone --depth 1 --branch <branch-or-main> \
      https://github.com/daniellemccool/ddp-inspector.git /tmp/ddpi
    php /tmp/ddpi/tests/run.php            # app tests; php-cli is installed
    sudo /usr/local/bin/uv tool run --from 'ansible-core>=2.18,<2.19' \
      ansible-playbook -i 127.0.0.1, /tmp/ddpi/provisioning/deploy-ddp-inspector.yaml
    rm -rf /tmp/ddpi

Expect `failed=0`. Setup settings (data source, DAP, flows) survive. After
the run, wait until a refresh started BEFORE the update has finished
before reading the status line: it reflects the last completed run.
