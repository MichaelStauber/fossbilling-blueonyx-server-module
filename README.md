# FOSSBilling BlueOnyx Server Module

This repository contains the BlueOnyx server module for FOSSBilling.

It provides the BlueOnyx hosting plan editor, lifecycle integration for
BlueOnyx Vsites, customer-facing SSL status and Let's Encrypt actions, and
the required theme overrides for the FOSSBilling UI. Let’s Encrypt requests
are queued from the customer interface and processed by the regular
FOSSBilling admin cron run so that long-running BlueOnyx work does not block
the browser request.

BlueOnyx project information: https://www.blueonyx.it
BlueOnyx core repository: https://github.com/MichaelStauber/BlueOnyx

## What is in this repository

- the FOSSBilling module under `modules/Blueonyx/`
- the BlueOnyx server manager under `library/Server/Manager/Blueonyx.php`
- the release packager in `build.sh`
- the changelog and release notes
- the supporting documentation under `docs/`

## Requirements

- FOSSBilling with module installation support
- a working BlueOnyx APIv2 endpoint
- the BlueOnyx server manager from this repository

## Installation

Install this module through the normal FOSSBilling module workflow, or deploy
the repository contents into a matching FOSSBilling installation.

The module install hook deploys the BlueOnyx-specific template overrides
automatically. No changes to FOSSBilling core files are required.

## Uninstallation

The module uninstall hook removes the BlueOnyx-specific `html_custom`
overrides again. Existing hosting plans and services are not removed by the
module itself.

## Versioning

This repository follows semantic versioning.

- `modules/Blueonyx/manifest.json` contains the module version
- `CHANGELOG.md` records user-facing changes
- Git tags use the form `v<version>`, for example `v0.2.2`

## Release artifact

The packaged download is built from the repository root and is named:

`fossbilling-blueonyx-server-module-<version>.zip`

Run `./build.sh` to create the release archive locally.

## Support

- Issues and pull requests belong in this repository
- BlueOnyx core changes belong in the BlueOnyx repository
- License information is provided in `LICENSE`
