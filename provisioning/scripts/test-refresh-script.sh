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

# fake gocmd: minimal ls/sync/get subset; honors -T (ticket) and -c (config
# dir) by ignoring them; FAKE_GOCMD_FAIL=1 makes every op fail (bad ticket).
mkdir -p "${TMP}/bin"
cat > "${TMP}/bin/gocmd" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
echo "gocmd $*" >> "${FAKE_GOCMD_LOG}"
[ "${FAKE_GOCMD_FAIL:-0}" = "1" ] && { echo "CAT_TICKET_INVALID" >&2; exit 1; }
resolve() { case "$1" in i:*) printf '%s' "${FAKE_REMOTE}${1#i:}";; *) printf '%s' "$1";; esac; }
args=(); cmd=""
while [ $# -gt 0 ]; do
  case "$1" in
    -T|-c|--ticket) shift 2 ;;
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

# ---- 2. yoda happy path -------------------------------------------------------
cat > "${ROOT}/config/instance.json" <<'J'
{"study_name":"crime","source_mode":"yoda","local_path":null,"cadence":"off","default_n":15}
J
cat > "${ROOT}/config/source.json" <<'J'
{"mode":"yoda","collection":"/coll","host":"fsw.data.uu.nl","zone":"nluu10p","ticket":"tkt123"}
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
check "yoda: ticket used via -T"             grep -q -- '-T tkt123' "${FAKE_GOCMD_LOG}"

# ---- 3. yoda invalid ticket: error status, no data touched --------------------
rm -rf "${ROOT}/data/inbox"; mkdir -p "${ROOT}/data/inbox"
touch "${ROOT}/state/refresh-requested"
FAKE_GOCMD_FAIL=1 "${TMP}/ddp-refresh.sh" || true
check "yoda bad ticket: phase error"         bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = error ]"
check "yoda bad ticket: plain-language msg"  bash -c "jq -r .message '${ROOT}/state/refresh-status.json' | grep -qi 'access code'"
check "yoda bad ticket: inbox untouched"     bash -c "[ -z \"\$(ls -A '${ROOT}/data/inbox')\" ]"

# ---- 4. resume after interrupt: rerun completes -------------------------------
touch "${ROOT}/state/refresh-requested"
check "yoda rerun: succeeds (idempotent)"    "${TMP}/ddp-refresh.sh"
check "yoda rerun: phase done again"         bash -c "[ \"\$(jq -r .phase '${ROOT}/state/refresh-status.json')\" = done ]"

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

# ---- 8. timer tick with cadence=off and no flag: exits 0, no run -----------------
LAST="$(jq -r .started_at "${ROOT}/state/refresh-status.json")"
check "timer+off: exits 0"                   "${TMP}/ddp-refresh.sh"
check "timer+off: no new run recorded"       bash -c "[ \"\$(jq -r .started_at '${ROOT}/state/refresh-status.json')\" = '${LAST}' ]"

# ---- 9. probe script ----------------------------------------------------------------
cat > "${ROOT}/config/source.json" <<'J'
{"mode":"yoda","collection":"/coll","host":"fsw.data.uu.nl","zone":"nluu10p","ticket":"tkt123"}
J
check "probe yoda ok"                        "${TMP}/ddp-probe.sh" yoda
check "probe yoda bad ticket fails"          bash -c "! FAKE_GOCMD_FAIL=1 '${TMP}/ddp-probe.sh' yoda"

echo; [ "${FAIL}" -eq 0 ] && echo "ALL PASS" || { echo "${FAIL} FAILURES"; exit 1; }
