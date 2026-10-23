# Changelog

## Unreleased

### Fixed

- If two people asked for the photo album ZIP while one was already being built, the second person was told they'd get a notification when it was ready but never received one. Everyone who asked while the ZIP was being built now gets the notification.

### Added

- The guestbook has a kiosk mode for a shared tablet or laptop at the event. Open it from the small Kiosk mode link at the bottom of the guestbook page. It drops the header and menu, shows the form at the top with the latest entries below it, newest first, and after each entry a countdown ring on the thank-you screen clears it for the next visitor. It never fills in or credits whoever is signed in on that browser, and it marks visitors as in person. Because every visitor shares one browser, the kiosk accepts up to 60 entries an hour instead of 5.

### Changed

- Equipment menus now use **My Equipment** and **Equipment Catalog** instead of **My Catalog** and **All User Catalogs**, matching the page headings. **Settings** is now **System Settings** in the sidebar, user menu, and page heading. Sign-in and session controls use **Sign In** and **Sign Out**, including station mode's former **End Session** button.
- Guestbook visitor categories use plainer names for club members, agency representatives, ARES/RACES volunteers, and media representatives, with stored values unchanged. The printed message header now says **Radiogram** instead of **ARRL Radiogram**.

### Fixed

- Invitation emails and the accept-invitation page called the app "FD Log DB". They now say Field Day Commander.
- The Reports page described the Cabrillo download as a `.cbr` file. It now correctly says `.log`.
- The event dashboard's rescore dialog said bonuses weren't recalculated automatically. It now explains that rescoring updates existing claims and recalculates automatic awards. Claims with no match in the selected rules version become unverified and worth zero points.
- The photo upload page said photos up to 25 MB were accepted, but uploads over 12 MB failed. It now says 12 MB.
- The setup wizard and **Site Branding** said SVG logos were accepted, but they were rejected on upload. They now say PNG or JPG.
- The logbook's **Operator** filter selected contacts by who logged them. It's now labeled **Logger**. The dashboard Info Card's **Location** row showed the ARRL/RAC section and is now labeled **Section**.
- The event dashboard's scoring note said scores updated there in real time, but the page doesn't live-update. It now says scores are calculated from logged contacts and claimed awards.
- System Settings and the setup wizard described the weather contact email as a public site contact. They now label it **Weather Contact Email** and explain that it's sent to the National Weather Service with weather requests.
- On a laptop or desktop, the guestbook's recent visitors were squeezed into narrow columns, with names, dates and comments cut off or wrapped a word per line. They now fill the column, one card per row. The form and the entry list also lost a second heading each, and on phones the form fields sit closer to the screen edge.
- When equipment committed to an event was marked lost or damaged, System Administrators received the incident notification twice, and owners who were also admins or equipment managers could receive it two or three times. Each person now receives it once.
- When running `deploy.sh` or `update.sh`, every `[INFO]`, `[WARN]`, `[ERROR]`, and phase line appeared twice in `/var/log/fd-commander-deploy.log` or `/var/log/fd-commander-update.log`, respectively. Each line now appears once, and the message shown when running without root privileges goes to stderr.
- On the station equipment assignment page, keyboard and screen reader users tabbing through **Committed to Event** or **Equipment Catalog** heard drag instructions that promised arrow-key controls that didn't work. They now tab to each card's **Assign** or **Commit & Assign** button, which names the equipment, and mouse drag and drop still works.
- When reading dashboard chart data with a screen reader, the hidden data table wasn't reliably announced as a table with column headers. It now uses an HTML table with column headers.
- Since 26.09.0, ticking **Youth (age 18 or younger)** on your own Profile page and saving left the box unticked when you returned. Operators who marked themselves as youth this way weren't counted toward the Field Day youth participation bonus. The setting now saves.

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
