# Changelog

## Unreleased

### Added

- A **Public Service / Other** event type for marathons, parades, SKYWARN nets, ARES drills and other events that aren't scored. These events don't ask for a section, operating class, power or GOTA station, and they start with net roles such as Net Control instead of the Field Day roles. Traffic goes in the Net Log, so contact logging, the logbook and external loggers are turned off for them, along with scoring, bonuses, the section map, the bulletin page and the contest reports. Stations are called positions.
- Unscored events get a volunteer briefing checklist in place of the ARRL safety items. You can edit or delete every item, and anyone with a shift at the event can check items off.
- A **Net Log** for directed nets, on every event type, including Field Day talk-in nets. It works like the contact logger: type the station being called, the calling station and the message on one line, in the order you hear them, such as "NCS AID7 WATER LOW", and press Enter. Start the message with EMERGENCY or PRIORITY to flag it. Run several nets at once, such as a local 2m net and a regional HF net: start a net with its name, frequency, band, mode and net control station, or set nets up ahead of the event, and each net control screen picks the net it logs. The ICS-309 prints one net or all of them. New entries show up on every open net log screen. A **Net Traffic** dashboard widget shows the newest entries, and it's on the default dashboard for unscored events.
- Stations and shift roles can have a tactical callsign, such as "Aid 7" or "Net Control". The net log suggests these names and records the licensed operator on duty under each one. Stations can also store their RX and TX frequencies, tone and mode.
- A **Stations** panel above the net log shows station locations, operators on duty, and how long since each station was last heard on any net at the event. Set **Overdue after (minutes)** for a net to flag quiet stations as overdue, with ages and flags updating without reloading. Station locations also appear in the ICS-205 Remarks column after the mode.
- A **Roll call** button on the Net Log counts stations as accounted for when you tap them or log their answers, with **Undo** for mistaken taps. The start, PAR entries, and final count appear in the net log and on the ICS-309, with any stations still missing named in the result.
- A **Station Status** list for the dashboard shows stations with a tactical callsign or location, their operators on duty, and how long since they were last heard, with overdue stations first and **Overdue** or **Out of service** badges. It updates as net traffic is logged, appears beside **Net Traffic** on the default dashboard for unscored events, and is only shown to signed-in users, never on the public TV dashboard.
- Your profile now has an optional **Phone** field and a **Credentials** card for IS-100, IS-200, IS-700, IS-800, AUXCOMM, COML, and ARES, with optional expiry dates and an ARES level. The ICS-211 check-in list uses your phone number as your contact and lists your unexpired credentials under Other Qualifications, such as "IS-100, ARES L2".
- An **ICS-205A Comms List** card in **Reports > ICS Forms** fills FEMA's communications list PDF with everyone who has a shift at the event, except no-shows, once per role and sorted by last name. It includes their role and tactical callsign, name and callsign, profile phone and email, and unexpired credentials, with 34 entries per page and extra pages as needed.
- An **ICS Forms** section in **Reports**. It fills FEMA's own ICS-205 radio plan and ICS-211 check-in list PDFs from your stations and shift check-ins, and prints an ICS-309 communications log from the net log. ICS-213 messages in **Message Traffic** can also be opened as FEMA's ICS-213 PDF. Each form opens in a new tab. The blank forms ship with FD Commander, so this works without internet. Long entries in one-line boxes on the ICS-205, ICS-205A, ICS-211, and ICS-213 print smaller to fit, down to 5 points, so more of a long club name fits in the ICS-211 Agency box.
- The guestbook has a kiosk mode for a shared tablet or laptop at the event. Open it from the small Kiosk mode link at the bottom of the guestbook page. It drops the header and menu, shows the form at the top with the latest entries below it, newest first, and after each entry a countdown ring on the thank-you screen clears it for the next visitor. It never fills in or credits whoever is signed in on that browser, and it marks visitors as in person. Because every visitor shares one browser, the kiosk accepts up to 60 entries an hour instead of 5.

### Fixed

- On a laptop or desktop, the guestbook's recent visitors were squeezed into narrow columns, with names, dates and comments cut off or wrapped a word per line. They now fill the column, one card per row. The form and the entry list also lost a second heading each, and on phones the form fields sit closer to the screen edge.
- When equipment committed to an event was marked lost or damaged, System Administrators received the incident notification twice, and owners who were also admins or equipment managers could receive it two or three times. Each person now receives it once.
- When running `deploy.sh` or `update.sh`, every `[INFO]`, `[WARN]`, `[ERROR]`, and phase line appeared twice in `/var/log/fd-commander-deploy.log` or `/var/log/fd-commander-update.log`, respectively. Each line now appears once, and the message shown when running without root privileges goes to stderr.
- On the station equipment assignment page, keyboard and screen reader users tabbing through **Committed to Event** or **All Equipment Catalog** heard drag instructions that promised arrow-key controls that didn't work. They now tab to each card's **Assign** or **Commit & Assign** button, which names the equipment, and mouse drag and drop still works.
- When reading dashboard chart data with a screen reader, the hidden data table wasn't reliably announced as a table with column headers. It now uses an HTML table with column headers.
- The SM/SEC message template for a Winter Field Day event named the event "FIELD DAY" instead of "WINTER FIELD DAY".

## 26.10.11 - 2026-10-21

### Changed

- Cabrillo and ADIF exports now name the program as Field Day Commander instead of FD Commander. So do the service names in `systemctl status`, the backup timers, and the messages from `deploy.sh`, `update.sh`, `backup.sh`, `restore.sh` and the Docker container. Services pick up the new names on the next `update.sh` run.

### Security

- PHP2Shell (CVE-2026-109618 and CVE-2026-109619) let anyone who could reach the server run code on it without signing in. FD Commander now runs on FrankenPHP 1.13.2, which is built with patched PHP. Update with `update.sh` or pull the new Docker image as soon as you can. Installs also turn off PHP's session upload progress tracking, which FD Commander never used.


### Fixed

- Since 26.10.10, the PDF reports had a white strip across the top of the first page, above the blue title banner. In the ARRL submission sheet and the Winter Field Day event summary, the same strip showed on every later page that starts with a banner. The banners reach the top edge of the page again.
- Since 26.10.10, the equipment incident report could leave a few rows alone on an extra page when the circumstances or owner names ran to several lines.
- The equipment owner contact list PDF filled only about three quarters of each page before starting the next, and an owner with several emergency phone numbers could push a row or two onto a page of their own. Pages now fill down to the footer.
- The dashboard widget size menus offered 4 columns, but the dashboard is only 3 columns wide. Picking 4 squeezed in an extra narrow column and shuffled every widget around. The menus now go up to 3, and widgets already set to 4 show as full width.
- While editing a dashboard on a phone or tablet, the widget toolbars slid over the top bar as you scrolled. They now scroll under it.
- The widget resize menu's column labels wrapped onto two lines on phones. They now read 1, 2 and 3, with a note that phones always show one column.
- On touchscreens, the sidebar's event switcher, its expandable sections such as Equipment, and the scroll-down arrow were smaller than the other menu rows and easy to miss. They're now full-size touch targets.

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
