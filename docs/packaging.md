# Packaging and release workflow

This repository is intended to be released as an independent GitHub project.
The release unit is a ZIP archive built from the repository root.

## Repository layout

The important top-level paths are:

- `modules/Blueonyx/` — the FOSSBilling module
- `library/Server/Manager/Blueonyx.php` — the BlueOnyx server manager
- `build.sh` — the packaging script
- `CHANGELOG.md` — release history
- `README.md` — end-user documentation
- `LICENSE` — the canonical repository license text

## Version source

The release version is read from:

- `modules/Blueonyx/manifest.json`

That version should stay in sync with:

- the Git tag
- the changelog entry
- the ZIP file name

## Build output

The packaging script creates:

- `fossbilling-blueonyx-server-module-<version>.zip`

The ZIP contains the module tree, the server manager, and the release
documents that belong with the published artifact. In practice that includes
the module tests and the module packaging note as well.

## Release process

1. Update `modules/Blueonyx/manifest.json`.
2. Add a matching entry to `CHANGELOG.md`.
3. Run `./build.sh`.
4. Verify the ZIP contents.
5. Create a Git tag such as `v0.1.0`.
6. Publish the ZIP as a GitHub Release asset.

## Practical rules

- Do not commit environment-specific files.
- Do not include secrets, customer data, or test artifacts in releases.
- Keep the BlueOnyx core repository and this module repository separate.
