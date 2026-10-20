# Changelog

Changes that someone running or using FD Commander would notice, newest release first. Versions are `YY.MM.N`, and each one matches a `vYY.MM.N` tag and Docker image. [CONTRIBUTING.md](CONTRIBUTING.md#changelog) explains what goes in here.

This changelog starts after 26.10.9. For earlier releases, see the commit history.

## Unreleased

### Fixed

- Since 26.10.10, the PDF reports had a white strip across the top of the first page, above the blue title banner. In the ARRL submission sheet and the Winter Field Day event summary, the same strip showed on every later page that starts with a banner. The banners reach the top edge of the page again.
- Since 26.10.10, the equipment incident report could leave a few rows alone on an extra page when the circumstances or owner names ran to several lines.
- The equipment owner contact list PDF filled only about three quarters of each page before starting the next, and an owner with several emergency phone numbers could push a row or two onto a page of their own. Pages now fill down to the footer.

## 26.10.10 - 2026-10-20

### Changed

- Development now happens on the `dev` branch, and `main` only moves when there's a release. If you build from source, `main` is the latest release and `dev` has fixes that haven't been released yet.
- PDF reports now open in a new tab instead of downloading. That covers the equipment reports, the ARRL submission sheet and the WFD event summary. Use the browser's PDF viewer to print or save one.
- Callsigns in the logbook are larger and easier to read, in both the table and the phone card view.
- When you install FD Commander as an app on a phone or computer, the install prompt now shows the full name, Field Day Commander. New installs from `deploy.sh` use the full name too.

### Fixed

- On a log with no contacts yet, the first contact you logged could stay hidden until it synced with the server. It happened when suggestions or warnings had appeared on the logging screen before that first contact.
- On screens between 1024 and 1280 pixels wide with the sidebar open, the buttons on station cards spilled into the next card and their labels were cut off.
- In the equipment report PDFs, rows at the bottom of a page could print underneath the page footer, and the next page started right at the top edge of the paper. In the station inventory, a station's equipment list could also be split across pages, leaving its heading behind at the bottom of the previous page.
- In the ARRL submission sheet and the Winter Field Day event summary, a long bulletin copy ran underneath the page footer and onto the top edge of the next page. The submission sheet's footer also showed the wrong page total, such as "Page 4 of 3", when the bulletin needed more than one page.
- During Winter Field Day, the guestbook manager, the guestbook and the event dashboard offered Field Day's elected official and served agency visit bonuses, which Winter Field Day doesn't award. Those visitors are still logged, just without bonus points.
