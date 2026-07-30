#!/usr/bin/env bash
# Tier 2 verification: run the playbook twice inside a clean ubuntu:24.04
# container. Run 2 must report changed=0 (idempotency).
#
# Container deltas vs a real workspace (documented, deliberate):
#   - No SRC-Nginx: we pre-create /etc/nginx/app-location-conf.d so preflight
#     passes; the nginx validate/reload handler is a no-op (no nginx binary →
#     handler task guarded below never fires because the conf render notifies
#     a handler that runs `nginx -t` only when nginx exists).
#   - No systemd: unit enable/start tasks are gated on
#     ansible_service_mgr == 'systemd' and skip cleanly.
#   - The "volume" is a plain dir injected via storage_path (extra-var wins
#     over auto-detect by design).
set -euo pipefail

CPUS="${1:-4}"
REPO_DIR="$(cd "$(dirname "$0")/../.." && pwd)"

docker run --rm --cpus="$CPUS" -v "$REPO_DIR":/component:ro ubuntu:24.04 bash -c "
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
apt-get update -q && apt-get install -yq python3-pip python3-venv sudo git curl unzip >/dev/null
python3 -m venv /opt/ansible
/opt/ansible/bin/pip install -q 'ansible==9.1.0'
mkdir -p /etc/nginx/app-location-conf.d
mkdir -p /data/vol1

run_playbook() {
  /opt/ansible/bin/ansible-playbook /component/provisioning/deploy-ddp-inspector.yaml \
    -e storage_path=/data/vol1
}

echo '=== TIER 2 RUN 1 (cold) ==='
run_playbook | tee /tmp/run1.log
echo '=== TIER 2 RUN 2 (idempotency) ==='
run_playbook | tee /tmp/run2.log

recap=\$(grep -A2 'PLAY RECAP' /tmp/run2.log | tail -1)
echo \"run 2 recap: \$recap\"
changed=\$(echo \"\$recap\" | sed -n 's/.*changed=\\([0-9]*\\).*/\\1/p')
if [[ \"\$changed\" -eq 0 ]]; then
  echo 'TIER 2 PASS (run-2 changed=0)'
else
  echo \"TIER 2 FAIL (run-2 changed=\$changed — non-idempotent tasks present)\"
  exit 1
fi
"
