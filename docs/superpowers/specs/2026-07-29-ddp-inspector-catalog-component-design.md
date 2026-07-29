# DDP Inspector — SRC catalog component (design)

**Date:** 2026-07-29
**Status:** approved in brainstorm; supersedes the single-study framing of
`deploy/HANDOFF-researchcloud-ddp-transcribe.md` (decision (a) co-locate was
already obsolete; decisions (b)–(d) are resolved here)
**Companion docs:** `d3i-infra/researchcloud-ddp-transcribe/docs/storage-backends.md`
(Data-plane contract), `.../docs/catalog-item.md` (snags S1–S17),
`docs/superpowers/specs/2026-07-08-ddp-inspector-design.md` (the app v1 design)

## 1. Summary

DDP Inspector becomes a **self-contained, study-agnostic SURF Research Cloud
catalog component** (not a catalog item) that any item can include after
SRC-Nginx. Provisioning installs software and wiring only — PHP, the app, the
SRAM-gated nginx location, and refresh machinery — and knows nothing about any
study. All study-specific configuration happens **post-launch in the browser**:
a researcher uploads their flow export zip(s) and connects their donation
storage through a plain-language setup wizard. Everything the instance writes
lives in a namespaced tree on an auto-detected SRC volume, so it survives
workspace rebuilds and can share a volume with other tenants (e.g. the
ddp-transcribe pipeline).

One inspector instance serves **one study**; a study may contain **multiple
flows** (e.g. Instagram + Facebook). Multi-study means the *component* is
study-agnostic, not that one instance multiplexes studies.

## 2. Personas and the technical boundary

The boundary sits **at the browser**:

- **Operator** (D3I / research engineering): SRC portal work — assembling
  items, launching workspaces, attaching volumes, SRAM/CO administration,
  minting Yoda read tickets. CLI-comfortable; owns the runbook.
- **Researcher** (ordinary person, little technical expertise): everything
  after the workspace URL exists — setup wizard, uploading flow exports,
  pasting a Research Drive share link or an "access code", refreshing,
  viewing donations. Never sees a terminal, a ticket qua ticket, or the words
  gocmd/rclone/systemd.

Every researcher-facing error message says what to *do* next, in their terms.
No user-visible 500s on bad data, ever.

## 3. Decisions (settled this session)

| # | Decision | Choice |
|---|---|---|
| D1 | Packaging | **Component, not item.** No convenience item; the operator assembles items by hand. The component must piggyback cleanly onto mono and ddp-transcribe items. |
| D2 | Repo shape | **One self-contained repo**: `daniellemccool/ddp-inspector` = app + `provisioning/`. SRC component points at it, Tag=`main`, playbook `provisioning/deploy-ddp-inspector.yaml`. Single version pin. Transferable to d3i-infra later (GitHub redirects preserve the portal reference). |
| D3 | App delivery | Free: the component's own clone contains the app; provisioning copies it to `/opt/ddp-inspector`. |
| D4 | PHP serving | **php-fpm** + nginx `fastcgi_pass` (self-supervising; standard packaging). |
| D5 | Instance model | One instance per study; N flow exports per instance. |
| D6 | Configuration | **Web UI post-launch** (setup wizard). No study parameters, **no Co-Secrets** in the component — S17 log-censoring is structurally avoided for our component. |
| D7 | Config authorization | Same SRAM CO gate for viewing and configuring. No role model in the app; the CO is the trust boundary. Operators can narrow access per item via SRC-Nginx's `rsc_nginx_co_role` parameter (requires a specific CO role at login) — no app involvement. |
| D8 | Refresh execution | **systemd + state files.** The UI writes config and a request flag and reads status files; a systemd path/timer unit runs the pull. PHP never runs a pull in-request (probes excepted, seconds-scale). |
| D9 | Source modes | Three: **Yoda read ticket** (gocmd pull), **Research Drive share link** (wizard-entered public link, rclone sync), **mounted/local path** (advanced/operator). The RD mode adopts the *mechanism* of the "Research Drive by Link" catalog component (public link = WebDAV endpoint + share token + password) but drives it **from the wizard**, not from provision-time CO secrets; the provision-time component remains compatible (its mount is just a local path) but is not the researcher path. |
| D10 | iRODS Folder Sync component | **Skip** — one-shot, account/DAP-based, no tickets, no recurrence, no tar handling. |
| D11 | Existing-component reuse | Hard constraint honored: SRC-OS, SRC-CO, SRC-External plugin, SRC-Nginx are consumed from the catalog; the inspector component is the only new thing. |

