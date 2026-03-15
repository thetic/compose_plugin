# Compose Manager

Compose Manager installs the Docker Compose CLI plugin on your unRAID server and provides a comprehensive web-based interface to create, run, update, back up, and restore Compose stacks directly from the unRAID dashboard. It includes per-stack autostart with configurable options, an integrated terminal for live command output, optional UI integration to hide Compose-managed containers, and tooling for testing and CI.

## Screenshots

### Main Compose Manager Interface

![Compose Manager UI](docs/images/compose.png)

### Dashboard Integration

![Dashboard Stacks](docs/images/dashboard.png)

### Stack Editor

The built-in editor provides multiple tabs for managing your compose stack:

| Compose File | Settings |
|:------------:|:--------:|
| ![Editor - Compose File](docs/images/editor-composeFile.png) | ![Editor - Settings](docs/images/editor-settings.png) |

| Env | Web UI Labels |
|:---:|:-------------:|
| ![Editor - Env](docs/images/editor-env.png) | ![Editor - Web UI](docs/images/editor-webUI.png) |

## Features

- **Docker Compose Integration** - Installs the Docker Compose CLI plugin and manages stacks on your unRAID server.
- **Web UI Management** - Create, edit, and manage Compose stacks directly from the unRAID dashboard.
- **Stack Operations** - Start, stop, update, and remove stacks with one click (supports profiles and override files).
- **Autostart & Shutdown** - Configurable autostart with wait/recreate options and improved shutdown handling.
- **Visibility & Filtering** - Optionally hide or filter Compose-managed containers in the native Docker UI (behavior varies by unRAID version).
- **Backup & Restore** - Manual and scheduled backups with selective restore from the UI.
- **Web Terminal** - Integrated terminal for live, colorized compose command output.
- **Developer & Testing Tools** - Unit tests and CI workflows to help contributors and ensure quality.

## Installation

~~Install via the Community Applications plugin in unRAID~~, or manually install by navigating to:

**Plugins → Install Plugin** and entering the plugin URL:

```
https://raw.githubusercontent.com/mstrhakr/compose_plugin/main/compose.manager.plg
```

## Requirements

- unRAID 6.9.0 or later
- Docker service enabled

## Configuration

Access **Settings → Compose** in the unRAID web UI. Key options:

- **Output Style** — Terminal (ttyd) for live, colorized command output or Basic for simpler logs.
- **Projects Folder** — Default: `/boot/config/plugins/compose.manager/projects`. Changing it does not move existing projects.
- **Autostart** — Enable per-stack autostart; optional force recreate and wait-for-Docker behavior with configurable timeouts.
- **Hide Compose Containers** — Patch (Unraid 6.12–7.2) that removes Compose-managed containers from the Docker page and Dashboard tile to avoid duplicate entries.
- **Debug to Log** — Send debug output to syslog for troubleshooting.

## Usage

### Creating a Stack

1. Navigate to **Docker → Compose** (or **Docker Compose** if header menu option is enabled)
2. Click **Add Stack**
3. Enter a name and optionally a description
4. Edit the compose.yaml file using the built-in editor
5. Click **Compose Up** to start the stack

### Managing Stacks

Each stack provides the following actions:

- **Compose Up** - Start the stack (with optional profile selection)
- **Compose Down** - Stop and remove containers
- **Update Stack** - Pull latest images and recreate containers
- **Edit Stack** - Modify the compose.yaml file
- **Remove Stack** - Delete the stack configuration

### Autostart

Enable autostart for a stack by clicking the autostart toggle. Stacks will automatically start when the unRAID array starts.

## Documentation

For detailed guides, see the [docs](docs/) folder:

- [Getting Started](docs/getting-started.md)
- [User Guide](docs/user-guide.md)
- [Configuration](docs/configuration.md)
- [Profiles](docs/profiles.md)

## Development & Testing 🔧

Quick start:

- Install PHP dependencies: `composer install` (required to install PHPUnit and tooling).
- Run unit tests: `php vendor/bin/phpunit --config phpunit.xml` (or `php vendor/bin/phpunit --testsuite unit`).
- Run a single test file: `php vendor/bin/phpunit --config phpunit.xml tests/unit/ExampleTest.php`.

Integration tests:

- Add and initialize the `plugin-tests` submodule (if not already present):
  - If the submodule is configured: `git submodule update --init --recursive`
  - Or add it manually: `git submodule add https://github.com/mstrhakr/plugin-tests.git tests/plugin-tests && git submodule update --init --recursive`
- See `tests/plugin-tests/README.md` for running integration suites and environment setup.

Static analysis:

- `composer run analyse` (runs PHPStan configured for this project).

For full developer setup, test running, and contribution guidelines see `docs/development.md`. (Contains examples for coverage, CI notes, and integration testing tips.)

Contributions are welcome — we added issue and PR templates to guide reports and pull requests. Please follow the templates when submitting issues or PRs.

## Support

- [GitHub Issues](https://github.com/mstrhakr/compose_plugin/issues)
- [unRAID Forums](https://forums.unraid.net/)

## License

This project is open source. See the repository for license details.

## Credits

Originally created by **dcflachs**. This fork maintained by **mstrhakr**.
Huge thanks to the entire Unraid community, without you this would be impossible.
