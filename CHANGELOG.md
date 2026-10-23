# Changelog

## Unreleased

### Fixed

- When a browser couldn't save queued contacts on the device (storage full, private browsing or blocked site data), the logging screen said nothing, and reloading or closing the tab while offline lost contacts that hadn't synced. A warning now stays at the top of the logging screen while contacts are waiting and can't be saved, and goes away once saving works again or the queue is empty.
- If two people asked for the photo album ZIP while one was already being built, the second person was told they'd get a notification when it was ready but never received one. Everyone who asked while the ZIP was being built now gets the notification.

### Added

- The guestbook has a kiosk mode for a shared tablet or laptop at the event. Open it from the small Kiosk mode link at the bottom of the guestbook page. It drops the header and menu, shows the form at the top with the latest entries below it, newest first, and after each entry a countdown ring on the thank-you screen clears it for the next visitor. It never fills in or credits whoever is signed in on that browser, and it marks visitors as in person. Because every visitor shares one browser, the kiosk accepts up to 60 entries an hour instead of 5.

### Changed

- Role management is now controlled by the **Manage Roles** permission instead of **Manage System Settings**, so you can let someone edit roles without giving them system preferences and site branding. Someone with only **Manage Roles** sees just the **Role Management** tab in **System Settings**. On upgrade, every role that could change system settings also gets **Manage Roles**, so nobody loses access, and the System Administrator role can't have it removed.
- Equipment menus now use **My Equipment** and **Equipment Catalog** instead of **My Catalog** and **All User Catalogs**, matching the page headings. **Settings** is now **System Settings** in the sidebar, user menu, and page heading. Sign-in and session controls use **Sign In** and **Sign Out**, including station mode's former **End Session** button.
- Guestbook visitor categories use plainer names for club members, agency representatives, ARES/RACES volunteers, and media representatives, with stored values unchanged. The printed message header now says **Radiogram** instead of **ARRL Radiogram**.

### Fixed
- Over plain HTTP on the club LAN, the copy buttons for the registration link on the Get Ready page and for generated passwords in the user reset and activate dialogs did nothing, and the password buttons still said "Copied". They now copy in that case too, and show a message if copying fails.

- A guestbook entry from an online visitor could be verified as an agency or elected official visit, which earns the visit bonus even though the visit was not in person. Verifying one from the edit window now shows a message that agency and elected official visits must be in person to count, and **Verify** on a selection skips those entries and says how many it skipped. The bonus eligible count and the bonus sidebar no longer include online entries. Entries already verified are left as they are.
- In the Role Manager, the description typed when creating a role was thrown away. It's now saved and shown under the role's name, and you can change it later with the **Edit** button on the selected role. The built-in roles get short descriptions on upgrade, unless they already have one.
- On the Clone Stations page, the target event could show blank or a different event, and accepting the default could clone stations into the wrong event or fail. It now preselects the event you're viewing.
- A screen left on the get-ready countdown stayed on 00:00:00 after the event started until someone refreshed it. It now switches to the live dashboard within a few seconds of the start time, staggered so several screens don't all reload at once.
- Opening an invitation link that was invalid, expired or already used sent you to a blank sign-in page. It now says the invitation is invalid, expired or already accepted.
- In developer mode, clicking the X on the amber testing banner did nothing. It now hides the banner and keeps it hidden for the rest of your session.
- On the event equipment dashboard, choosing only **Unassigned** in the station filter did not show **Clear Filters**, so the only way back to all stations was to reopen the filter. The button now appears.
- Downloading the Field Day Cabrillo log or the submission sheet PDF failed with a server error when the event callsign was portable, such as W1AW/1. The files now download, with the slash in the file name replaced by an underscore.
- During Winter Field Day, the transcribe screen's exchange box and help text showed a Field Day example (1423 W1AW 3A CT), but 3A is not a Winter Field Day class. The example now fits the event, such as 1423 W1AW 1H CT.
- On the logging and transcribe screens, pressing Tab in the exchange box did nothing, so keyboard users couldn't reach the band and mode controls or the log. Tab now moves on, and accepts a suggestion only when one is highlighted. On phones, screen readers had no name for the transcribe screen's back button; it now reads Change Station.
- Screen readers read the remove buttons on the logbook's active filter chips as "times" or nothing. Each now reads "Remove" and the filter name. On the equipment list, a photo thumbnail could only be opened with a mouse or touch; keyboard users can now Tab to it and press Enter.
- On a shared station computer in station mode, every page change added another set of idle-activity listeners in the browser, and they piled up. They are now removed when the page changes.
- Clearing the weather contact email or the callsign API key in System Settings and saving left the old value in place, so the old address was still sent to the National Weather Service. Blanking either field now clears it.
- Assigning equipment to a station gave no warning when the antenna or the station's radio had no bands set, or when the station had no primary radio. Those cases now show the same confirmation as an incompatible band, so you can check before assigning.
- On your Profile page, typing a wrong or expired code while turning on two-factor authentication showed no message under the code field. It now shows the error.
- Changing your password with a new password that was too weak or didn't match its confirmation blamed the current password field. The error now appears under the field that failed.

