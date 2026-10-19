# Changelog

Changes that someone running or using FD Commander would notice, newest release first. Versions are `YY.MM.N`, and each one matches a `vYY.MM.N` tag and Docker image. [CONTRIBUTING.md](CONTRIBUTING.md#changelog) explains what goes in here.

This changelog starts after 26.10.9. For earlier releases, see the commit history.

## Unreleased

### Changed

- Development now happens on the `dev` branch, and `main` only moves when there's a release. If you build from source, `main` is the latest release and `dev` has fixes that haven't been released yet.

### Fixed

- On a log with no contacts yet, the first contact you logged could stay hidden until it synced with the server. It happened when suggestions or warnings had appeared on the logging screen before that first contact.
- On screens between 1024 and 1280 pixels wide with the sidebar open, the buttons on station cards spilled into the next card and their labels were cut off.
