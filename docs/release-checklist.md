# Release checklist

Use this checklist before publishing a GitHub Release.

## Pre-flight

- [ ] `modules/Blueonyx/manifest.json` has the intended version
- [ ] `CHANGELOG.md` has a matching entry for that version
- [ ] `README.md` still reflects the current installation and support model
- [ ] No secrets, customer data, or machine-local files are present

## Build

- [ ] Run `./build.sh`
- [ ] Verify the ZIP name matches the manifest version
- [ ] Inspect the ZIP contents once

## Publish

- [ ] Create a Git tag in the form `v<version>`
- [ ] Publish a GitHub Release with the ZIP asset
- [ ] Attach a short release note that links to the changelog

## After release

- [ ] Verify the release asset downloads correctly
- [ ] Confirm the repository default branch still builds cleanly
