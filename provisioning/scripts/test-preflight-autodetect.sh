#!/usr/bin/env bash
# test-preflight-autodetect.sh — hermetic tests for preflight's storage-volume
# auto-detection (no SRC needed). Runs the real preflight role via a scratch
# play, injecting fake mount tables (storage_mounts_source, WITH fstype) and a
# temp data root (storage_data_root). Contract under test: the storage_path
# INPUT (an SRC extra-var, which set_fact cannot override) resolves into the
# storage_root FACT; exactly one BLOCK-DEVICE (ext4/xfs) mount qualifies;
# fuse.* mounts (Research-Drive-by-Link) never qualify; no volume = loud fail.
set -uo pipefail   # no -e: failures are counted and reported

HERE="$(cd "$(dirname "$0")" && pwd)"
PROV="$(dirname "$HERE")"
PLAYBOOK="${PROV}/.venv/bin/ansible-playbook"
[ -x "${PLAYBOOK}" ] || { echo "missing ${PLAYBOOK} — set up provisioning/.venv first" >&2; exit 2; }

TMP="$(mktemp -d)"
trap 'rm -rf "${TMP}"' EXIT

FAIL=0
check() { local desc="$1"; shift; if "$@" >/dev/null 2>&1; then echo "ok: ${desc}"; else echo "FAIL: ${desc}"; FAIL=$((FAIL+1)); fi; }

DATA="${TMP}/data"
NGINXDIR="${TMP}/nginx-conf.d"; mkdir -p "${NGINXDIR}"

cat > "${TMP}/scratch.yaml" <<'PLAY'
---
- name: Preflight auto-detect scratch harness
  hosts: 127.0.0.1
  connection: local
  gather_facts: false
  vars:
    inspector_base_path: "/inspector"
    inspector_install_dir: "/opt/ddp-inspector"
    inspector_service_user: "www-data"
    inspector_service_group: "www-data"
    # Skip the php/nginx environment asserts' real-system checks are fed
    # by the harness (nginx_confdir points at a temp dir; php check is
    # satisfied by the host having php or by preflight_skip_php below).
  roles:
    - role: preflight
  tasks:
    - name: Report resolved facts
      ansible.builtin.debug:
        msg: >-
          RESOLVED storage_root=[{{ storage_root | default('') }}]
          inspector_root=[{{ inspector_root | default('') }}]
          php_fpm_service=[{{ php_fpm_service | default('') }}]
          php_fpm_sock=[{{ php_fpm_sock | default('') }}]
PLAY

mounts() { # mounts <dir:fstype ...> -> -e JSON dict with fstype-carrying entries
  local out first=1 spec d t
  out='{"storage_mounts_source": ['
  for spec in "$@"; do
    d="${spec%%:*}"; t="${spec##*:}"
    [ "${first}" -eq 1 ] || out+=","
    out+="{\"mount\": \"${d}\", \"fstype\": \"${t}\"}"
    first=0
  done
  printf '%s]}' "${out}"
}

run() { # run <case-log> <extra -e args...>
  local log="$1"; shift
  ANSIBLE_ROLES_PATH="${PROV}/roles" "${PLAYBOOK}" "${TMP}/scratch.yaml" \
    -e "storage_data_root=${DATA}" -e "nginx_confdir=${NGINXDIR}" \
    -e "preflight_skip_php=true" "$@" \
    > "${TMP}/${log}" 2>&1
}

# ---- 1. one ext4 volume, no storage_path -> adopted -------------------------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1"
run c1.log -e "$(mounts "${DATA}/vol1:ext4")"
check "one ext4 volume: play succeeds"        bash -c "! grep -q 'failed=1' '${TMP}/c1.log'"
check "one ext4 volume: adopted"              grep -qF "RESOLVED storage_root=[${DATA}/vol1]" "${TMP}/c1.log"
check "inspector_root derived"                grep -qF "inspector_root=[${DATA}/vol1/ddp-inspector]" "${TMP}/c1.log"

# ---- 2. ext4 volume + fuse.rclone mount -> volume adopted, fuse ignored -----
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1" "${DATA}/rdbylink"
run c2.log -e "$(mounts "${DATA}/vol1:ext4" "${DATA}/rdbylink:fuse.rclone")"
check "ext4+fuse: play succeeds"              bash -c "! grep -q 'failed=1' '${TMP}/c2.log'"
check "ext4+fuse: block volume adopted"       grep -qF "RESOLVED storage_root=[${DATA}/vol1]" "${TMP}/c2.log"

# ---- 3. two ext4 volumes -> loud ambiguity failure --------------------------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1" "${DATA}/vol2"
run c3.log -e "$(mounts "${DATA}/vol1:ext4" "${DATA}/vol2:ext4")"
check "two volumes: play fails"               grep -q 'failed=1' "${TMP}/c3.log"
check "two volumes: ambiguity named"          grep -qi 'set storage_path explicitly' "${TMP}/c3.log"

