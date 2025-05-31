# ChatPerms [![](https://poggit.pmmp.io/shield.state/ChatPerms)](https://poggit.pmmp.io/p/ChatPerms)

A comprehensive chat and permissions management plugin for PocketMine-MP servers with advanced moderation tools, analytics, and faction support.

## Features

- Custom chat formats for different groups
- Customizable nametags with health display and faction tags
- Dynamic permission management with group inheritance
- Faction integration (supports FactionsPro, PiggyFactions, SimpleFaction, and Factions)
- Automatic detection of compatible faction plugins
- Real-time nametag updates reflecting health changes
- Mute system with temporary and permanent muting
- Chat cooldowns per group
- Chat statistics and analytics
- Temporary group assignments
- Permission debugging tools
- Easy-to-use commands for group and permission management

## Commands

### Group Management
- `/cp setgroup <player> <group>` - Assign a player to a group
- `/cp creategroup <group> <chat_format> <nametag_format> [permissions...]` - Create a new group
- `/cp removegroup <group>` - Delete a group
- `/cp listgroups` - List all groups with details

### Permission Management
- `/cp addgroupperm <group> <permission>` - Add a permission to a group
- `/cp removegroupperm <group> <permission>` - Remove a permission from a group
- `/cp checkplayer <player>` - Check player permissions and group info

### Moderation
- `/cp mute <player> [duration] [reason]` - Mute a player
- `/cp unmute <player>` - Unmute a player
- `/cp mutelist` - List all muted players

### Temporary Groups
- `/cp tempgroup <player> <group> <duration> [reason]` - Assign temporary group
- `/cp tempgroupcheck <player>` - Check temporary group status

### Statistics
- `/cp stats` - Show server chat statistics
- `/cp stats <player>` - Show player chat statistics

### Utility
- `/cp help` - Display all available commands

## Placeholders

### Chat Format
- `{PLAYER}` - Player's username
- `{MESSAGE}` - Chat message
- `{WORLD}` - Current world name
- `{FACTION}` - Player's faction (if detected)

### Nametag Format
- `{PLAYER}` - Player's username
- `{HEALTH}` - Color-coded health display
- `{WORLD}` - Current world name
- `{FACTION}` - Player's faction (if detected)

## Duration Formats

- `s` = seconds (30s = 30 seconds)
- `m` = minutes (15m = 15 minutes)
- `h` = hours (2h = 2 hours)
- `d` = days (1d = 1 day)
- `w` = weeks (1w = 1 week)
- `permanent` = never expires

## Group Inheritance

Groups can inherit permissions from parent groups using the `inherits` property in config.yml:

```yaml
groups:
  default:
    permissions:
      - chatperms.basic
  vip:
    inherits: default
    permissions:
      - chatperms.vip
  admin:
    inherits: vip
    permissions:
      - chatperms.admin
```

## Installation

1. Download the plugin from Poggit
2. Place the plugin file in your server's `plugins` folder
3. Restart your server
4. Configure the plugin in `plugins/ChatPerms/config.yml`

## Configuration Files

- `config.yml` - Main configuration
- `players.yml` - Player group assignments
- `mutes.yml` - Mute data
- `chat_stats.yml` - Chat statistics
- `temp_groups.yml` - Temporary group assignments

## Permissions

- `chatperms.command.*` - All command access
- `chatperms.command.setgroup` - Set player groups
- `chatperms.command.creategroup` - Create groups
- `chatperms.command.removegroup` - Remove groups
- `chatperms.command.addgroupperm` - Add group permissions
- `chatperms.command.removegroupperm` - Remove group permissions
- `chatperms.command.listgroups` - List groups
- `chatperms.command.checkplayer` - Check player info
- `chatperms.command.mute` - Mute players
- `chatperms.command.unmute` - Unmute players
- `chatperms.command.mutelist` - List muted players
- `chatperms.command.stats` - View statistics
- `chatperms.command.tempgroup` - Assign temporary groups
- `chatperms.command.tempgroupcheck` - Check temp group status

## Supported Faction Plugins

- FactionsPro
- PiggyFactions
- SimpleFaction
- Factions

## Support

For issues, feature requests, or contributions, please visit the GitHub repository.
