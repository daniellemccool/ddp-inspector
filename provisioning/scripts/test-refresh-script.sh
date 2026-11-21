#!/usr/bin/env bash
# test-refresh-script.sh — hermetic tests for the rendered refresh + probe
# scripts (no Yoda, no RD, no SRC). Renders the templates via ansible ad-hoc,
# then executes them against temp dirs with fake gocmd/rclone PATH shims
# (test-tiered-scripts.sh pattern from the transcribe repo).
set -uo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
PROV="$(dirname "$HERE")"
ANSIBLE="${PROV}/.venv/bin/ansible"
TPL="${PROV}/roles/refresh/templates"
TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

FAIL=0
check() { local desc="$1"; shift; if "$@" >/dev/null 2>&1; then echo "ok: ${desc}"; else echo "FAIL: ${desc}"; FAIL=$((FAIL+1)); fi; }

ROOT="${TMP}/vol/ddp-inspector"
mkdir -p "${ROOT}/config" "${ROOT}/data/inbox" "${ROOT}/data/analyses/transcripts" "${ROOT}/state" "${ROOT}/cache"

export FAKE_REMOTE="${TMP}/remote"   # fake iRODS collection root
mkdir -p "${FAKE_REMOTE}/coll/inbox"
export FAKE_GOCMD_LOG="${TMP}/gocmd.log"; : > "${FAKE_GOCMD_LOG}"
export FAKE_RCLONE_LOG="${TMP}/rclone.log"; : > "${FAKE_RCLONE_LOG}"
export FAKE_RD="${TMP}/rd"; mkdir -p "${FAKE_RD}"

# fake gocmd: minimal ls/sync/get subset that logs in like Yoda — the data
# access password must arrive via IRODS_USER_PASSWORD (else "Authentication
# failed", like the real server); a ticket (-T) is a hard error. It copies the
# -c dir's irods_environment.json to FAKE_GOCMD_ENV_COPY for assertions.
# FAKE_GOCMD_FAIL=1 = the server rejects the login (expired/revoked DAP).
export FAKE_YODA_PASSWORD="dap-secret"
export FAKE_GOCMD_ENV_COPY="${TMP}/gocmd-env-copy.json"
mkdir -p "${TMP}/bin"
cat > "${TMP}/bin/gocmd" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
echo "gocmd $*" >> "${FAKE_GOCMD_LOG}"
case " $* " in *" -T "*|*" --ticket "*) echo "unexpected ticket flag" >&2; exit 2;; esac
if [ "${FAKE_GOCMD_FAIL:-0}" = "1" ] || [ "${IRODS_USER_PASSWORD:-}" != "${FAKE_YODA_PASSWORD}" ]; then
  echo 'Authentication failed (auth scheme: "pam_password", username: "x", zone: "nluu10p")!' >&2; exit 1
fi
resolve() { case "$1" in i:*) printf '%s' "${FAKE_REMOTE}${1#i:}";; *) printf '%s' "$1";; esac; }
args=(); cmd=""
while [ $# -gt 0 ]; do
  case "$1" in
    -c) cp "$2/irods_environment.json" "${FAKE_GOCMD_ENV_COPY}"; shift 2 ;;
    -*)             shift ;;
    *)  if [ -z "${cmd}" ]; then cmd="$1"; else args+=("$1"); fi; shift ;;
  esac
done
case "${cmd}" in
  ls)   p="$(resolve "${args[0]}")"; [ -e "${p}" ] || { echo "not found" >&2; exit 1; }; ls "${p}" ;;
  sync) s="$(resolve "${args[0]}")"; d="$(resolve "${args[1]}")"
        if [ -d "${d}" ]; then cp -r "${s}" "${d}/"; else mkdir -p "${d}"; cp -r "${s}/." "${d}/"; fi ;;
  get)  s="$(resolve "${args[0]}")"; d="${args[1]}"; cp -r "${s}" "${d}" ;;
  *)    exit 0 ;;
esac
FAKE
chmod +x "${TMP}/bin/gocmd"

