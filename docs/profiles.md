# Docker Compose Profiles

Compose Manager provides full support for [Docker Compose profiles](https://docs.docker.com/compose/profiles/), allowing you to selectively start services based on your needs.

## What are Profiles?

Profiles let you define groups of services in your `compose.yaml` that can be started together. Services without a profile are always started, while profiled services only start when their profile is explicitly activated.

## Example compose.yaml with Profiles

```yaml
services:
  # Always starts (no profile)
  webapp:
    image: nginx:latest
    ports:
      - "80:80"

  # Only starts with 'debug' profile
  debugger:
    image: busybox
    profiles:
      - debug
    command: sleep infinity

  # Only starts with 'monitoring' profile
  prometheus:
    image: prom/prometheus
    profiles:
      - monitoring
    ports:
      - "9090:9090"

  grafana:
    image: grafana/grafana
    profiles:
      - monitoring
    ports:
      - "3000:3000"
```

## Using Profiles in Compose Manager

### Auto-Detection

When you save a compose file, Compose Manager automatically detects all defined profiles and stores them. These are displayed in the stack settings panel under "Available profiles".

### Interactive Profile Selection

When you click **Compose Up**, **Compose Down**, or **Update Stack** on a stack that has profiles defined, a profile selector dialog appears allowing you to:

- Include **Default services (no profile)**, which are always part of the Compose model
- Select **All profile-based services** to enable every declared profile
- Select one or more specific profiles to include those profile-tagged services alongside the default services

The selector behavior maps to Compose like this:

- **Default services only** - no profile flag is passed
- **All profile-based services** - Compose Manager passes `--profile "*"`
- **Specific profiles selected** - Compose Manager passes one `--profile` flag per selected profile

### Default Profiles for Autostart

Configure default profiles in the stack editor's **Settings** tab:

1. Click the stack icon to open the context menu
2. Select **Edit Stack**
3. Go to the **Settings** tab
4. In the "Default Profile(s)" field, enter one or more profile names (comma-separated)

**Example:** `production,monitoring` will activate both the `production` and `monitoring` profiles.

Default profiles are used for:

- **Autostart** - When the array starts, only services matching the default profiles will start
- **Start All Stacks** - Multi-stack operations use each stack's configured default profiles
- **Stop All Stacks** - Multi-stack stop operations respect default profiles

### Profile Storage

Profiles are stored in the stack's configuration directory:

- `profiles` - JSON array of available profiles (auto-detected from compose file)
- `default_profile` - Comma-separated list of default profiles for autostart/multi-stack operations

## Override File Usage

Compose Manager uses the stack override file to store Unraid-specific metadata such as:

- WebUI URL labels
- Icon URL/path labels
- Compose Manager shell/label metadata

Because profile-tagged services are still valid services in the Compose model, Compose Manager preserves override metadata for both:

- default services with no `profiles` key
- services assigned to one or more profiles

## Tips

1. **No profile = always starts**: Services without a `profiles` key start regardless of which profiles are activated
2. **Multiple profiles**: A service can belong to multiple profiles; it starts if any of its profiles are activated
3. **Comma-separated**: Specify multiple default profiles separated by commas (e.g., `dev,debug`)
4. **Default-only is valid**: If no profiles are selected, only services without a `profiles` key are included
5. **All profile-based services**: Use the all-profiles option to include every profile-tagged service in addition to the default services
