[![Quality Gate Status](https://sonarcloud.io/api/project_badges/measure?project=ckoval7_fd-commander&metric=alert_status)](https://sonarcloud.io/summary/new_code?id=ckoval7_fd-commander)
[![Security Rating](https://sonarcloud.io/api/project_badges/measure?project=ckoval7_fd-commander&metric=security_rating)](https://sonarcloud.io/summary/new_code?id=ckoval7_fd-commander)
![Website](https://img.shields.io/website?url=https%3A%2F%2Ffielddaycommander.org%2F)
![Website](https://img.shields.io/website?url=https%3A%2F%2Fdemo.fielddaycommander.org%2F&label=demo)
![GitHub License](https://img.shields.io/github/license/ckoval7/fielddaycommander?v=1)


# Field Day Commander

Field Day Commander helps ham radio clubs run ARRL Field Day and Winter Field Day. Log contacts, schedule volunteers, track equipment, manage safety checklists, and follow scores from phones, tablets, and laptops on your local network.

It runs on modest hardware, including a Raspberry Pi 4, and works without an internet connection after installation.

[Check out the website](https://fielddaycommander.org) | [Try the live demo](https://demo.fielddaycommander.org/)

## Key Features

- **Real-time contact logging:** multiple operators log QSOs simultaneously with live dupe checking across all stations and bands
- **External logger integration:** receive contacts over UDP from N1MM Logger+, WSJT-X/JTDX, fldigi, and other loggers that send ADIF over UDP, so operators don't have to log each contact twice
- **ADIF file import:** upload .adi/.adif files through a guided wizard with station mapping, validation, and review before import
- **Live scoring:** scores update automatically under your event's rules, tracking bonus points for ARRL Field Day and objective multipliers for Winter Field Day
- **Section map:** interactive map of all 86 ARRL/RAC sections, colored by band, QSO count, or recency, updated in real time
- **Station management:** define operating positions, assign equipment, and track which stations are active
- **Volunteer scheduling:** build shift schedules with gap detection and operator self-sign-up
- **Equipment tracking:** catalog your equipment, commit it to events, assign it to stations, and track it from arrival through return
- **Visitor guestbook:** track visitors for ARRL bonus points with location-based check-in and category tracking
- **NTS message traffic:** log radiograms, track originated/relayed/received messages, and capture the Section Manager message for bonus points
- **Photo uploads:** capture the event from any device on the network, with photos stored on your server
- **Role-based access:** four roles (System Admin, Event Manager, Station Captain, Operator) with fully customizable permissions
- **Safety checklist:** built-in 15-item ARRL safety checklist with required/complete tracking
- **Cabrillo export:** generate submission-ready files when the event wraps up
- **Logging during network interruptions:** if the server connection drops, contacts queue in the browser and sync automatically when connectivity returns
- **Offline operation:** logging and event management work without internet access after installation
- **Runs on a Pi:** tested on Raspberry Pi 4 with 2 GB of RAM running off a battery pack

## Getting Started

### Automated Setup

To install on a fresh Ubuntu 22.04+ or Debian 12 server, download the setup script:

```bash
curl -fLO https://raw.githubusercontent.com/ckoval7/fielddaycommander/main/deploy.sh
```

Run it with your server's hostname or IP address:

```bash
sudo bash deploy.sh --domain fdcommander.local --repo-url https://github.com/ckoval7/fielddaycommander.git
```

The script installs dependencies, configures the database, builds assets, and sets up the web server.

For a Docker installation, follow [DOCKER.md](DOCKER.md).

### Hardened systems

`deploy.sh` and `update.sh` also work on Rocky Linux, AlmaLinux and RHEL 9 with SELinux enforcing and with STIG or CIS profiles applied. Each step below runs only when that feature is present on the server:

- **SELinux:** The scripts add persistent file-context rules with `semanage fcontext` and `restorecon`, never `chcon`. `.env` is labeled `etc_t` because systemd reads it before it starts the service. The rest of the install keeps the policy defaults. If `semanage` is missing, the scripts install `policycoreutils-python-utils`. The web server is expected to run as `unconfined_service_t`, which you can check with `ps -eZ | grep frankenphp`. If it runs confined as `httpd_t`, the deploy labels `storage/` and `bootstrap/cache` writable and adds the web port. The scripts never switch SELinux to permissive.
- **fapolicyd:** The scripts trust the FrankenPHP and Composer binaries and add `/etc/fapolicyd/rules.d/39-fdcommander.rules`, so PHP, FrankenPHP and Node can load the app's files under the install path.
- **`/tmp` mounted `noexec`:** Composer and npm use `/var/lib/fd-commander/deploy-tmp` instead, and the script removes it afterward.
- **Restrictive umask (077):** Files get explicit owners and modes.
- **firewalld:** The scripts open only the web port.

If the web server isn't running at the end, `deploy.sh` prints its recent log lines and any SELinux denials (from `ausearch -m AVC -ts recent`), then exits with an error. If a deploy still fails, include the deploy log at `/var/log/fd-commander-deploy.log` and the `ausearch` output in your bug report.

### Backups

`backup.sh` saves the database, uploaded photos, and `.env` file. Keep `.env` with your backups: its `APP_KEY` is needed to restore two-factor logins. Use `restore.sh` to restore a backup.

Turn on hourly database and nightly full backups with:

```bash
sudo /var/www/fd-commander/backup.sh --install-schedule
```

Run either script with `--help` for options. Docker installs: see [DOCKER.md](DOCKER.md#backups).

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

Built with Laravel 13, Livewire 4, and Mary UI. FrankenPHP serves the app through Laravel Octane, and Laravel Reverb provides live updates.

## Contributing

Contributions are welcome. Branch from `dev` and open your pull request against `dev`. A PR opened against `main` is moved to `dev` automatically. [CONTRIBUTING.md](CONTRIBUTING.md) covers the branch flow, tests, and formatting.

## License

The code is licensed under the GNU General Public License, version 3 or (at your option) any later version. See [LICENSE](LICENSE).

Images and documentation, including this README and everything in `docs/`, are licensed under [Creative Commons Attribution-NonCommercial-ShareAlike 4.0](LICENSES/CC-BY-NC-SA-4.0.txt).

Some files started from MIT-licensed upstream code (the Laravel skeleton, and config and stubs published by Fortify, Octane, Reverb, laravel-dompdf, laravel-permission and Pest). Those files keep the upstream copyright notice in their headers, and the MIT text is in [LICENSES/MIT.txt](LICENSES/MIT.txt).

Every file declares its license with an SPDX tag, either in a header comment or in [REUSE.toml](REUSE.toml). The project follows the [REUSE](https://reuse.software) specification.

## Field Day Rules

Field Day Commander includes scoring rules for ARRL Field Day and Winter Field Day. See the official rules from [ARRL](https://www.arrl.org/field-day-rules) and the [Winter Field Day Association](https://winterfieldday.org/sop.php).
