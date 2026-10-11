#!/usr/bin/env bash
# Dashboard screenshots for the docs. Needs the workbench server on :8000
# (docker compose up serve). Pass shot names to take only those.
set -euo pipefail
cd "$(dirname "$0")/../.."

docker run --rm --network host \
    --user "$(id -u):$(id -g)" -e HOME=/tmp \
    -e PUPPETEER_CACHE_DIR=/home/pptruser/.cache/puppeteer \
    -e NODE_PATH=/home/pptruser/node_modules \
    -e MONOCLE_URL="${MONOCLE_URL:-http://localhost:8000}" \
    -v "$PWD":/work -w /work \
    ghcr.io/puppeteer/puppeteer:latest \
    node workbench/screenshots/shoot.cjs "$@"