# ---- 4. xfs volume qualifies (block-device allowlist) ------------------------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1"
run c4.log -e "$(mounts "${DATA}/vol1:xfs")"
check "xfs volume: adopted"                   grep -qF "RESOLVED storage_root=[${DATA}/vol1]" "${TMP}/c4.log"

# ---- 5. ONLY a fuse mount -> treated as no volume -> loud failure ------------
rm -rf "${DATA}"; mkdir -p "${DATA}/rdbylink"
run c5.log -e "$(mounts "${DATA}/rdbylink:fuse.rclone")"
check "fuse only: play fails"                 grep -q 'failed=1' "${TMP}/c5.log"
check "fuse only: create-a-volume guidance"   grep -qi 'create a small volume' "${TMP}/c5.log"

# ---- 6. no volume at all -> loud failure with create-a-volume fail_msg ------
rm -rf "${DATA}"; mkdir -p "${DATA}"
run c6.log -e '{"storage_mounts_source": []}'
check "no volume: play fails"                 grep -q 'failed=1' "${TMP}/c6.log"
check "no volume: create-a-volume guidance"   grep -qi 'create a small volume' "${TMP}/c6.log"

# ---- 7. explicit storage_path wins over detection ---------------------------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1" "${TMP}/explicit"
run c7.log -e "$(mounts "${DATA}/vol1:ext4")" -e "storage_path=${TMP}/explicit"
check "explicit path: respected verbatim"     grep -qF "RESOLVED storage_root=[${TMP}/explicit]" "${TMP}/c7.log"

# ---- 8. unedited placeholder counts as unset -> detection runs --------------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1"
run c8.log -e "$(mounts "${DATA}/vol1:ext4")" \
  -e 'storage_path=/home/<username>/data/<volume-name>'
check "placeholder: detection supersedes"     grep -qF "RESOLVED storage_root=[${DATA}/vol1]" "${TMP}/c8.log"

# ---- 9. symlinked entry: ~/data/<vol> -> shared mount elsewhere --------------
rm -rf "${DATA}"; mkdir -p "${DATA}" "${TMP}/shared/vol1"
ln -s "${TMP}/shared/vol1" "${DATA}/vol1"
run c9.log -e "$(mounts "${TMP}/shared/vol1:ext4")"
check "symlinked volume: adopted via link"    grep -qF "RESOLVED storage_root=[${DATA}/vol1]" "${TMP}/c9.log"

# ---- 10. the data dir ITSELF is a symlink to a shared mount root -------------
rm -rf "${DATA}"; mkdir -p "${TMP}/sharedroot/vol1"
ln -s "${TMP}/sharedroot" "${DATA}"
run c10.log -e "$(mounts "${TMP}/sharedroot/vol1:ext4")"
check "symlinked data root: volume adopted"   grep -qF "RESOLVED storage_root=[${TMP}/sharedroot/vol1]" "${TMP}/c10.log"

# ---- 11. missing SRC-Nginx conf dir -> loud failure --------------------------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1"
ANSIBLE_ROLES_PATH="${PROV}/roles" "${PLAYBOOK}" "${TMP}/scratch.yaml" \
  -e "storage_data_root=${DATA}" -e "nginx_confdir=${TMP}/no-such-dir" \
  -e "preflight_skip_php=true" -e "$(mounts "${DATA}/vol1:ext4")" \
  > "${TMP}/c11.log" 2>&1
check "missing nginx dir: play fails"         grep -q 'failed=1' "${TMP}/c11.log"
check "missing nginx dir: names SRC-Nginx"    grep -qi 'SRC-Nginx' "${TMP}/c11.log"

# ---- php-facts path (preflight_skip_php=false): fake apt-cache on PATH ------
# Regression coverage for the epoch/non-epoch "Candidate:" parsing — Ubuntu
# 24.04's php-fpm candidate is unepoched, and an epoch-only regex used to
# crash the whole play (`'NoneType' object is not iterable`) on that form.
apt_shim() { # apt_shim <dir> <candidate-line> -> writes a fake apt-cache there
  local dir="$1" candidate="$2"
  mkdir -p "${dir}"
  cat > "${dir}/apt-cache" <<SH
#!/usr/bin/env bash
cat <<OUT
php-fpm:
  Installed: (none)
  Candidate: ${candidate}
  Version table:
     ${candidate} 500
        500 http://archive.ubuntu.com/ubuntu noble/universe amd64 Packages
OUT
SH
  chmod +x "${dir}/apt-cache"
}

# ---- 12. epoch candidate (e.g. Debian-style "2:8.3+93ubuntu2") --------------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1"
APTBIN="${TMP}/apt-epoch"; apt_shim "${APTBIN}" "2:8.3+93ubuntu2"
PATH="${APTBIN}:${PATH}" ANSIBLE_ROLES_PATH="${PROV}/roles" "${PLAYBOOK}" "${TMP}/scratch.yaml" \
  -e "storage_data_root=${DATA}" -e "nginx_confdir=${NGINXDIR}" \
  -e "preflight_skip_php=false" -e "$(mounts "${DATA}/vol1:ext4")" \
  > "${TMP}/c12.log" 2>&1
