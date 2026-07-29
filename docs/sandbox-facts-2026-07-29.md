# Sandbox reconnaissance facts — 2026-07-29

Workspace: `test.develop-data-do.src.surf-hosted.nl` (145.38.195.161), launched
from the hand-assembled dev item (SRC-OS → SRC-CO → SRC-External → SRC-Nginx →
Research Drive by Link), Ubuntu 24.04.4. These are live-verified facts the
provisioning plan's roles must match; re-verify on any newly launched box.

## Access

- SRC syncs `~/.ssh/authorized_keys` from the SRAM profile and **overwrites
  appended keys**; scoped/extra keys go in `~/.ssh/authorized_keys2`
  (sshd reads both: `authorizedkeysfile .ssh/authorized_keys .ssh/authorized_keys2`).
- `AuthorizedKeysCommand` is `none` — plain file auth.

## Storage / mounts (live fstype-filter test case)

```
/data/ddp-inspector_test_storage  /dev/vdb1   xfs          ← SRC volume (adopt)
/data/rdbylink                    rd-remote:  fuse.rclone  ← RD-by-Link mount (ignore)
/data/datasets                    (plain dir, not in mount table — reject)
~/data -> /data                   (symlink, as on the transcribe box)
```

- RD-by-Link 1.0.2 works on modern Nextcloud **iff** `rd_by_link_url` is the
  full modern endpoint `https://uu.data.surf.nl/public.php/dav/files/<TOKEN>/`
  (component passes the URL verbatim to rclone, vendor=other). Legacy
  `public.php/webdav/` returns 404 on uu.data.surf.nl (verified via curl
  PROPFIND, 2026-07-29). Auth: user=`<TOKEN>`, pass=link password.
- Personal WebDAV on this instance is modern-form too
  (`remote.php/dav/files/<user>`), which predicted the above.

## Nginx / SRAM gate

- nginx 1.24.0 (Ubuntu). `/etc/nginx/app-location-conf.d/` holds exactly one
  file at provision: `authentication.conf` (SRC-Nginx's). Our conf is an
  additional file beside it.
- `authentication.conf` provides: `@custom_401` (302 → SRC OAuth2 implicit
  flow, `oauth2.live.surfresearchcloud.nl`), `/oauth2_callback` (JS posts the
  fragment token to `/store_token`), `/store_token` (sets `Authorization`
  cookie, Max-Age from `expires_in`, default 43200), `/logout`, a sample
  gated `location = /test`, and:
- `location = /validate` proxies to
  `https://gw.live.surfresearchcloud.nl/user/users/cos/<LAUNCHING-CO-UUID>/?co_role=<rsc_nginx_co_role>`
  with the cookie token. **The CO UUID is baked in at provision** — viewing
  rights = launching CO membership; `rsc_nginx_co_role` narrows to a role.
- Gate pattern to copy (from `location = /test`):
  `error_page 401 = @custom_401; auth_request /validate;
  auth_request_set $username $upstream_http_username;`
- **php-fpm correction:** the sample forwards the user with
  `proxy_set_header REMOTE_USER $username` (proxy apps). For fastcgi we use
  `fastcgi_param REMOTE_USER $username` instead.
- Unauthenticated `https://<host>/` returns 403 (no `/` location with the
  401→login flow; our `/inspector/` location defines its own).

## PHP / systemd

- PHP not preinstalled; `php8.3-fpm` candidate `8.3.6-0ubuntu0.24.04.10`
  (≥ 8.1 ✓). Expected socket: `/run/php/php8.3-fpm.sock` (verify at install).
- systemd state: `running` — timers/path units fine.

## Item parameter facts (from assembling the dev item)

- Secret-type component params (RD-by-Link's url/user/pass) never appear at
  the item's parameter step; they resolve from the launching CO's Secrets tab.
- A provisioning failure auto-deletes the workspace ("Error in creating
  resource. Going to delete resource…") — S-snag confirmed on this item too.
- Only item-level change needed from defaults: `co_passwordless_sudo` → true.