# fake rclone: lsd (probe) + sync :webdav: -> copies FAKE_RD; obscure echoes.
cat > "${TMP}/bin/rclone" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
echo "rclone $*" >> "${FAKE_RCLONE_LOG}"
[ "${FAKE_RCLONE_FAIL:-0}" = "1" ] && { echo "401 Unauthorized" >&2; exit 1; }
cmd="$1"; shift
case "${cmd}" in
  obscure) printf 'obscured-%s' "$1" ;;
  lsd)     ls "${FAKE_RD}" ;;
  sync)    # rclone sync :webdav: <dest> [flags] — copy fake RD into dest.
           # Flags that take a value (--webdav-url/--webdav-vendor/
           # --webdav-user/--webdav-pass/--include) must have their VALUE
           # skipped too, else the value (which also doesn't match
           # ':webdav:*' or '-*') gets mistaken for the destination.
           dest=""; skip_next=0
           for a in "$@"; do
             if [ "${skip_next}" = "1" ]; then skip_next=0; continue; fi
             case "${a}" in
               :webdav:*) ;;
               --webdav-url|--webdav-vendor|--webdav-user|--webdav-pass|--include) skip_next=1 ;;
               -*) ;;
               *) dest="${a}" ;;
             esac
           done
           mkdir -p "${dest}"; cp -r "${FAKE_RD}/." "${dest}/" ;;
  *)       exit 0 ;;
esac
FAKE
chmod +x "${TMP}/bin/rclone"
export PATH="${TMP}/bin:${PATH}"

render() { # render <template> <dest>
  "${ANSIBLE}" localhost -c local -m ansible.builtin.template \
    -a "src=${TPL}/$1 dest=$2 mode=0755" \
    -e "inspector_root=${ROOT}" \
    -e "gocmd_bin=gocmd" -e "rclone_bin=rclone" \
    >/dev/null
}
render ddp-refresh.sh.j2 "${TMP}/ddp-refresh.sh"
render ddp-probe.sh.j2   "${TMP}/ddp-probe.sh"

# ---- 1. unconfigured instance: exits 0, does nothing -------------------------
check "unconfigured: exits 0"                "${TMP}/ddp-refresh.sh"

# ---- 1b. unconfigured + flag: exits 0 AND flag consumed -----------------------
# Reachable via the app's setup page, which can touch refresh-requested
# before instance.json exists. Without consuming the flag here, systemd's
# PathExists= unit re-triggers on every deactivation, loops to the
# start-rate limit, and the path unit enters failed state.
touch "${ROOT}/state/refresh-requested"
check "unconfigured+flag: exits 0"           "${TMP}/ddp-refresh.sh"
check "unconfigured+flag: flag consumed"     bash -c "! test -e '${ROOT}/state/refresh-requested'"

# ---- 2. yoda happy path -------------------------------------------------------
cat > "${ROOT}/config/instance.json" <<'J'
{"study_name":"crime","source_mode":"yoda","local_path":null,"cadence":"off","default_n":15}
J
cat > "${ROOT}/config/source.json" <<'J'
{"mode":"yoda","collection":"/coll","env":{"irods_host":"yoda.example","irods_port":1247,"irods_zone_name":"nluu10p","irods_user_name":"r@uu.nl","irods_authentication_scheme":"pam_password"},"password":"dap-secret"}
J
echo '{}' > "${FAKE_REMOTE}/coll/inbox/donor1.json"
mkdir -p "${FAKE_REMOTE}/coll/transcripts-tars"
mkdir -p "${TMP}/mk/42"; echo tx > "${TMP}/mk/42/7000000000000000042.txt"
tar -C "${TMP}/mk" -cf "${FAKE_REMOTE}/coll/transcripts-tars/shard-42.tar" 42
touch "${ROOT}/state/refresh-requested"
check "yoda: run succeeds"                   "${TMP}/ddp-refresh.sh"
check "yoda: flag consumed"                  bash -c "! test -e '${ROOT}/state/refresh-requested'"
check "yoda: donation landed"                test -f "${ROOT}/data/inbox/donor1.json"
check "yoda: transcript extracted"           test -f "${ROOT}/data/analyses/transcripts/42/7000000000000000042.txt"
check "yoda: phase done"                     bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = done ]"
check "yoda: donations counted"              bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"
check "yoda: config written as pasted"      bash -c "[ \"\$(jq -r '.irods_host + \" \" + .irods_user_name' '${FAKE_GOCMD_ENV_COPY}')\" = 'yoda.example r@uu.nl' ]"
check "yoda: DAP never in gocmd argv"        bash -c "! grep -q dap-secret '${FAKE_GOCMD_LOG}'"
check "yoda: DAP never in the config file"   bash -c "! grep -q dap-secret '${FAKE_GOCMD_ENV_COPY}'"

