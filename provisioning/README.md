# ddp-inspector provisioning

The SRC catalog-component half of this repo: one Ansible play that turns a
workspace (already running SRC-Nginx) into a study-blind DDP Inspector.
Design: `../docs/superpowers/specs/2026-07-29-ddp-inspector-catalog-component-design.md`.
Operations: `docs/OPERATIONS.md`.

## Layout

    deploy-ddp-inspector.yaml   entry play (SRC component Path points here)
    roles/preflight             volume auto-detect (fstype-filtered) + asserts
    roles/inspector             php-fpm, app install, nginx location, volume tree
    roles/refresh               gocmd/rclone, refresh+probe scripts, systemd units
    scripts/                    hermetic test harnesses (see below)
    docs/OPERATIONS.md          operator runbook

## Tests

    scripts/tier1.sh                      yamllint + --syntax-check
    scripts/test-preflight-autodetect.sh  injected-facts auto-detect cases
    scripts/test-inspector-templates.sh   template render assertions
    scripts/test-refresh-script.sh        refresh/probe vs fake gocmd/rclone
    scripts/tier2-docker.sh               container double-run, changed=0

One-time setup: `python3 -m venv .venv && .venv/bin/pip install 'ansible==9.1.0' yamllint ansible-lint`
