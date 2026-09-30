#!/usr/bin/env bash
set -Eeuo pipefail

sha="${1:-}"
[[ "$sha" =~ ^[0-9a-f]{40}$ ]] || { echo 'Expected a 40-character commit SHA' >&2; exit 2; }

base=/var/www/html/blucom-deploy
releases="$base/releases"
shared="$base/shared"
current="$base/current"
repo=git@github.com:AmirMehrabi/blucom_ir.git
release="$releases/$sha"

mkdir -p "$releases" "$shared" "$base/logs"
exec 9>"$base/deploy.lock"
flock 9

head_sha="$(git ls-remote "$repo" refs/heads/master | cut -f1)"
[[ "$head_sha" == "$sha" ]] || { echo 'Skipping commit that is no longer master HEAD'; exit 0; }
if [[ -L "$current" && "$(readlink -f "$current")" == "$release" ]]; then
    echo 'Commit already deployed'
    exit 0
fi
[[ -f "$shared/.env" && -d "$shared/storage" ]] || { echo 'Shared .env or storage is missing' >&2; exit 1; }

previous=""
[[ ! -L "$current" ]] || previous="$(readlink -f "$current")"
switched=0
rollback() {
    if (( switched )) && [[ -n "$previous" ]]; then
        ln -s "$previous" "$base/.rollback.$$"
        mv -Tf "$base/.rollback.$$" "$current"
        echo "Restored previous release $previous" >&2
    fi
}
trap rollback ERR

if [[ ! -d "$release/.git" ]]; then
    rm -rf "$release"
    git clone --quiet --depth=1 --branch master "$repo" "$release"
fi
[[ "$(git -C "$release" rev-parse HEAD)" == "$sha" ]] || { echo 'Cloned commit does not match master HEAD' >&2; exit 1; }

ln -sfn "$shared/.env" "$release/.env"
rm -rf "$release/storage"
ln -s "$shared/storage" "$release/storage"
mkdir -p "$release/bootstrap/cache" "$shared/storage/app/public" "$shared/assets"
ln -sfn "$shared/storage/app/public" "$release/public/storage"
chgrp -R www-data "$release/bootstrap/cache"
chmod 2775 "$release/bootstrap/cache"

(
    cd "$release"
    composer install --no-interaction --prefer-dist --no-progress --optimize-autoloader
    APP_ENV=testing php artisan test --compact
    composer install --no-dev --no-interaction --prefer-dist --no-progress --optimize-autoloader
    npm ci --no-audit --no-fund
    npm run build
    php artisan optimize
    php artisan migrate --force
)

# Keep old hashed assets reachable while a browser still holds an older page.
rsync -a "$release/public/build/assets/" "$shared/assets/"

ln -s "$release" "$base/.next.$$"
mv -Tf "$base/.next.$$" "$current"
switched=1

status="$(curl --silent --output /dev/null --write-out '%{http_code}' --resolve admin.blucom.ir:443:127.0.0.1 https://admin.blucom.ir/up)"
if [[ "$status" != 200 ]]; then
    echo "Health check returned $status" >&2
    rollback
    exit 1
fi
# Long-running services must load the new checkout after the atomic switch.
# Initial installation is an infrastructure step; later releases restart only
# the application services that are already active, never FreeSWITCH.
if systemctl is-active --quiet blucom-reverb.service; then
    php "$current/artisan" reverb:restart
fi
if systemctl is-active --quiet blucom-live-monitor.service; then
    php "$current/artisan" voip:monitor --restart
fi
switched=0
echo "Deployed $sha"
