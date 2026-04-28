[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=ckoval7_fd-commander&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=ckoval7_fd-commander)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=ckoval7_fd-commander&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=ckoval7_fd-commander)
![Website](https://img.shields.io/website?url=https%3A%2F%2Ffielddaycommander.org%2F)
![Website](https://img.shields.io/website?url=https%3A%2F%2Fdemo.fielddaycommander.org%2F&label=demo)
![GitHub License](https://img.shields.io/github/license/ckoval7/fielddaycommander)


# Field Day Commander

A web-based operations platform for ham radio clubs running ARRL Field Day and Winter Field Day. FD Commander goes beyond contact logging to handle the whole event: volunteer scheduling, equipment tracking, safety checklists, external logger integration, and real-time scoring with all 18 ARRL bonus categories. Runs on any device on your local network, no internet required.

Field Day Commander runs on modest hardware (including a Raspberry Pi 4) and is designed for air-gapped field deployments where reliability matters more than cloud connectivity.

[Check out the website](https://fielddaycommander.org) | [Try the live demo](https://demo.fielddaycommander.org/)

## Why Use It?

**For your club:** Multiple operators log contacts simultaneously from any phone, tablet, or laptop on the network. Scores update in real time so everyone can see how the event is going. An interactive section map shows which ARRL sections you've worked and which you still need.

**For event organizers:** Plan stations, schedule operator shifts, track equipment, manage safety checklists, and generate Cabrillo exports, all from one place. The live scoring dashboard tracks all 18 bonus categories so you can see at a glance what points you're leaving on the table.

**For operators:** A clean logging interface with real-time dupe detection. If the network hiccups, contacts you log in the web UI queue locally and sync when the connection comes back. Already using N1MM Logger+, WSJT-X, or fldigi? FD Commander receives contacts from those programs over UDP, so you don't have to double-log.

## Key Features

- **Real-time contact logging:** multiple operators log QSOs simultaneously with live dupe checking across all stations and bands
- **External logger integration:** receive contacts over UDP from N1MM Logger+, WSJT-X/JTDX, fldigi, and any logger that sends ADIF over UDP
- **ADIF file import:** upload .adi/.adif files through a guided wizard with station mapping, validation, and review before import
- **Live scoring:** scores update automatically with power multipliers and all 18 ARRL bonus categories tracked
- **Section map:** interactive map of all 86 ARRL/RAC sections, colored by band, QSO count, or recency, updated in real time
- **Station management:** define operating positions, assign equipment, and track which stations are active
- **Volunteer scheduling:** build shift schedules with gap detection and operator self-sign-up
- **Equipment tracking:** personal catalogs, event commitments, station assignments, and status tracking through the event lifecycle
- **Visitor guestbook:** track visitors for ARRL bonus points with location-based check-in and category tracking
- **NTS message traffic:** log radiograms, track originated/relayed/received messages, and capture the Section Manager message for bonus points
- **Photo uploads:** capture the event from any device on the network; everything stays on your server
- **Role-based access:** four roles (System Admin, Event Manager, Station Captain, Operator) with fully customizable permissions
- **Safety compliance:** built-in 15-item ARRL safety checklist with required/complete tracking
- **Cabrillo export:** generate submission-ready files when the event wraps up
- **Store-and-forward logging:** if the server connection drops, contacts queue in the browser and sync automatically when connectivity returns
- **Air-gapped operation:** zero external dependencies at runtime; everything runs locally after initial install
- **Runs on a Pi:** tested on Raspberry Pi 4 with 2 GB of RAM running off a battery pack

## Getting Started

### Automated Setup

The fastest path to a running instance. On a fresh Ubuntu 22.04+ or Debian 12 server:

```bash
sudo bash deploy.sh --domain yourdomain.com
```

The interactive script handles installing dependencies, configuring the database, building assets, and setting up the web server.

### Manual Setup

If you prefer to configure things yourself, or need to adapt the install to your environment, see the [documentation on the website](https://fielddaycommander.org/fd-commander-docs.html).

### System Requirements

- **OS:** Ubuntu 22.04 LTS+ or Debian 12
- **RAM:** 1 GB minimum (2+ GB recommended)
- **Disk:** 10 GB minimum (SSD preferred)
- **Database:** MySQL 8.0+ or MariaDB 10.6+

## Documentation

Full documentation, how-to guides, and FAQ are on the website: [fielddaycommander.org](https://fielddaycommander.org/fd-commander-docs.html)

## Technology

Built with Laravel 12, Livewire, and Mary UI. Served by FrankenPHP (Caddy + Laravel Octane) for persistent-process performance with automatic HTTPS. Real-time updates powered by Laravel Reverb (WebSockets).

## Contributing

Contributions are welcome! Fork the repo, create a feature branch, and submit a pull request. Please run the test suite (`php artisan test`) and code formatter (`vendor/bin/pint`) before submitting.

## License

GPL v3. See [LICENSE](LICENSE) for details.

## Field Day Rules

This application is designed to comply with current ARRL Field Day rules. See [arrl.org/field-day-rules](https://www.arrl.org/field-day-rules) for the official rules document.
