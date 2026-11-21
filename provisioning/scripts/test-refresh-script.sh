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

# Needs: jq, zip, unzip, bsdtar (libarchive) on the test machine.
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

# fake rclone over FAKE_RD (the share's top folder): ":webdav:<path>" is
# FAKE_RD/<path>. obscure echoes; lsd lists the root (probe); lsf lists one
# folder like real rclone (dirs with a trailing /, --files-only and
# --include honoured); copy copies a file or folder into a local dir; sync
# mirrors the source folder's top-level files matching --include into dest.
cat > "${TMP}/bin/rclone" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
echo "rclone $*" >> "${FAKE_RCLONE_LOG}"
[ "${FAKE_RCLONE_FAIL:-0}" = "1" ] && { echo "401 Unauthorized" >&2; exit 1; }
cmd="$1"; shift
# Flags that take a value must have their VALUE skipped too, else the value
# (which matches neither ':webdav:*' nor '-*') is mistaken for a path.
pos=(); inc="*"; files_only=0
while [ $# -gt 0 ]; do
  case "$1" in
    --include) inc="$2"; shift 2 ;;
    --webdav-url|--webdav-vendor|--webdav-user|--webdav-pass|--max-depth|--contimeout) shift 2 ;;
    --files-only) files_only=1; shift ;;
    -*) shift ;;
    *) pos+=("$1"); shift ;;
  esac
