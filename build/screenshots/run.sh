#!/usr/bin/env bash
# Dashboard screenshots for the docs. Needs the workbench server on :8000
# (docker compose up serve), or MONOCLE_URL pointing at it. Pass shot names
# to take only those.
#
# Runs in Docker, or in Podman with CONTAINER=podman. Without CONTAINER it
# uses Docker when the Docker daemon answers, else Podman.
set -euo pipefail
cd "$(dirname "$0")/../.."

runtime="${CONTAINER:-}"

if [ -z "$runtime" ]; then
    if command -v docker > /dev/null && docker info > /dev/null 2>&1; then
        runtime=docker
    elif command -v podman > /dev/null; then
        runtime=podman
    else
        echo "Neither Docker nor Podman is available; set CONTAINER=docker or CONTAINER=podman." >&2
        exit 1
    fi
fi

case "$runtime" in
    # Rootless Podman maps your user into the container, then runs as it.
    podman) user=(--userns=keep-id --user "$(id -u):$(id -g)") ;;
    docker) user=(--user "$(id -u):$(id -g)") ;;
    *)
        echo "CONTAINER must be docker or podman, not '$runtime'." >&2
        exit 1
        ;;
esac

"$runtime" run --rm --network host \
    "${user[@]}" -e HOME=/tmp \
    -e PUPPETEER_CACHE_DIR=/home/pptruser/.cache/puppeteer \
    -e NODE_PATH=/home/pptruser/node_modules \
    -e MONOCLE_URL="${MONOCLE_URL:-http://localhost:8000}" \
    -v "$PWD":/work -w /work \
    ghcr.io/puppeteer/puppeteer:latest \
    node build/screenshots/shoot.cjs "$@"
