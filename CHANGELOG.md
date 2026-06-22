# Changelog

All notable changes to this repository will be documented in this file.

The format below is intentionally release-oriented so it can be copied into
GitHub Releases without additional editing.

## [0.2.2] - 2026-06-21

### Added

- The admin order manage override now guards against missing `suspend_reason_list` values in the mod_order extension config

### Changed

- Bumped the module release package to `0.2.2`
- Synced the GitHub checkout with the latest live BlueOnyx order manage template fix

### Fixed

- Opening an order in the admin area no longer crashes when the mod_order extension config does not provide a suspend reason list

### Security

- No security-specific changes in this release

## [0.2.1] - 2026-06-20

### Added

- Release packaging now carries the current BlueOnyx server override source
  so module activation can deploy the admin server template without failing
- Blueonyx README is shipped in the ZIP as `Blueonyx-README.md` to keep the
  GitHub-facing README filename untouched in the repository

### Changed

- Bumped the module release package to `0.2.1`
- Tightened the release tree so it is directly publishable after zip build

### Fixed

- Activation no longer fails when the admin server template override source is
  missing from the release tree
- Checkout override references to the promo context are handled defensively

### Security

- No security-specific changes in this release

## [0.2.0] - 2026-06-20

### Added

- Versioned the BlueOnyx module release package for the first publicable
  self-contained build
- Documented the module-owned BlueOnyx administration flow and packaging
  layout more clearly for GitHub/SVN publishing
- Kept the customer-facing Let’s Encrypt request flow asynchronous through
  the normal FOSSBilling admin cron run
- Added email autoconfiguration support through the BlueOnyx Vsite plan
  editor

### Changed

- Release packaging now includes the module tree, server manager, docs, and
  build script needed to reproduce the ZIP
- Updated release docs and handover notes to reflect the current module
  ownership model and shipped artifact layout

### Fixed

- No user-facing bug fixes in this release note beyond the ongoing module
  integration work captured in the current repository state

### Security

- No security-specific changes in this release

## [0.1.0] - 2026-06-19

### Added

- Initial BlueOnyx server module packaging for FOSSBilling
- BlueOnyx hosting plan editor and lifecycle integration
- Customer-facing SSL status and Let's Encrypt request flow
- Automatic deployment and removal of BlueOnyx theme overrides
- Reproducible release packaging with `build.sh`

### Changed

- Introduced a standalone repository layout for the BlueOnyx module

### Fixed

- No bug fixes in this release

### Security

- No security-specific changes in this release
