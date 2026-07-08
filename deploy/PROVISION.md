# Provisioning DDP Inspector in a SURF Research Cloud workspace

## Prerequisites (Ansible playbook additions)
- Install PHP: `package: { name: [php-fpm, php-cli] }`
- Enable php-fpm: `service: { name: php-fpm, state: started, enabled: true }`

## Deploy
1. Copy the repo to `/opt/ddp-inspector` (exclude `config.php`, tests optional).
2. `cp config.php.example config.php`; set:
   - `ddp_dir` → read-only path to the workspace's TikTok DDP files.
   - `transcripts_dir` → sharded transcript tree, or `null`.
   - `base_path` → `/inspector`.
3. Add `deploy/nginx-location.conf.example` content to
   `/etc/nginx/app-location-conf.d/authentication.conf`; adjust paths/fpm socket.
4. `systemctl reload nginx`.
5. Browse `https://<hostname>.<co>.src.surf-hosted.nl/inspector/` (SRAM login required).

## Local dev alternative
`cp config.php.example config.php` (set `ddp_dir`), then `./run-dev.sh` → http://127.0.0.1:8110