# ---- 3. yoda login rejected (expired DAP): error status, no data touched ------
rm -rf "${ROOT}/data/inbox"; mkdir -p "${ROOT}/data/inbox"
touch "${ROOT}/state/refresh-requested"
FAKE_GOCMD_FAIL=1 "${TMP}/ddp-refresh.sh" || true
check "yoda bad login: phase error"          bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "yoda bad login: says data access password" bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'data access password'"
check "yoda bad login: inbox untouched"      bash -c "[ -z \"\$(ls -A '${ROOT}/data/inbox')\" ]"

# ---- 3b. source.json from the old anonymous-ticket design: plain error ---------
cp "${ROOT}/config/source.json" "${TMP}/source.good.json"
echo '{"mode":"yoda","collection":"/coll","host":"fsw.data.uu.nl","zone":"nluu10p","ticket":"tkt123"}' > "${ROOT}/config/source.json"
touch "${ROOT}/state/refresh-requested"
"${TMP}/ddp-refresh.sh" || true
check "legacy ticket config: phase error"    bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "legacy ticket config: asks for config" bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'Yoda configuration'"
check "legacy ticket config: gocmd not run"  bash -c "! grep -q -- '-T' '${FAKE_GOCMD_LOG}'"
cp "${TMP}/source.good.json" "${ROOT}/config/source.json"

# ---- 4. resume after interrupt: rerun completes -------------------------------
touch "${ROOT}/state/refresh-requested"
check "yoda rerun: succeeds (idempotent)"    "${TMP}/ddp-refresh.sh"
check "yoda rerun: phase done again"         bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = done ]"

# ---- 4b. yoda Next-export layout: donations/<date>_<study>.zip, no inbox/ -----
# The export zip holds one study folder with donation files (…_source=…),
# onboarding answers, and Next preview-mode test donations. Only real
# donations from the NEWEST export land, and the inbox mirrors it (a file
# left from an earlier pull — donor1.json from case 4 — must disappear).
rm -rf "${FAKE_REMOTE}/coll/inbox"
mkdir -p "${FAKE_REMOTE}/coll/donations" "${TMP}/zx/new/20260728_study" "${TMP}/zx/old/20260701_study"
DON="assignment=1_task=2_participant=p9_source=tiktok_key=9-tiktok.json"
ONB="assignment=1_participant=p9_key=9-onboarding.json"
PRV="assignment=1_task=2_participant=preview_source=tiktok_key=8-tiktok.json"
OLD="assignment=1_task=2_participant=pold_source=tiktok_key=1-tiktok.json"
for f in "${DON}" "${ONB}" "${PRV}"; do echo '{}' > "${TMP}/zx/new/20260728_study/${f}"; done
echo '{}' > "${TMP}/zx/old/20260701_study/${OLD}"
(cd "${TMP}/zx/new" && zip -qr "${FAKE_REMOTE}/coll/donations/20260728_study.zip" 20260728_study)
(cd "${TMP}/zx/old" && zip -qr "${FAKE_REMOTE}/coll/donations/20260701_study.zip" 20260701_study)
check "export: stale inbox file present before" test -f "${ROOT}/data/inbox/donor1.json"
touch "${ROOT}/state/refresh-requested"
check "export: run succeeds"                 "${TMP}/ddp-refresh.sh"
check "export: donation landed (flat)"       test -f "${ROOT}/data/inbox/${DON}"
check "export: onboarding not extracted"     bash -c "! test -e '${ROOT}/data/inbox/${ONB}'"
check "export: preview not extracted"        bash -c "! test -e '${ROOT}/data/inbox/${PRV}'"
check "export: older export ignored"         bash -c "! test -e '${ROOT}/data/inbox/${OLD}'"
check "export: inbox mirrors the export"     bash -c "! test -e '${ROOT}/data/inbox/donor1.json'"
check "export: donations counted"            bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"
check "export: zips pruned from cache"       bash -c "! ls '${ROOT}/cache/donations'/*.zip"
check "export: no staging dir left behind"   bash -c "! test -e '${ROOT}/data/.inbox-staging'"
check "export: donation readable"            test -r "${ROOT}/data/inbox/${DON}"

