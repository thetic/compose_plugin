# User Guide

## Managing Stacks

### Creating a Stack

1. Navigate to **Docker → Compose**
2. Click **Add Stack**
3. Enter a stack name and optional description
4. Edit the `compose.yaml` file
5. Click **Compose Up**

### Stack Editor

The editor provides four tabs for managing your stack:

| Tab | Purpose |
|-----|---------|
| **Compose File** | Edit your `compose.yaml` with syntax highlighting |
| **Settings** | Configure autostart, profiles, and environment files |
| **Env** | Edit environment variables for your stack |
| **Web UI** | Add Unraid-specific labels for web UI integration |

![Editor - Compose File](images/editor-composeFile.png)

![Editor - Settings](images/editor-settings.png)

![Editor - Env](images/editor-env.png)

![Editor - Web UI Labels](images/editor-webUI.png)

### Stack Operations

Each stack supports the following actions:

| Action | Description |
|--------|-------------|
| **Compose Up** | Start all services in the stack |
| **Compose Down** | Stop and remove all containers |
| **Update Stack** | Pull latest images and recreate containers |
| **Edit Stack** | Open the stack editor |
| **Remove Stack** | Delete the stack configuration |

## Autostart

Enable autostart to have stacks start automatically when the Unraid array starts.

1. Click the autostart toggle on a stack
2. Optionally configure default profiles for autostart
3. Stacks will start in order when the array starts

### Force Recreate

Enable "Autostart Force Recreate" in settings to always recreate containers during autostart.

### Recreate After Label Changes

When Unraid-specific labels are modified via the Web UI, Compose Manager can optionally recreate affected containers so updated label metadata is applied without manual recreation steps.

### Stack Recheck

Use the stack "Recheck" action in the UI to re-evaluate a stack's state on the server. Results are persisted server-side to help with diagnostics and automated checks.

### Backup / Restore

Compose Manager provides a Backup & Restore interface under **Settings → Compose → Backup / Restore**:
- **Create Backup** - Create a compressed archive of selected stacks and configuration
- **List / Browse Backups** - Inspect available backup archives and their contents
- **Restore** - Restore selected stacks from an archive (select which stacks to restore)
- **Schedule** - Configure periodic backups with frequency and retention

### Hiding and Filtering Compose Containers

You can optionally hide compose-managed containers from the native Docker manager and Dashboard. Use the setting to toggle patching of the Docker page and enable server-side filters so only the desired containers/stacks are shown.

### Display Options

Enable the option to display Compose stacks above native Docker containers on the Dashboard for clearer stack-focused views.

## Environment Files

Specify custom `.env` file paths per stack in the Settings tab. This is useful when:

- Your env file is in a different location
- You want to share env files between stacks
- You have environment-specific configurations

## Indirect Stacks

Reference compose files stored outside the default projects folder. Useful for:

- Keeping compose files with your application data
- Managing compose files in version control
- Sharing configurations across servers

## Web UI Integration

### Unraid Docker Labels

Add Unraid-specific labels to integrate containers with the native Docker UI:

```yaml
services:
  myapp:
    image: myapp:latest
    labels:
      net.unraid.docker.webui: "http://[IP]:[PORT:8080]/"
      net.unraid.docker.icon: "https://example.com/icon.png"
```

The **Web UI** tab in the editor provides a visual interface for adding these labels.

### Patching the Native UI

Enable "Patch Web UI" in settings to show compose containers in the native Docker manager with stack grouping.
