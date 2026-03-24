# Field Day Commander

A web-based operations hub for ham radio clubs running ARRL Field Day. Manage contacts, scoring, stations, operators, and equipment from any device on your local network, no internet required.

Field Day Commander runs on modest hardware (including a Raspberry Pi 4) and is designed for air-gapped field deployments where reliability matters more than cloud connectivity.

## Why Use It?

**For your club:** Multiple operators log contacts simultaneously from any phone, tablet, or laptop on the network. Scores update in real time so everyone can see how the event is going.

**For event organizers:** Plan stations, schedule operator shifts, track equipment, manage safety checklists, and generate post-event reports and Cabrillo exports, all from one place.

**For operators:** A clean logging interface with realtime dupe detection. Just sit down and start making contacts.

## Key Features

- **Real-time contact logging,** multiple operators log QSOs simultaneously with live dupe checking
- **Live scoring,** scores update automatically as contacts are logged, with power multipliers and bonus tracking
- **Station management,** define operating positions, assign equipment, and track which stations are active
- **Volunteer scheduling,** build shift schedules and assign volunteers to stations and other roles
- **Equipment tracking,** catalog personal and club-owned gear, commit it to events, and track status through the event lifecycle
- **Role-based access,** four roles (System Admin, Event Manager, Station Captain, Operator) with appropriate permissions at each level
- **Safety compliance,** built-in site safety checklist with completion tracking
- **Cabrillo export,** generate submission-ready files when the event wraps up
- **Air-gapped operation,** zero external dependencies at runtime; everything runs locally
- **Runs on a Pi,** tested on Raspberry Pi 4 (4GB), Intel NUC, and standard Linux servers

## Getting Started

### Automated Setup

The fastest path to a running instance. On a fresh Ubuntu 22.04+ or Debian 12 server:

```bash
# Setup Script COming Soon
```

The interactive script handles installing dependencies, configuring the database, building assets, and setting up the web server.

### Manual Setup

If you prefer to configure things yourself, or need to adapt the install to your environment, follow the step-by-step deployment guides in the `docs/guides/` directory:

1. **Environment Preparation,** install PHP 8.3+, MySQL/MariaDB, Nginx, and Node.js
2. **Application Deployment,** deploy the app, configure SSL, and start background services
3. **Quick Start Guide,** first login, initial configuration, and creating your first event

### System Requirements

- **OS:** Ubuntu 22.04 LTS+ or Debian 12
- **RAM:** 1 GB minimum (2+ GB recommended)
- **Disk:** 10 GB minimum (SSD preferred)
- **Database:** MySQL 8.0+ or MariaDB 10.6+

## Documentation

Full documentation lives in the `docs/guides/` directory:

| Guide | Description |
|-------|-------------|
| Environment Preparation | Server and dependency setup |
| Application Deployment | App installation and web server configuration |
| Quick Start Guide | First-run walkthrough for new installations |
| System Overview | Architecture, roles, event lifecycle, and navigation |
| Glossary | Definitions of Field Day and app-specific terms |

## Technology

Built with Laravel 12, Livewire, and Mary UI. Real-time updates powered by Laravel Reverb (WebSockets). See the [System Overview](docs/04-system-overview.md) for architecture details.

## Contributing

Contributions are welcome! Fork the repo, create a feature branch, and submit a pull request. Please run the test suite (`php artisan test`) and code formatter (`vendor/bin/pint`) before submitting.

## License

GPL v3. See [LICENSE](LICENSE) for details.

## Field Day Rules

This application is designed to comply with current ARRL Field Day rules. See [arrl.org/field-day-rules](https://www.arrl.org/field-day-rules) for the official rules document.