# ---- 4b'. Next's real export quirk: entries carry NO permission bits ------------
# (zipinfo shows ?---------; unzip would write mode-000 files). Rewrite the
# fake export with zeroed external attributes and require readable output.
python3 -I - "${FAKE_REMOTE}/coll/donations/20260728_study.zip" <<'PY'
import sys, zipfile
p = sys.argv[1]
items = [(i, zipfile.ZipFile(p).read(i.filename)) for i in zipfile.ZipFile(p).infolist()]
with zipfile.ZipFile(p, "w", zipfile.ZIP_DEFLATED) as z:
    for i, data in items:
        i.external_attr = 0
        z.writestr(i, data)
PY
rm -f "${ROOT}/data/inbox"/*.json
touch "${ROOT}/state/refresh-requested"
check "export no-perm zip: run succeeds"     "${TMP}/ddp-refresh.sh"
check "export no-perm zip: donation readable" test -r "${ROOT}/data/inbox/${DON}"
check "export: transcripts still extracted"  test -f "${ROOT}/data/analyses/transcripts/42/7000000000000000042.txt"

# ---- 4c. export with no donation files yet: done, zero donations ---------------
rm -f "${FAKE_REMOTE}/coll/donations"/*.zip
mkdir -p "${TMP}/zx/empty/20260801_study"; echo '{}' > "${TMP}/zx/empty/20260801_study/${ONB}"
(cd "${TMP}/zx/empty" && zip -qr "${FAKE_REMOTE}/coll/donations/20260801_study.zip" 20260801_study)
touch "${ROOT}/state/refresh-requested"
check "export no donations: run succeeds"    "${TMP}/ddp-refresh.sh"
check "export no donations: zero counted"    bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 0 ]"

# ---- 4d. empty donations/ folder: plain-language error --------------------------
rm -f "${FAKE_REMOTE}/coll/donations"/*.zip
touch "${ROOT}/state/refresh-requested"
"${TMP}/ddp-refresh.sh" || true
check "export missing zip: phase error"      bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "export missing zip: says export"      bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'export'"

# ---- 4e. neither inbox/ nor donations/: plain-language error --------------------
rm -rf "${FAKE_REMOTE}/coll/donations"
touch "${ROOT}/state/refresh-requested"
"${TMP}/ddp-refresh.sh" || true
check "no donations folder: phase error"     bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "no donations folder: says folder path" bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'folder path'"

# ---- 5. rd-link happy path -----------------------------------------------------
cat > "${ROOT}/config/instance.json" <<'J'
{"study_name":"insta","source_mode":"rd-link","local_path":null,"cadence":"off","default_n":15}
J
cat > "${ROOT}/config/source.json" <<'J'
{"mode":"rd-link","webdav_url":"https://researchdrive.example/public.php/webdav/","share_token":"abc123","password":"pw"}
J
echo '{}' > "${FAKE_RD}/assignment=1_task=2_participant=p1_source=instagram_key=1-instagram.json"
rm -rf "${ROOT}/data/inbox"; mkdir -p "${ROOT}/data/inbox"
touch "${ROOT}/state/refresh-requested"
check "rd-link: run succeeds"                "${TMP}/ddp-refresh.sh"
check "rd-link: donation landed"             bash -c "ls '${ROOT}/data/inbox' | grep -q instagram"
check "rd-link: password obscured"           grep -q 'obscured-pw' "${FAKE_RCLONE_LOG}"

# ---- 6. rd-link bad link: error status ------------------------------------------
touch "${ROOT}/state/refresh-requested"
FAKE_RCLONE_FAIL=1 "${TMP}/ddp-refresh.sh" || true
check "rd-link bad: phase error"             bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "rd-link bad: share-link guidance"     bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'share link'"

# ---- 7. local mode: symlink + count ----------------------------------------------
SRCDIR="${TMP}/pipeline-inbox"; mkdir -p "${SRCDIR}"
echo '{}' > "${SRCDIR}/assignment=1_task=2_participant=p2_source=tiktok_key=2-tiktok.json"
cat > "${ROOT}/config/instance.json" <<J
{"study_name":"pig","source_mode":"local","local_path":"${SRCDIR}","cadence":"off","default_n":15}
J
rm -f "${ROOT}/config/source.json"
rm -rf "${ROOT}/data/inbox"
touch "${ROOT}/state/refresh-requested"
check "local: run succeeds"                  "${TMP}/ddp-refresh.sh"
check "local: inbox is a symlink"            test -L "${ROOT}/data/inbox"
check "local: donations counted"             bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"

# ---- 7b. local re-config: symlink re-points when local_path changes -------------
# INBOX is still the symlink to SRCDIR from case 7. Switching local_path
# (while staying in local mode) must re-point the symlink, not leave it
# stale — and must never touch the old folder's contents when doing so.
SRCDIR2="${TMP}/pipeline-inbox-2"; mkdir -p "${SRCDIR2}"
echo '{}' > "${SRCDIR2}/assignment=1_task=2_participant=p3_source=youtube_key=3-youtube.json"
cat > "${ROOT}/config/instance.json" <<J
{"study_name":"pig","source_mode":"local","local_path":"${SRCDIR2}","cadence":"off","default_n":15}
J
touch "${ROOT}/state/refresh-requested"
check "local re-point: run succeeds"         "${TMP}/ddp-refresh.sh"
check "local re-point: symlink retargeted"   bash -c "[ \"\$(readlink '${ROOT}/data/inbox')\" = '${SRCDIR2}' ]"
check "local re-point: donations counted"    bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"
check "local re-point: old folder untouched" test -f "${SRCDIR}/assignment=1_task=2_participant=p2_source=tiktok_key=2-tiktok.json"

# ---- 7c. source-mode switch: local -> rd-link replaces the stale symlink --------
# INBOX is still a symlink (now into SRCDIR2). Switching to rd-link must
# strip that symlink and sync into a REAL directory — never mirror-sync
# straight through it into SRCDIR2 (which would delete files there).
SENTINEL="${SRCDIR2}/DO-NOT-TOUCH.sentinel"
echo "sentinel-content" > "${SENTINEL}"
cat > "${ROOT}/config/instance.json" <<'J'
{"study_name":"insta2","source_mode":"rd-link","local_path":null,"cadence":"off","default_n":15}
J
cat > "${ROOT}/config/source.json" <<'J'
{"mode":"rd-link","webdav_url":"https://researchdrive.example/public.php/webdav/","share_token":"abc123","password":"pw"}
J
touch "${ROOT}/state/refresh-requested"
check "switch local->rd-link: run succeeds"      "${TMP}/ddp-refresh.sh"
check "switch local->rd-link: inbox is real dir" bash -c "[ ! -L '${ROOT}/data/inbox' ] && [ -d '${ROOT}/data/inbox' ]"
check "switch local->rd-link: donation landed"   bash -c "ls '${ROOT}/data/inbox' | grep -q instagram"
check "switch local->rd-link: old folder untouched" bash -c "[ \"\$(cat '${SENTINEL}')\" = sentinel-content ]"

# ---- 8. timer tick with cadence=off and no flag: exits 0, no run -----------------
LAST="$(jq -r .started_at "${ROOT}/state/refresh-status.json")"
check "timer+off: exits 0"                   "${TMP}/ddp-refresh.sh"
check "timer+off: no new run recorded"       bash -c "[ \"\$(jq -r .started_at '${ROOT}/state/refresh-status.json')\" = '${LAST}' ]"

# ---- 9. probe script ----------------------------------------------------------------
cat > "${ROOT}/config/source.json" <<'J'
{"mode":"yoda","collection":"/coll","env":{"irods_host":"yoda.example","irods_port":1247,"irods_zone_name":"nluu10p","irods_user_name":"r@uu.nl","irods_authentication_scheme":"pam_password"},"password":"dap-secret"}
J
check "probe yoda ok"                        "${TMP}/ddp-probe.sh" yoda
check "probe yoda bad login fails"           bash -c "! FAKE_GOCMD_FAIL=1 '${TMP}/ddp-probe.sh' yoda"

echo; [ "${FAIL}" -eq 0 ] && echo "ALL PASS" || { echo "${FAIL} FAILURES"; exit 1; }