check "php epoch candidate: play succeeds"    bash -c "! grep -q 'failed=1' '${TMP}/c12.log'"
check "php epoch candidate: service resolved" grep -qF "php_fpm_service=[php8.3-fpm]" "${TMP}/c12.log"
check "php epoch candidate: sock resolved"    grep -qF "php_fpm_sock=[/run/php/php8.3-fpm.sock]" "${TMP}/c12.log"

# ---- 13. non-epoch candidate (Ubuntu 24.04's actual form) -> no crash -------
rm -rf "${DATA}"; mkdir -p "${DATA}/vol1"
APTBIN="${TMP}/apt-noepoch"; apt_shim "${APTBIN}" "8.3.6-1build1"
PATH="${APTBIN}:${PATH}" ANSIBLE_ROLES_PATH="${PROV}/roles" "${PLAYBOOK}" "${TMP}/scratch.yaml" \
  -e "storage_data_root=${DATA}" -e "nginx_confdir=${NGINXDIR}" \
  -e "preflight_skip_php=false" -e "$(mounts "${DATA}/vol1:ext4")" \
  > "${TMP}/c13.log" 2>&1
check "php non-epoch candidate: play succeeds (no crash)" bash -c "! grep -q 'failed=1' '${TMP}/c13.log'"
check "php non-epoch candidate: service resolved"         grep -qF "php_fpm_service=[php8.3-fpm]" "${TMP}/c13.log"
check "php non-epoch candidate: sock resolved"             grep -qF "php_fpm_sock=[/run/php/php8.3-fpm.sock]" "${TMP}/c13.log"

# ---- provision-time detection (mount table under the platform data root) ----
# During a real SRC create the play runs as the cloud user (ubuntu): no home
# data-dir symlink exists yet, and the volume is only visible as its mount
# under /data/<volume-name> (live failure 2026-07-30). storage_platform_data_root
# is the seam standing in for /data.
PDATA="${TMP}/pdata"

# ---- 14. provision-time shape: volume mounted under /data, home data dir missing
rm -rf "${DATA}" "${PDATA}"; mkdir -p "${PDATA}/vol1"
run c14.log -e "storage_platform_data_root=${PDATA}" \
  -e "$(mounts "${PDATA}/vol1:xfs" "${PDATA}/rdbylink:fuse.rclone")"
check "provision shape: play succeeds"        bash -c "! grep -q 'failed=1' '${TMP}/c14.log'"
check "provision shape: volume adopted"       grep -qF "RESOLVED storage_root=[${PDATA}/vol1]" "${TMP}/c14.log"

# ---- 15. mount scan + symlinked home data root agree -> ONE candidate, no ambiguity
rm -rf "${DATA}" "${PDATA}"; mkdir -p "${PDATA}/vol1"
ln -s "${PDATA}" "${DATA}"
run c15.log -e "storage_platform_data_root=${PDATA}" -e "$(mounts "${PDATA}/vol1:xfs")"
check "dual detection: no spurious ambiguity" bash -c "! grep -q 'failed=1' '${TMP}/c15.log'"
check "dual detection: volume adopted"        grep -qF "RESOLVED storage_root=[${PDATA}/vol1]" "${TMP}/c15.log"

# ---- 16. home entry is a symlink to an already-counted mount -> deduped ------
rm -rf "${DATA}" "${PDATA}"; mkdir -p "${DATA}" "${PDATA}/vol1"
ln -s "${PDATA}/vol1" "${DATA}/vol1"
run c16.log -e "storage_platform_data_root=${PDATA}" -e "$(mounts "${PDATA}/vol1:xfs")"
check "symlink dedupe: no spurious ambiguity" bash -c "! grep -q 'failed=1' '${TMP}/c16.log'"
check "symlink dedupe: volume adopted"        grep -qF "RESOLVED storage_root=[${PDATA}/vol1]" "${TMP}/c16.log"

# ---- 17. two volumes under /data -> loud ambiguity failure -------------------
rm -rf "${DATA}" "${PDATA}"; mkdir -p "${PDATA}/vol1" "${PDATA}/vol2"
run c17.log -e "storage_platform_data_root=${PDATA}" \
  -e "$(mounts "${PDATA}/vol1:xfs" "${PDATA}/vol2:ext4")"
check "two /data volumes: play fails"         grep -q 'failed=1' "${TMP}/c17.log"
check "two /data volumes: ambiguity named"    grep -qi 'set storage_path explicitly' "${TMP}/c17.log"

echo; [ "${FAIL}" -eq 0 ] && echo "ALL PASS" || { echo "${FAIL} FAILURES"; exit 1; }