- On the Users page, **Delete** in the bulk actions bar deleted every selected account the moment you tapped it. It now asks you to confirm first.
- When the SYSTEM account was among the selected users, the bulk role, lock and delete messages counted it even though it was skipped. The messages now report only the accounts that changed, and lock, unlock and delete leave SYSTEM alone too.
- When creating a user with an invitation email and the email failed to send, the Add User dialog stayed open with the same details, and saving again said the callsign was already registered. The dialog now closes and the message says the email failed, so you can send a password reset email from the user's row.

- On the schedule screens, a shift whose only volunteer was marked a no-show was listed under the **Full** filter and sorted as filled, even though people could still sign up for it. Manage Schedule's capacity count also included no-shows. The filters, sort and counts now ignore no-shows.
- On My Shifts, a shift you never checked in to still said "Check-in opens" after it ended. It now says "Shift ended".
- On Manage Schedule, a shift that ran past midnight, such as 10 PM to 2 AM, showed only the end time, so it looked like it ended before it started. It now shows the end date too.
- Invitation emails and the accept-invitation page called the app "FD Log DB". They now say Field Day Commander.
- The Reports page described the Cabrillo download as a `.cbr` file. It now correctly says `.log`.
- The event dashboard's rescore dialog said bonuses weren't recalculated automatically. It now explains that rescoring updates existing claims and recalculates automatic awards. Claims with no match in the selected rules version become unverified and worth zero points.
- The photo upload page said photos up to 25 MB were accepted, but uploads over 12 MB failed. It now says 12 MB.
- The setup wizard and **Site Branding** said SVG logos were accepted, but they were rejected on upload. They now say PNG or JPG, and the file picker no longer offers SVG files.
- Photo uploads over 12 MB failed even though the upload page said photos up to 25 MB were accepted. Uploads up to 25 MB now work. Helm installs behind ingress-nginx also lift the ingress's 1 MB request limit to 35 MB, so photo uploads there are no longer rejected.
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
- The Weather page showed only "No weather data yet" while a local weather alert was active but no forecast had loaded, such as with no internet. Active alerts now show whether or not there is a forecast.
- With metric units, the Weather page highlighted wind gusts from 25 km/h, well below the 25 mph it uses otherwise. Gusts are now highlighted from 40 km/h, the same as 25 mph.
- On the Scoring page, when a station was set to more power than the event's power setting, the **Multiplier Rules** table highlighted the row for the event's setting instead of the one the multiplier came from. When the event was set to 5W or less on natural power but a station used commercial or generator power, the page said the event qualified for 5× even though it scored 2×. The table and the explanation now match the multiplier.
- After you filtered the logbook, **Export CSV** ignored the filters and included contacts outside the list you were viewing. When you browsed a past event, including through the Reports page's **Review in logbook** link, the CSV could come from the current event instead. The CSV now matches exactly the contacts listed for the event shown and follows filter changes without reloading the page.
- For a Winter Field Day event, the **Scoring** tab on the event dashboard showed the ARRL Field Day formula (QSO points × power multiplier + bonuses), listed mode points from Field Day rules, and showed objectives as points. It now shows the Winter Field Day formula, QSO points × (OM + 1), with mode points and objective multipliers from the event's rules, and the event overview no longer shows a power multiplier.

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
