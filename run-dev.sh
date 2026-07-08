#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
if [ ! -f config.php ]; then
  echo "No config.php — copy config.php.example to config.php and set ddp_dir." >&2
  exit 1
fi
exec php -S 127.0.0.1:8110 -t public
