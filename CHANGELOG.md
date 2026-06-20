# Changelog

All notable changes to this repository will be documented in this file.

The format below is intentionally release-oriented so it can be copied into
GitHub Releases without additional editing.

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
