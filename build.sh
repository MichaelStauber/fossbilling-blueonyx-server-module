#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
MANIFEST="${ROOT_DIR}/modules/Blueonyx/manifest.json"
VERSION="$(sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "${MANIFEST}" | head -n 1)"
STAGING_DIR="$(mktemp -d)"

cleanup() {
  rm -rf "${STAGING_DIR}"
}
trap cleanup EXIT

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
  "${ROOT_DIR}/HANDOVER.md" \
  "${ROOT_DIR}/LICENSE" \
  "${ROOT_DIR}/SUN-modified-BSD-License.txt" \
  "${ROOT_DIR}/docs" \
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

mkdir -p "${STAGING_DIR}"
cp "${ROOT_DIR}/README.md" "${STAGING_DIR}/BlueOnyx-README.md"
cp "${ROOT_DIR}/CHANGELOG.md" "${STAGING_DIR}/CHANGELOG.md"
cp "${ROOT_DIR}/HANDOVER.md" "${STAGING_DIR}/HANDOVER.md"
cp "${ROOT_DIR}/LICENSE" "${STAGING_DIR}/LICENSE"
cp "${ROOT_DIR}/SUN-modified-BSD-License.txt" "${STAGING_DIR}/SUN-modified-BSD-License.txt"
cp "${ROOT_DIR}/build.sh" "${STAGING_DIR}/build.sh"
cp -R "${ROOT_DIR}/docs" "${STAGING_DIR}/docs"
cp -R "${ROOT_DIR}/modules" "${STAGING_DIR}/modules"
cp -R "${ROOT_DIR}/library" "${STAGING_DIR}/library"

cd "${STAGING_DIR}"
zip -r "${ZIP_PATH}" \
  BlueOnyx-README.md \
  CHANGELOG.md \
  HANDOVER.md \
  LICENSE \
  SUN-modified-BSD-License.txt \
  build.sh \
  docs \
  modules/Blueonyx \
  library/Server/Manager/Blueonyx.php

echo "Created ${ZIP_PATH}"