done
resolve() { case "$1" in :webdav:*) p="${1#:webdav:}"; printf '%s' "${FAKE_RD}${p:+/${p}}";; *) printf '%s' "$1";; esac; }
case "${cmd}" in
  obscure) printf 'obscured-%s' "${pos[0]}" ;;
  lsd)     ls "${FAKE_RD}" ;;
  lsf)     d="$(resolve "${pos[0]}")"
           [ -d "${d}" ] || { echo "directory not found" >&2; exit 3; }
           for e in "${d}"/*; do
             [ -e "${e}" ] || continue
             n="$(basename "${e}")"
             if [ -d "${e}" ]; then [ "${files_only}" = 1 ] || echo "${n}/"
             else [[ "${n}" == ${inc} ]] && echo "${n}"; fi
           done; exit 0 ;;
  copy)    s="$(resolve "${pos[0]}")"; d="${pos[1]}"; mkdir -p "${d}"
           if [ -d "${s}" ]; then cp -r "${s}/." "${d}/"; else cp "${s}" "${d}/"; fi ;;
  sync)    s="$(resolve "${pos[0]}")"; d="${pos[1]}"; mkdir -p "${d}"
           find "${d}" -maxdepth 1 -type f -delete
           for e in "${s}"/*; do
             [ -f "${e}" ] && [[ "$(basename "${e}")" == ${inc} ]] && cp "${e}" "${d}/"
           done; exit 0 ;;
  *)       exit 0 ;;
esac
FAKE
chmod +x "${TMP}/bin/rclone"

# fake php: records the call and prints FAKE_PHP_COUNT as the count; unset or
# FAKE_PHP_FAIL=1 = the warm-up fails (the other tests rely on the file-count fallback).
export FAKE_PHP_LOG="${TMP}/php.log"; : > "${FAKE_PHP_LOG}"
cat > "${TMP}/bin/php" <<'FAKE'
#!/usr/bin/env bash
echo "php $*" >> "${FAKE_PHP_LOG}"
[ "${FAKE_PHP_FAIL:-0}" = "1" ] || [ -z "${FAKE_PHP_COUNT:-}" ] && { echo "boom" >&2; exit 1; }
echo "warming…"
echo "${FAKE_PHP_COUNT}"
FAKE
chmod +x "${TMP}/bin/php"
export PATH="${TMP}/bin:${PATH}"

render() { # render <template> <dest>
  "${ANSIBLE}" localhost -c local -m ansible.builtin.template \
    -a "src=${TPL}/$1 dest=$2 mode=0755" \
    -e "inspector_root=${ROOT}" \
    -e "gocmd_bin=gocmd" -e "rclone_bin=rclone" \
    -e "inspector_php_bin=${TMP}/bin/php" -e "inspector_install_dir=/opt/ddp-inspector" \
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
{"study_name":"example","source_mode":"yoda","local_path":null,"cadence":"off","default_n":15}
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

# ---- 2b. cache warm-up: the php shim's last line is the donation count ---------
export FAKE_PHP_COUNT=7
touch "${ROOT}/state/refresh-requested"; "${TMP}/ddp-refresh.sh" >/dev/null 2>&1
check "warm-up: shim called with the install path" grep -q "php /opt/ddp-inspector/bin/warm-cache.php" "${FAKE_PHP_LOG}"
check "warm-up: donations come from the warm-up"  bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 7 ]"
check "warm-up: phase done"                       bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = done ]"
touch "${ROOT}/state/refresh-requested"
export FAKE_PHP_FAIL=1
check "warm-up failing: run still succeeds"       "${TMP}/ddp-refresh.sh"
check "warm-up failing: phase still done"         bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = done ]"
check "warm-up failing: falls back to file count" bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"
touch "${ROOT}/state/refresh-requested"
"${TMP}/ddp-refresh.sh" >/dev/null 2>&1
check "warm-up failing: logged"                   grep -q "cache warm-up failed" "${ROOT}/state/refresh.log"
unset FAKE_PHP_FAIL FAKE_PHP_COUNT

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
# (zipinfo shows ?---------; unzip writes mode-000 files). Rebuild the fake
# export with mode-0000 entries (bsdtar + an mtree spec sets the stored mode
# exactly) and require readable output.
printf '#mtree\n./20260728_study/%s type=file mode=0000 contents=%s\n' \
  "${DON}" "${TMP}/zx/new/20260728_study/${DON}" \
  | bsdtar --format zip -cf "${FAKE_REMOTE}/coll/donations/20260728_study.zip" @-
check "export no-perm zip: entry stored mode 0000" bash -c "zipinfo '${FAKE_REMOTE}/coll/donations/20260728_study.zip' | grep -q '^-\\{10\\} '"
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

# ---- 6b. rd-link share of the study's top folder: donations/<date>_<study>.zip --
# No donation files at the share's root (a .json deeper down doesn't count):
# only the NEWEST export is copied, and the inbox mirrors its donations (the
# instagram file from case 5 must disappear).
RD_KEEP="${FAKE_RD}"
export FAKE_RD="${TMP}/rd-study-zip"
mkdir -p "${FAKE_RD}/donations" "${FAKE_RD}/analysis"
(cd "${TMP}/zx/new" && zip -qr "${FAKE_RD}/donations/20260728_study.zip" 20260728_study)
(cd "${TMP}/zx/old" && zip -qr "${FAKE_RD}/donations/20260701_study.zip" 20260701_study)
echo '{}' > "${FAKE_RD}/analysis/notes.json"
: > "${FAKE_RCLONE_LOG}"
touch "${ROOT}/state/refresh-requested"
check "rd-link export: run succeeds"         "${TMP}/ddp-refresh.sh"
check "rd-link export: donation landed"      test -r "${ROOT}/data/inbox/${DON}"
check "rd-link export: onboarding not extracted" bash -c "! test -e '${ROOT}/data/inbox/${ONB}'"
check "rd-link export: preview not extracted" bash -c "! test -e '${ROOT}/data/inbox/${PRV}'"
check "rd-link export: older export ignored" bash -c "! test -e '${ROOT}/data/inbox/${OLD}'"
check "rd-link export: inbox mirrors the export" bash -c "! ls '${ROOT}/data/inbox' | grep -q instagram"
check "rd-link export: deeper json not pulled" bash -c "! test -e '${ROOT}/data/inbox/notes.json'"
check "rd-link export: donations counted"    bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"
check "rd-link export: only the newest copied" bash -c "grep -q 'copy :webdav:donations/20260728_study.zip' '${FAKE_RCLONE_LOG}' && ! grep -q 20260701 '${FAKE_RCLONE_LOG}'"
check "rd-link export: zip pruned from cache" bash -c "! ls '${ROOT}/cache/donations'/*.zip"
check "rd-link export: no staging dir left"  bash -c "! test -e '${ROOT}/data/.inbox-staging'"

# ---- 6c. rd-link share of the study's top folder: inbox/*.json ----------------
# inbox/ wins over donations/ (as on Yoda); the inbox mirrors it.
export FAKE_RD="${TMP}/rd-study-inbox"
mkdir -p "${FAKE_RD}/inbox" "${FAKE_RD}/donations"
echo '{}' > "${FAKE_RD}/inbox/assignment=1_task=2_participant=p5_source=youtube_key=5-youtube.json"
echo 'notes' > "${FAKE_RD}/README.txt"
(cd "${TMP}/zx/old" && zip -qr "${FAKE_RD}/donations/20260701_study.zip" 20260701_study)
touch "${ROOT}/state/refresh-requested"
check "rd-link inbox/: run succeeds"         "${TMP}/ddp-refresh.sh"
check "rd-link inbox/: donation landed"      bash -c "ls '${ROOT}/data/inbox' | grep -q participant=p5"
check "rd-link inbox/: inbox mirrors inbox/" bash -c "! test -e '${ROOT}/data/inbox/${DON}'"
check "rd-link inbox/: export not used"      bash -c "! test -e '${ROOT}/data/inbox/${OLD}'"
check "rd-link inbox/: donations counted"    bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"

# ---- 6d. rd-link donations/ without an export: plain-language error ------------
export FAKE_RD="${TMP}/rd-study-noexport"; mkdir -p "${FAKE_RD}/donations"
touch "${ROOT}/state/refresh-requested"
"${TMP}/ddp-refresh.sh" || true
check "rd-link no export: phase error"       bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "rd-link no export: says export"       bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'export'"

# ---- 6e. rd-link share with no donations anywhere: plain-language error --------
export FAKE_RD="${TMP}/rd-study-empty"; mkdir -p "${FAKE_RD}/analysis"; echo x > "${FAKE_RD}/README.txt"
touch "${ROOT}/state/refresh-requested"
"${TMP}/ddp-refresh.sh" || true
check "rd-link nothing: phase error"         bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "rd-link nothing: says no donations"   bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'no donations were found'"
check "rd-link nothing: says study's folder" bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi \"study's folder\""
export FAKE_RD="${RD_KEEP}"

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

# ---- 7d. local study folder with inbox/*.json: inbox links to <folder>/inbox -----
# INBOX is a real dir again (case 7c). No donation files at the folder's root.
LSTUDY="${TMP}/study-with-inbox"; mkdir -p "${LSTUDY}/inbox" "${LSTUDY}/analysis"
echo '{}' > "${LSTUDY}/inbox/assignment=1_task=2_participant=p6_source=tiktok_key=6-tiktok.json"
echo '{}' > "${LSTUDY}/analysis/notes.json"
local_cfg() { printf '{"study_name":"pig","source_mode":"local","local_path":"%s","cadence":"off","default_n":15}\n' "$1" > "${ROOT}/config/instance.json"; }
local_cfg "${LSTUDY}"; rm -f "${ROOT}/config/source.json"
touch "${ROOT}/state/refresh-requested"
check "local inbox/: run succeeds"           "${TMP}/ddp-refresh.sh"
check "local inbox/: inbox links to inbox/"  bash -c "[ \"\$(readlink '${ROOT}/data/inbox')\" = '${LSTUDY}/inbox' ]"
check "local inbox/: donation counted"       bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"

# ---- 7e. local study folder with donations/<date>_<study>.zip ------------------
# INBOX is a symlink into LSTUDY/inbox (case 7d). The newest export is
# unpacked into a REAL inbox dir: never through that symlink, and nothing in
# either configured folder is created, changed or removed.
LZIP="${TMP}/study-with-export"; mkdir -p "${LZIP}/donations"
(cd "${TMP}/zx/new" && zip -qr "${LZIP}/donations/20260728_study.zip" 20260728_study)
(cd "${TMP}/zx/old" && zip -qr "${LZIP}/donations/20260701_study.zip" 20260701_study)
snap() { find "$1" -printf '%p %s %T@ %m\n' | sort; }
snap "${LZIP}" > "${TMP}/lzip.before"; snap "${LSTUDY}" > "${TMP}/lstudy.before"
local_cfg "${LZIP}"
touch "${ROOT}/state/refresh-requested"
check "local export: run succeeds"           "${TMP}/ddp-refresh.sh"
check "local export: inbox is a real dir"    bash -c "[ ! -L '${ROOT}/data/inbox' ] && [ -d '${ROOT}/data/inbox' ]"
check "local export: donation landed"        test -r "${ROOT}/data/inbox/${DON}"
check "local export: preview not extracted"  bash -c "! test -e '${ROOT}/data/inbox/${PRV}'"
check "local export: older export ignored"   bash -c "! test -e '${ROOT}/data/inbox/${OLD}'"
check "local export: donations counted"     bash -c "[ \"\$(jq -r .donations '${ROOT}/state/refresh-status.json')\" = 1 ]"
check "local export: folder untouched"       bash -c "snap() { find \"\$1\" -printf '%p %s %T@ %m\n' | sort; }; snap '${LZIP}' | cmp -s - '${TMP}/lzip.before'"
check "local export: old link target untouched" bash -c "snap() { find \"\$1\" -printf '%p %s %T@ %m\n' | sort; }; snap '${LSTUDY}' | cmp -s - '${TMP}/lstudy.before'"
check "local export: no staging dir left"    bash -c "! test -e '${ROOT}/data/.inbox-staging'"

# ---- 7f. back to a folder holding donation files itself: symlink again ---------
local_cfg "${SRCDIR}"
touch "${ROOT}/state/refresh-requested"
check "local root json: run succeeds"        "${TMP}/ddp-refresh.sh"
check "local root json: inbox links to folder" bash -c "[ \"\$(readlink '${ROOT}/data/inbox')\" = '${SRCDIR}' ]"

# ---- 7g. local folder with no donations anywhere: plain-language error ---------
LEMPTY="${TMP}/study-empty"; mkdir -p "${LEMPTY}/analysis"
local_cfg "${LEMPTY}"
touch "${ROOT}/state/refresh-requested"
"${TMP}/ddp-refresh.sh" || true
check "local nothing: phase error"           bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "local nothing: says no donations"     bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'no donations were found'"
check "local nothing: folder still empty"    bash -c "[ \"\$(find '${LEMPTY}' | wc -l)\" = 2 ]"
local_cfg "${SRCDIR2}"

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
local_cfg "${SRCDIR}"
check "probe local root json counts"         bash -c "'${TMP}/ddp-probe.sh' local | grep -q '(1 donation files)'"
local_cfg "${LSTUDY}"
check "probe local inbox/ counts"            bash -c "'${TMP}/ddp-probe.sh' local | grep -q '(1 donation files)'"
local_cfg "${LZIP}"
check "probe local export mentioned"         bash -c "'${TMP}/ddp-probe.sh' local | grep -qi 'export'"
local_cfg "${TMP}/no-such-folder"
check "probe local missing fails"            bash -c "! '${TMP}/ddp-probe.sh' local"

echo; [ "${FAIL}" -eq 0 ] && echo "ALL PASS" || { echo "${FAIL} FAILURES"; exit 1; }
