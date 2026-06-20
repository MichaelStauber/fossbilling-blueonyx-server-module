#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST="${ROOT_DIR}/modules/Blueonyx/manifest.json"
VERSION="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "${MANIFEST}" | head -n 1)"

if [[ -z "${VERSION}" ]]; then
  echo "Unable to determine version from ${MANIFEST}" >&2
  exit 1
fi

if ! command -v zip >/dev/null 2>&1; then
  echo "zip is required but was not found in PATH" >&2
  exit 1
fi

for required in \
  "${ROOT_DIR}/README.md" \
  "${ROOT_DIR}/CHANGELOG.md" \
  "${ROOT_DIR}/LICENSE" \
  "${ROOT_DIR}/SUN-modified-BSD-License.txt" \
  "${ROOT_DIR}/modules/Blueonyx" \
  "${ROOT_DIR}/library/Server/Manager/Blueonyx.php"
do
  if [[ ! -e "${required}" ]]; then
    echo "Missing required release asset: ${required}" >&2
    exit 1
  fi
done

ZIP_NAME="fossbilling-blueonyx-server-module-${VERSION}.zip"
ZIP_PATH="${ROOT_DIR}/${ZIP_NAME}"

rm -f "${ZIP_PATH}"
cd "${ROOT_DIR}"
zip -r "${ZIP_PATH}" \
  README.md \
  CHANGELOG.md \
  LICENSE \
  SUN-modified-BSD-License.txt \
  modules/Blueonyx \
  library/Server/Manager/Blueonyx.php

echo "Created ${ZIP_PATH}"
