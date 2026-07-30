#!/usr/bin/env bash
# tier1.sh — fast static gate: yamllint + ansible-playbook --syntax-check.
# Mirrors the transcribe repo's Tier-1. Requires a .venv with ansible+lint:
#   python3 -m venv provisioning/.venv
#   provisioning/.venv/bin/pip install 'ansible==9.1.0' yamllint ansible-lint
set -euo pipefail
HERE="$(cd "$(dirname "$0")" && pwd)"
PROV="$(dirname "$HERE")"
VENV="${PROV}/.venv/bin"
[ -x "${VENV}/yamllint" ] || { echo "missing ${VENV}/yamllint — set up provisioning/.venv first" >&2; exit 2; }
"${VENV}/yamllint" -c "${PROV}/.yamllint" "${PROV}"
ANSIBLE_ROLES_PATH="${PROV}/roles" "${VENV}/ansible-playbook" --syntax-check \
  "${PROV}/deploy-ddp-inspector.yaml"
echo "TIER 1 PASS"