Standing decisions inherited from 2026-07-28 (not relitigated): the inspector
is never part of DDP Transcribe; Yoda is the shared data plane with the
pipeline (volumes single-attach); access to the campaign collection is by
anonymous read ticket; there is **no extracted transcript tree on Yoda**
(server-side extraction is `MSI_OPERATION_NOT_ALLOWED`, FSW thread pending) —
the inspector pulls shard tars and extracts locally; an FSW unblock is an
optimization, never a dependency.

## 4. Component & provisioning

### Composition (all reused from the catalog)

```
SRC-OS → SRC-CO → SRC-External plugin → SRC-Nginx → ddp-inspector
```

SRC-Nginx ("Web Apps via Nginx") provides TLS on 443 and the SRAM `/validate`
endpoint. The "Research Drive by Link" component is **not** part of the
standard composition — the wizard's RD mode syncs from the share link
directly (D9), so no provision-time RD wiring exists. It may optionally be
added to a specific item when an operator prefers a provision-time mount;
the inspector then consumes it via the mounted/local-path mode.
SRC-External must precede any non-SURF component (runbook rule).
Exact ordering is re-verified against the SURF runbook during planning.
Piggybacking onto the ddp-transcribe item requires adding SRC-Nginx there
(that item is SSH-only today); components attach to *items*, not running
workspaces, so piggybacking = edit the item (S7), launch fresh.

### Roles (transcribe repo conventions throughout)

Conventions: single play `vars:` block; `roles/<name>/tasks/main.yaml` +
`templates/`; fully-qualified `ansible.builtin.*`; idempotency via
`creates:`/`changed_when`; `| bool` coercion on every SRC param; generated-file
headers; preflight asserts with actionable `fail_msg`s; SRC params arrive as
extra-vars and are resolved into differently-named facts.

