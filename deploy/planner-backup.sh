#!/usr/bin/env bash
# Nightly backup of the planner. The whole application state is one SQLite file
# — tasks and calendar objects together — so this tarball is a complete backup.
#
# The container is stopped first on purpose: copying a live SQLite WAL can
# produce an archive that will not restore. .env goes in too, which is why
# backups/ is 0700 root: it holds APP_KEY, and the database with the password hash.
set -euo pipefail

ROOT=/opt/planner
COMPOSE="docker compose -f ${ROOT}/compose.yml"
STAMP=$(date -u +%Y%m%dT%H%M%SZ)
ARCHIVE="${ROOT}/backups/planner-${STAMP}.tar.gz"

mkdir -p "${ROOT}/backups"
chmod 700 "${ROOT}/backups"

${COMPOSE} stop planner

# Start the container again even if tar fails.
trap '${COMPOSE} start planner || true' EXIT

tar -czf "${ARCHIVE}" -C "${ROOT}" data .env
chmod 600 "${ARCHIVE}"

${COMPOSE} start planner
trap - EXIT

find "${ROOT}/backups" -name 'planner-*.tar.gz' -mtime +14 -delete

echo "planner backup written to ${ARCHIVE}"
