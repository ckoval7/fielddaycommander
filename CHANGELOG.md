# Changelog

Changes that someone running or using FD Commander would notice, newest release first. Versions are `YY.MM.N`, and each one matches a `vYY.MM.N` tag and Docker image. [CONTRIBUTING.md](CONTRIBUTING.md#changelog) explains what goes in here.

This changelog starts after 26.10.9. For earlier releases, see the commit history.

## Unreleased

### Added

- A **Public Service / Other** event type for marathons, parades, SKYWARN nets, ARES drills and other events that aren't scored. These events don't ask for a section, operating class, power or GOTA station, and they start with net roles such as Net Control instead of the Field Day roles. Traffic goes in the Net Log, so contact logging, the logbook and external loggers are turned off for them, along with scoring, bonuses, the section map, the bulletin page and the contest reports. Stations are called positions.
- Unscored events get a volunteer briefing checklist in place of the ARRL safety items. You can edit or delete every item, and anyone with a shift at the event can check items off.
- A **Net Log** for directed nets, on every event type, including Field Day talk-in nets. It works like the contact logger: type the station being called, the calling station and the message on one line, in the order you hear them, such as "NCS AID7 WATER LOW", and press Enter. Start the message with EMERGENCY or PRIORITY to flag it. Run several nets at once, such as a local 2m net and a regional HF net: start a net with its name, frequency, band, mode and net control station, or set nets up ahead of the event, and each net control screen picks the net it logs. The ICS-309 prints one net or all of them. New entries show up on every open net log screen. A **Net Traffic** dashboard widget shows the newest entries, and it's on the default dashboard for unscored events.
- Stations and shift roles can have a tactical callsign, such as "Aid 7" or "Net Control". The net log suggests these names and records the licensed operator on duty under each one. Stations can also store their RX and TX frequencies, tone and mode.
- An **ICS Forms** section in **Reports**. It fills FEMA's own ICS-205 radio plan and ICS-211 check-in list PDFs from your stations and shift check-ins, and prints an ICS-309 communications log from the net log. ICS-213 messages in **Message Traffic** can also be opened as FEMA's ICS-213 PDF. Each form opens in a new tab. The blank forms ship with FD Commander, so this works without internet.

### Changed

- Development now happens on the `dev` branch, and `main` only moves when there's a release. If you build from source, `main` is the latest release and `dev` has fixes that haven't been released yet.

### Fixed

- On a log with no contacts yet, the first contact you logged could stay hidden until it synced with the server. It happened when suggestions or warnings had appeared on the logging screen before that first contact.
- On screens between 1024 and 1280 pixels wide with the sidebar open, the buttons on station cards spilled into the next card and their labels were cut off.
- The SM/SEC message template for a Winter Field Day event named the event "FIELD DAY" instead of "WINTER FIELD DAY".