- **`preflight`** —
  - Volume auto-detection: reuse the transcribe repo's `storage_root` fact
    logic (single qualifying mount under `~/data` adopted; `~/data` entries
    are symlinks to `/data` and the existing code resolves them — validated
    live 2026-07-28; `<...>` placeholder counts as unset; multiple candidates
    fail loudly asking for explicit `storage_path`). **New filter:** only
    block-device filesystems (ext4/xfs) qualify as storage volumes;
    `fuse.rclone` (and other fuse) mounts are excluded, so an RD-by-Link
    mount can coexist with the volume without tripping auto-detection.
  - Asserts: `/etc/nginx/app-location-conf.d/` exists (else fail: "add the
    SRC-Nginx component to this item before ddp-inspector"); PHP ≥ 8.1
    available from apt; resolved volume is writable.
  - **A volume is required** — there is no boot-disk fallback (config,
    exports, and pulled data must survive rebuilds). Piggyback items whose
    host needs no volume (e.g. mono) must attach one at launch; the
    `fail_msg` says so ("create a small volume in this CO and attach it at
    launch — donation-only studies need only a small one"). With two or
    more volumes attached (e.g. a team's analyses drive alongside ours),
    auto-detect refuses to guess (S10) and `storage_path` names ours
    explicitly.
- **`inspector`** — apt-installs `php-fpm` + `php-cli`; copies the app from
  the component's own clone into `/opt/ddp-inspector`; templates `config.php`
  (points at `<storage_root>/ddp-inspector/`); templates the nginx location
  (below) into `/etc/nginx/app-location-conf.d/ddp-inspector.conf` — only
  ever *adds* its own file, never touches other apps' confs — and reloads
  nginx.
- **`refresh`** — installs the gocmd and rclone binaries; installs the
  source-mode-dispatching refresh script and probe script; installs
  `ddp-refresh.service` (oneshot, runs as the workspace user),
  `ddp-refresh.path` (watches the request flag), `ddp-refresh.timer`
  (cadence read from instance config; default off).

### Nginx location (from `deploy/nginx-location.conf.example`)

```nginx
location /inspector/ {
    error_page 401 = @custom_401;
    auth_request /validate;                       # SRAM, CO members only
    auth_request_set $username $upstream_http_username;
    alias /opt/ddp-inspector/public/;
    index index.php;
    location ~ ^/inspector/(.+\.php)$ {
        include fastcgi_params;
        fastcgi_pass 127.0.0.1:9000;              # php-fpm
        fastcgi_param SCRIPT_FILENAME /opt/ddp-inspector/public/$1;
    }
}
```

App binds nothing; no inbound ports beyond SRC-Nginx's 443 (+22 from SRC-OS).
Upload size for flow-export zips needs `client_max_body_size` (set to 64m in
our location block) and matching PHP `upload_max_filesize`/`post_max_size`.

### Parameters (deliberately tiny; every one declared by hand at the component, S8)

| Parameter | Source type | Default | Notes |
|---|---|---|---|
| `inspector_base_path` | Fixed, Overwritable ✓ | `/inspector` | must match the location prefix; templated into `config.php` |
| `storage_path` | Fixed, Overwritable ✓ | `<autodetect>` placeholder | optional override, S9/S10 semantics identical to transcribe |

No secrets, no study identity, no interactive parameters required. A failed
provision log is never censored (S17 avoided by construction). Provision
failure can auto-destroy the workspace, so preflight asserts carry the whole
diagnostic story in their `fail_msg`s.

Note the persona split (§2): provisioning is entirely operator-side — with
no study parameters, nothing a researcher does can make provisioning fail —
so `fail_msg`s are operator-facing and may be technical. Every
researcher-caused failure (wrong link, expired code, malformed zip) occurs
post-launch and surfaces in the wizard/status UI in plain language (§6).

### Dev/validation vehicle (no product item)

A private sandbox item (owner CO: D3I data donation, never public):
SRC-OS → SRC-CO → SRC-External → SRC-Nginx on Ubuntu 24.04, small CPU flavour
(2C-16GB), ~50 GB boot, volume attached. Used first for hand-prototyping over
SSH, then edited (S7) to append the `ddp-inspector` component (Development
status) as its first integration test. The sandbox launch also verifies stock
SRC-Nginx works on Ubuntu 24.04 (the existence of a third-party
"SRC-nginx-Ubuntu22" fork suggests OS-version sensitivity — verify early).

## 5. Storage layout & source modes

Everything the inspector owns lives under one namespaced tree on the volume:

```
<storage_root>/ddp-inspector/
├── config/
│   ├── instance.json            # {study_name, source_mode, paths, cadence, default_n}
│   ├── source.json              # 0600: yoda {collection,host,zone,ticket}
│   │                            #    or rd-link {webdav_url,share_token,password}
│   └── flows/<platform-slug>/   # unpacked flow exports (slug from inner zip name)
│       ├── documentation.txt
│       └── build-meta.json      # commit, build id, upload timestamp
├── data/
│   ├── inbox/                   # donation JSONs (pulled/synced; local mode: symlink)
│   └── analyses/                # linking boundary (§7)
│       └── transcripts/         # NN/<id>.txt|.json extracted from shard tars
├── state/
│   ├── refresh-requested        # flag; ddp-refresh.path fires on it
│   ├── refresh-status.json      # phase, started/finished, counts, last error
│   └── refresh.log              # last run output (UI tails it)
└── cache/                       # shard-tar staging (kept for resume, pruned post-extract)
```

Config writes are atomic (write-temp + rename). `source.json` is `0600`,
owned by the service user.

### Source modes (three, converging on `data/`)

- **Yoda read ticket** — 12-line credential-free gocmd config generated from
  `source.json` (verified live 2026-07-13: `ls`/`get` with no UU account, no
  DAP, no CO membership). Refresh = adapted `yoda-sync.sh pull-resume`:
  `inbox/` synced directly; `transcripts-tars/` pulled into `cache/` and
  extracted locally into `data/analyses/transcripts/` (required path while
  the FSW extraction ban holds; if `transcripts/` later appears on Yoda the
  script prefers syncing the tree and skips tar handling).
- **Mounted/local path** — `data/inbox` becomes a symlink to a configured
  directory (an RD-by-Link mount under `/data/…`, or the pipeline volume's
  `inbox/` when piggybacked on ddp-transcribe); refresh re-checks freshness
  only. Labeled "advanced" in the UI; operator-configured setups.
- **Research Drive share link** (researcher path) — wizard-entered link +
  password; refresh script runs rclone against the public-link WebDAV
  endpoint (`https://<rd-host>/public.php/webdav`, username = share token,
  password = link password) syncing into `data/inbox/`. Read-only is enforced
  at link creation in the RD portal.

### Refresh execution

`ddp-refresh.service` (oneshot) dispatches on the configured source mode; flock
serializes timer ticks against button presses. Before touching data it
validates: `gocmd ls` probe (Yoda) or an rclone `lsd` probe (RD link); on
failure it writes an actionable status and exits without half-pulling.
`refresh-status.json` is updated at each phase transition. The same script is
SSH-runnable by the operator for debugging.

### Sizing

Donation-only studies: MBs — any volume works. The crime study: tars +
extracted tree ≈ 2× transcripts, ~60–90 GB at campaign end (S11: size
generously, check growability first). Guidance lives in the provisioning
README.

## 6. Config web UI (researcher-facing)

Same SRAM gate as all pages (D7). Unconfigured instance → all pages redirect
to setup. Wizard steps:

1. **"Add your study's donation flow(s)"** — upload the zip(s) downloaded
   from the flow builder. Validation: zip contains `documentation.txt` + one
   inner `build_*_<platform>_*.zip`; platform slug parsed from the inner
   name (matches the donation filenames' `source=` segment). Confirmation in
   researcher terms: "Instagram — 12 data tables ✓". Multiple uploads = one
   per platform in the study; re-upload replaces that platform's export.
2. **"Where are your donations stored?"** — guided choices:
   - **SURF Research Drive**: paste share link + password; inline 3-step
     instructions for creating a read-only, password-protected share link in
     the RD portal.
   - **"My data manager gave me an access code"** (Yoda): paste collection
     path + access code. Ticket minting/expiry/rotation (`gocmd mkticket -t
     read`, `modticket`, `rmticket`, `lsticket`) is strictly operator-side,
     documented in the operator runbook, never in the UI.
   - **Advanced: a folder on this workspace** (local/mounted path).
3. **"Check & fetch"** — one button: probe, then first refresh, with
   progress from `refresh-status.json`. Outcomes in plain language: "Found
   142 donations ✓" / "That link doesn't work — the share may have expired
   or the password is wrong. Create a new share link and paste it here."

Steady state: index shows "Last updated: yesterday · 142 donations" + a
"Check for new donations" button; optional daily schedule. Expired
credentials surface as a banner ("Your access code has expired — ask your
data manager for a new one"), never a broken page. Rotation = paste the new
link/code, test, save.

## 7. App generalization & the linking boundary

### Generic donation model

A donation file is a JSON list of entries, each `{"deleted row count": "N",
"<table_key>": [flat rows]}` — the port export shape, platform-independent.
Identity comes from the mono filename contract: `participant=` and `source=`
segments. The app builds participant → platform(s) → tables → rows.
Duplicate donations for the same participant+platform (abandoned-mid-session
re-runs) resolve to the newest by key timestamp, with a visible note.
`deleted row count` is surfaced as "participant removed N rows before
donating".

### documentation.txt as enrichment, never dependency

Doc sections carry human titles, descriptions, and variable tables — but no
machine table keys. Matching: a doc section maps to a donation table by
**column-set equality** (the doc's variable names are the row keys),
tie-broken by **order** (sections and tables originate from the same flow
script). Matched → researcher-worded titles + descriptions as help text.
Unmatched → prettified key ("Tiktok watch history"), fully rendered.
Upstream request (optimization, not dependency — same posture as the FSW
unblock): data-donation-task emits machine table keys in the export.

### Pages

The existing three, generalized: **index** = participants × platforms with
freshness; **participant** = per-platform sections, each table with full row
count + seeded sample of `default_n` rows (`Sample.php` semantics unchanged —
reproducible across visits); **transcript** page becomes the transcripts
analysis view. Perf envelope from the handoff §4 still governs: 9.6 MB/90k-row
donor renders in ~1.5–2 s at ~63 MB peak.

**Ground truth (regression anchor):** crime-study participant
`69f39df5fdef5b2e2a84011b` must show watch 90,466 / favorites 5,384 /
likes 2,973 / shares 55 / comments 30 — per-table row counts through the
generic path, bit-for-bit.

### Linking boundary (analyses)

An **analysis** is derived data at `data/analyses/<name>/`, linked to
donation rows by `(platform, entity-id)` where entity-id derivation is
per-analysis (transcripts: TikTok video ID parsed from the row's Link URL).
One narrow PHP interface: an analysis declares its platform scope, its
row → entity-id extraction, and content resolution/rendering from its
directory. `transcript.php` refactors into the first implementation (sharded
`NN/<id>.txt|.json`, graceful "not transcribed yet"). No plugin loader, no
registry (YAGNI) — a documented seam: future analyses = data under
`analyses/<name>/` + one module, no redesign. Invisible to researchers;
transcript links simply appear on rows that have them.

An analysis's data directory defaults to `data/analyses/<name>/` but is
overridable to any readable path (operator-side, advanced setting) — e.g. a
second attached volume where a team's external analyses already live. The
linking contract is unchanged; only the resolution root moves. The inspector
reads analysis directories strictly read-only.

### Error handling posture

Malformed donation file → "couldn't read this donation" on that participant,
rest renders. Unknown table shapes → generic rendering. Missing/partial
analyses → not-yet state. Unparseable documentation.txt → enrichment absent,
silently. Config missing → setup wizard, not an error page.

## 8. Testing & validation

- **App tests** (existing `tests/` harness, extended): generic parser
  fixtures for all four known platforms (TikTok synthetic examples + shapes
  from the ChatGPT/Facebook/Instagram exports); column-set/order matching
  incl. Facebook's near-duplicate column sets; duplicate-donation rule; §7
  ground-truth counts against `examples/`.
- **Provisioning tests** (transcribe harness patterns): Tier-1 = `yamllint`
  + `ansible-playbook --syntax-check`; Tier-2 = container double-run,
  `changed=0`; hermetic preflight replay with injected facts — including
  fstype filtering (adopt ext4/xfs volume, ignore `fuse.rclone` mount).
- **Refresh-script tests**: render units + scripts, execute against fake
  gocmd/rclone (`test-tiered-scripts.sh` pattern): ticket-invalid,
  empty-collection, resume-after-interrupt.
- **Live ladder**: sandbox item hand-prototype → component appended to
  sandbox item (first integration launch) → crime-study viewer verified
  against the §7 participant. Validation log kept from day one.

## 9. Open items

- **Operator runbook** (part of implementation): ticket minting with expiry
  (`modticket`), rotation, revocation, audits; assembling piggyback items;
  volume sizing table.
- **Upstream request** to data-donation-task: machine table keys in the flow
  export.
- **FSW extraction answer**: if server-side extraction unblocks, the Yoda
  refresh prefers the extracted tree (simplification only).
- **Verify early in sandbox**: SRC-Nginx on Ubuntu 24.04; exact component
  ordering rules per the SURF runbook.
- **Repo transfer** to a d3i-infra home: deferred; GitHub redirects keep the
  portal reference valid whenever it happens.
