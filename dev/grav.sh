#!/usr/bin/env bash
# Development Grav for the plugin: Grav and the plugins it needs, at the versions of the sites, in tmp/grav (ignored by git),
# served by the PHP built-in server. No container.
#
#   dev/grav.sh install   install (or complete) the site, link this plugin, copy the pages of the test site (dev/site)
#   dev/grav.sh serve     serve it on http://127.0.0.1:${GRAV_PORT:-8820}
#   dev/grav.sh tunnel    forward ${API_TUNNEL:-}  (local port:host:port through ssh) for an engine API that only listens locally
set -Eeuo pipefail
cd -- "$(dirname -- "$(readlink -f -- "$0")")/.."
plugin="$PWD"
[ -f .env ] && { set -a; . ./.env; set +a; }
site="${GRAV_DIR:-$plugin/tmp/grav}"
port="${GRAV_PORT:-8820}"

# path url tag
dependencies='
. https://github.com/getgrav/grav.git 2.2.23
user/plugins/error https://github.com/getgrav/grav-plugin-error.git 2.0.5
user/plugins/problems https://github.com/getgrav/grav-plugin-problems.git 3.0.1
user/plugins/shortcode-core https://github.com/getgrav/grav-plugin-shortcode-core.git 6.2.7
user/plugins/email https://github.com/getgrav/grav-plugin-email.git 5.3.4
user/plugins/form https://github.com/getgrav/grav-plugin-form.git 9.1.39
user/plugins/login https://github.com/getgrav/grav-plugin-login.git 3.12.1
user/themes/quark2 https://github.com/getgrav/grav-theme-quark2.git 1.1.22
'

install() {
    while read -r path url tag; do
        [ -n "$path" ] || continue
        target="$site/$path"
        [ "$path" = . ] && target="$site"
        if [ ! -e "$target/.git" ] && [ ! -e "$target/blueprints.yaml" ] && { [ "$path" != . ] || [ ! -e "$site/index.php" ]; }; then
            git -c advice.detachedHead=false clone -q --depth 1 --branch "$tag" "$url" "$target"
        fi
    done <<< "$dependencies"
    [ -f "$site/vendor/autoload.php" ] || composer install -q --no-dev -d "$site"
    for p in "$site"/user/plugins/*/ "$site"/user/themes/*/; do
        [ -f "$p/composer.json" ] && [ ! -d "$p/vendor" ] && grep -q '"require"' "$p/composer.json" && composer install -q --no-dev -d "$p" || true
    done
    ln -sfn "$plugin" "$site/user/plugins/w4os"
    mkdir -p "$site/user/config/plugins"
    cp dev/site/config/system.yaml "$site/user/config/system.yaml" 2>/dev/null || true
    rm -rf "$site/user/pages/02.typography"
    cp -R dev/site/pages/. "$site/user/pages/"
    echo "Grav in $site. dev/grav.sh serve"
}

case "${1:-}" in
    install) install ;;
    serve) cd "$site" && exec php -S "127.0.0.1:$port" system/router.php ;;
    tunnel) [ -n "${API_TUNNEL:-}" ] && [ -n "${API_TUNNEL_HOST:-}" ] || { echo "Set API_TUNNEL (8099:127.0.0.1:8099) and API_TUNNEL_HOST in .env" >&2; exit 1; }
        exec ssh -N -L "$API_TUNNEL" "$API_TUNNEL_HOST" ;;
    *) sed -n '2,8p' "$0" | sed 's/^# \{0,1\}//'; exit 1 ;;
esac
