<?php

declare(strict_types=1);

namespace ChatPerms;

use pocketmine\plugin\PluginBase;
use pocketmine\event\Listener;
use pocketmine\player\Player;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\permission\Permission;
use pocketmine\permission\PermissionManager;
use pocketmine\utils\Config;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\PermissionAttachment;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\utils\TextFormat;
use pocketmine\scheduler\ClosureTask;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\plugin\PluginManager;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;

class Main extends PluginBase implements Listener {
    private $config;
    private $groups;
    private $playerGroups;
    private $playerAttachments = [];
    private $factionPlugin = null;
    private $messages;
    
    private $muteData;
    private $lastChatTime = [];
    private $chatStats;
    private $tempGroups;

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        $this->groups = $this->config->get("groups", []);
        $this->playerGroups = new Config($this->getDataFolder() . "players.yml", Config::YAML);
        $this->muteData = new Config($this->getDataFolder() . "mutes.yml", Config::YAML);
        $this->chatStats = new Config($this->getDataFolder() . "chat_stats.yml", Config::YAML);
        $this->tempGroups = new Config($this->getDataFolder() . "temp_groups.yml", Config::YAML);
        
        if (!$this->config->exists("messages")) {
            $this->config->set("messages", [
                "no_permission" => "§cYou don't have permission to use this command.",
                "player_not_found" => "§cPlayer not found.",
                "group_not_found" => "§cGroup not found.",
                "group_already_exists" => "§cGroup already exists.",
                "permission_already_exists" => "§cPermission already exists in the group.",
                "permission_not_found" => "§cPermission not found in the group.",
                "group_created" => "§aGroup §6{GROUP} §ahas been created.",
                "group_removed" => "§aGroup §6{GROUP} §ahas been removed.",
                "player_group_set" => "§aPlayer §b{PLAYER} §ahas been set to group §6{GROUP}",
                "permission_added" => "§aPermission §b{PERMISSION} §ahas been added to group §6{GROUP}",
                "permission_removed" => "§aPermission §b{PERMISSION} §ahas been removed from group §6{GROUP}",
                "faction_detected" => "§aDetected faction plugin: {PLUGIN}",
                "no_faction_detected" => "§cNo compatible faction plugin detected.",
                "unknown_command" => "§cUnknown command: {COMMAND}. Use /cp help for help.",
                "player_muted" => "§cYou are muted! Reason: {REASON}. Time remaining: {TIME}",
                "player_muted_permanent" => "§cYou are permanently muted! Reason: {REASON}",
                "mute_success" => "§aSuccessfully muted §b{PLAYER} §afor {DURATION}. Reason: {REASON}",
                "mute_success_permanent" => "§aSuccessfully permanently muted §b{PLAYER}. Reason: {REASON}",
                "unmute_success" => "§aSuccessfully unmuted §b{PLAYER}",
                "player_not_muted" => "§cPlayer {PLAYER} is not muted.",
                "chat_cooldown" => "§cPlease wait {SECONDS} more seconds before sending another message.",
                "already_muted" => "§cPlayer {PLAYER} is already muted.",
                "invalid_duration" => "§cInvalid duration format. Use examples: 1m, 5h, 1d, 1w",
                "tempgroup_expired" => "§aYour temporary group §6{GROUP} §ahas expired. You have been restored to your original group."
            ]);
            $this->config->save();
        }
        
        $this->messages = $this->config->get("messages");

        $this->registerPermissions();
        $this->startNameTagUpdateTask();
        $this->startMuteCheckTask();
        $this->startTempGroupCheckTask();
        
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(
            function(): void {
                $this->detectFactionPlugin();
            }
        ), 20);

        if (!$this->config->exists("rename_nametag")) {
            $this->config->set("rename_nametag", true);
            $this->config->save();
        }
    }

    private function startMuteCheckTask(): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->checkExpiredMutes();
            }
        ), 1200);
    }

    private function checkExpiredMutes(): void {
        $currentTime = time();
        $mutes = $this->muteData->getAll();
        $updated = false;
        foreach ($mutes as $playerName => $muteInfo) {
            if (isset($muteInfo['expires']) && $muteInfo['expires'] > 0 && $muteInfo['expires'] <= $currentTime) {
                $this->muteData->remove($playerName);
                $updated = true;
                $player = $this->getServer()->getPlayerExact($playerName);
                if ($player !== null) {
                    $player->sendMessage("§aYour mute has expired. You can now chat again.");
                }
            }
        }
        if ($updated) {
            $this->muteData->save();
        }
    }

    private function detectFactionPlugin(): void {
        $pluginManager = $this->getServer()->getPluginManager();
        $factionPlugins = ['FactionsPro', 'PiggyFactions', 'SimpleFaction', 'Factions'];
        foreach ($factionPlugins as $pluginName) {
            $plugin = $pluginManager->getPlugin($pluginName);
            if ($plugin !== null && $plugin->isEnabled()) {
                $this->factionPlugin = $plugin;
                $this->getLogger()->info($this->getMessage("faction_detected", ["PLUGIN" => $pluginName]));
                return;
            }
        }
        $this->getLogger()->info($this->getMessage("no_faction_detected"));
    }

    private function startNameTagUpdateTask(): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                foreach ($this->getServer()->getOnlinePlayers() as $player) {
                    $this->updatePlayerNameTag($player);
                }
            }
        ), 20);
    }

    private function registerPermissions(): void {
        $permManager = PermissionManager::getInstance();
        foreach ($this->groups as $group => $data) {
            $groupPermName = "chatperms.group." . $group;
            if ($permManager->getPermission($groupPermName) === null) {
                $permManager->addPermission(new Permission($groupPermName));
            }
            foreach ($data['permissions'] as $perm) {
                if ($permManager->getPermission($perm) === null) {
                    $permManager->addPermission(new Permission($perm));
                }
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool {
        if ($command->getName() !== "cp" && $command->getName() !== "chatperms") {
            return false;
        }
        if (count($args) === 0) {
            return $this->handleHelpCommand($sender);
        }
        $subCommand = strtolower(array_shift($args));
        switch($subCommand) {
            case "setgroup":
                return $this->handleSetGroupCommand($sender, $args);
            case "creategroup":
                return $this->handleCreateGroupCommand($sender, $args);
            case "removegroup":
                return $this->handleRemoveGroupCommand($sender, $args);
            case "addgroupperm":
                return $this->handleAddGroupPermCommand($sender, $args);
            case "removegroupperm":
                return $this->handleRemoveGroupPermCommand($sender, $args);
            case "listgroups":
                return $this->handleListGroupsCommand($sender, $args);
            case "checkplayer":
                return $this->handleCheckPlayerCommand($sender, $args);
            case "mute":
                return $this->handleMuteCommand($sender, $args);
            case "unmute":
                return $this->handleUnmuteCommand($sender, $args);
            case "mutelist":
                return $this->handleMuteListCommand($sender, $args);
            case "stats":
                return $this->handleStatsCommand($sender, $args);
            case "tempgroup":
                return $this->handleTempGroupCommand($sender, $args);
            case "tempgroupcheck":
                return $this->handleTempGroupCheckCommand($sender, $args);
            case "help":
                return $this->handleHelpCommand($sender);
            default:
                $sender->sendMessage($this->getMessage("unknown_command", ["COMMAND" => $subCommand]));
                return true;
        }
    }

    private function handleMuteCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.mute")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) < 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp mute <player> [duration] [reason]");
            return true;
        }

        $playerName = $args[0];
        $duration = $args[1] ?? "permanent";
        $reason = isset($args[2]) ? implode(" ", array_slice($args, 2)) : "No reason specified";

        if ($this->isPlayerMuted($playerName)) {
            $sender->sendMessage($this->getMessage("already_muted", ["PLAYER" => $playerName]));
            return true;
        }

        $expireTime = 0;
        if ($duration !== "permanent") {
            $expireTime = $this->parseDuration($duration);
            if ($expireTime === false) {
                $sender->sendMessage($this->getMessage("invalid_duration"));
                return true;
            }
            $expireTime += time();
        }

        $muteData = [
            "reason" => $reason,
            "muted_by" => $sender->getName(),
            "muted_at" => time(),
            "expires" => $expireTime
        ];

        $this->muteData->set($playerName, $muteData);
        $this->muteData->save();

        if ($expireTime > 0) {
            $sender->sendMessage($this->getMessage("mute_success", [
                "PLAYER" => $playerName,
                "DURATION" => $duration,
                "REASON" => $reason
            ]));
        } else {
            $sender->sendMessage($this->getMessage("mute_success_permanent", [
                "PLAYER" => $playerName,
                "REASON" => $reason
            ]));
        }

        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player !== null) {
            if ($expireTime > 0) {
                $player->sendMessage($this->getMessage("player_muted", [
                    "REASON" => $reason,
                    "TIME" => $this->formatTime($expireTime - time())
                ]));
            } else {
                $player->sendMessage($this->getMessage("player_muted_permanent", ["REASON" => $reason]));
            }
        }

        return true;
    }

    private function handleUnmuteCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.unmute")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) !== 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp unmute <player>");
            return true;
        }

        $playerName = $args[0];

        if (!$this->isPlayerMuted($playerName)) {
            $sender->sendMessage($this->getMessage("player_not_muted", ["PLAYER" => $playerName]));
            return true;
        }

        $this->muteData->remove($playerName);
        $this->muteData->save();

        $sender->sendMessage($this->getMessage("unmute_success", ["PLAYER" => $playerName]));

        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player !== null) {
            $player->sendMessage("§aYou have been unmuted by " . $sender->getName());
        }

        return true;
    }

    private function handleMuteListCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.mutelist")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        $mutes = $this->muteData->getAll();
        if (empty($mutes)) {
            $sender->sendMessage(TextFormat::YELLOW . "No players are currently muted.");
            return true;
        }

        $sender->sendMessage(TextFormat::YELLOW . "=== Muted Players ===");
        $currentTime = time();

        foreach ($mutes as $playerName => $muteInfo) {
            $reason = $muteInfo['reason'] ?? "No reason";
            $mutedBy = $muteInfo['muted_by'] ?? "Unknown";
            $expires = $muteInfo['expires'] ?? 0;

            if ($expires > 0) {
                $remaining = $expires - $currentTime;
                if ($remaining > 0) {
                    $timeLeft = $this->formatTime($remaining);
                    $sender->sendMessage(TextFormat::RED . $playerName . TextFormat::GRAY . " - " . TextFormat::WHITE . $reason . TextFormat::GRAY . " (by " . $mutedBy . ", " . $timeLeft . " left)");
                }
            } else {
                $sender->sendMessage(TextFormat::RED . $playerName . TextFormat::GRAY . " - " . TextFormat::WHITE . $reason . TextFormat::GRAY . " (by " . $mutedBy . ", permanent)");
            }
        }

        return true;
    }

    private function isPlayerMuted(string $playerName): bool {
        if (!$this->muteData->exists($playerName)) {
            return false;
        }

        $muteInfo = $this->muteData->get($playerName);
        $expires = $muteInfo['expires'] ?? 0;

        if ($expires > 0 && $expires <= time()) {
            $this->muteData->remove($playerName);
            $this->muteData->save();
            return false;
        }

        return true;
    }

    private function parseDuration(string $duration): int|false {
        if (!preg_match('/^(\d+)([smhdw])$/', $duration, $matches)) {
            return false;
        }

        $value = (int)$matches[1];
        $unit = $matches[2];

        return match($unit) {
            's' => $value,
            'm' => $value * 60,
            'h' => $value * 3600,
            'd' => $value * 86400,
            'w' => $value * 604800,
            default => false
        };
    }

    private function formatTime(int $seconds): string {
        if ($seconds < 60) {
            return $seconds . " seconds";
        } elseif ($seconds < 3600) {
            return round($seconds / 60) . " minutes";
        } elseif ($seconds < 86400) {
            return round($seconds / 3600) . " hours";
        } elseif ($seconds < 604800) {
            return round($seconds / 86400) . " days";
        } else {
            return round($seconds / 604800) . " weeks";
        }
    }

    private function handleSetGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.setgroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 2) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp setgroup <player> <group>");
            return true;
        }
        $player = $this->getServer()->getPlayerExact($args[0]);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }
        $group = $args[1];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        $this->setPlayerGroup($player, $group);
        $sender->sendMessage($this->getMessage("player_group_set", [
            "PLAYER" => $player->getName(),
            "GROUP" => $group
        ]));
        return true;
    }

    private function handleCreateGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.creategroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) < 3) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp creategroup <group> <chat_format> <nametag_format> [permissions...]");
            return true;
        }
        $group = $args[0];
        $chatFormat = $args[1];
        $nametagFormat = $args[2];
        $permissions = array_slice($args, 3);

        if (isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_already_exists"));
            return true;
        }

        $this->groups[$group] = [
            'chat_format' => $chatFormat,
            'nametag_format' => $nametagFormat,
            'permissions' => $permissions,
            'chat_cooldown' => 0
        ];

        $this->config->set("groups", $this->groups);
        $this->config->save();

        $this->registerPermissions();

        $sender->sendMessage($this->getMessage("group_created", ["GROUP" => $group]));
        return true;
    }

    private function handleRemoveGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.removegroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp removegroup <group>");
            return true;
        }
        $group = $args[0];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        
        $playersMovedToDefault = 0;
        foreach ($this->playerGroups->getAll() as $playerName => $playerGroup) {
            if ($playerGroup === $group) {
                $this->playerGroups->set($playerName, "default");
                $playersMovedToDefault++;
                
                $onlinePlayer = $this->getServer()->getPlayerExact($playerName);
                if ($onlinePlayer !== null) {
                    $this->updatePlayerPermissions($onlinePlayer);
                    $this->updatePlayerNameTag($onlinePlayer);
                }
            }
        }
        $this->playerGroups->save();
        
        unset($this->groups[$group]);
        $this->config->set("groups", $this->groups);
        $this->config->save();
        
        $sender->sendMessage($this->getMessage("group_removed", ["GROUP" => $group]));
        if ($playersMovedToDefault > 0) {
            $sender->sendMessage(TextFormat::YELLOW . "Moved $playersMovedToDefault player(s) to default group.");
        }
        return true;
    }

    private function handleAddGroupPermCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.addgroupperm")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 2) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp addgroupperm <group> <permission>");
            return true;
        }
        $group = $args[0];
        $permission = $args[1];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        if (in_array($permission, $this->groups[$group]['permissions'])) {
            $sender->sendMessage($this->getMessage("permission_already_exists"));
            return true;
        }
        $this->groups[$group]['permissions'][] = $permission;
        $this->config->set("groups", $this->groups);
        $this->config->save();
        $this->registerPermissions();
        
        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if ($this->getPlayerGroup($onlinePlayer) === $group) {
                $this->updatePlayerPermissions($onlinePlayer);
            }
        }
        
        $sender->sendMessage($this->getMessage("permission_added", [
            "PERMISSION" => $permission,
            "GROUP" => $group
        ]));
        return true;
    }

    private function handleRemoveGroupPermCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.removegroupperm")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        if (count($args) !== 2) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp removegroupperm <group> <permission>");
            return true;
        }
        $group = $args[0];
        $permission = $args[1];
        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }
        $key = array_search($permission, $this->groups[$group]['permissions']);
        if ($key === false) {
            $sender->sendMessage($this->getMessage("permission_not_found"));
            return true;
        }
        unset($this->groups[$group]['permissions'][$key]);
        $this->config->set("groups", $this->groups);
        $this->config->save();
        $this->registerPermissions();
        
        foreach ($this->getServer()->getOnlinePlayers() as $onlinePlayer) {
            if ($this->getPlayerGroup($onlinePlayer) === $group) {
                $this->updatePlayerPermissions($onlinePlayer);
            }
        }
        
        $sender->sendMessage($this->getMessage("permission_removed", [
            "PERMISSION" => $permission,
            "GROUP" => $group
        ]));
        return true;
    }

    private function handleListGroupsCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.listgroups")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        
        if (empty($this->groups)) {
            $sender->sendMessage(TextFormat::YELLOW . "No groups are currently defined.");
            return true;
        }
        
        $sender->sendMessage(TextFormat::YELLOW . "=== ChatPerms Groups ===");
        
        foreach ($this->groups as $groupName => $groupData) {
            $sender->sendMessage("");
            $sender->sendMessage(TextFormat::GREEN . "Group: " . TextFormat::AQUA . $groupName);
            $sender->sendMessage(TextFormat::GRAY . "Chat Format: " . TextFormat::WHITE . ($groupData['chat_format'] ?? 'Not set'));
            $sender->sendMessage(TextFormat::GRAY . "Nametag Format: " . TextFormat::WHITE . ($groupData['nametag_format'] ?? 'Not set'));
            $sender->sendMessage(TextFormat::GRAY . "Chat Cooldown: " . TextFormat::WHITE . ($groupData['chat_cooldown'] ?? 0) . " seconds");
            
            $permissions = $groupData['permissions'] ?? [];
            if (!empty($permissions)) {
                $sender->sendMessage(TextFormat::GRAY . "Permissions: " . TextFormat::WHITE . implode(", ", $permissions));
            } else {
                $sender->sendMessage(TextFormat::GRAY . "Permissions: " . TextFormat::WHITE . "None");
            }
            
            $playersInGroup = 0;
            foreach ($this->playerGroups->getAll() as $playerName => $playerGroup) {
                if ($playerGroup === $groupName) {
                    $playersInGroup++;
                }
            }
            $sender->sendMessage(TextFormat::GRAY . "Players in group: " . TextFormat::WHITE . $playersInGroup);
        }
        
        return true;
    }

    private function handleCheckPlayerCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.checkplayer")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }
        
        if (count($args) !== 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp checkplayer <player>");
            return true;
        }
        
        $player = $this->getServer()->getPlayerExact($args[0]);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }
        
        $group = $this->getPlayerGroup($player);
        $groupData = $this->groups[$group] ?? [];
        $directPermissions = $groupData['permissions'] ?? [];
        $allPermissions = $this->getAllGroupPermissions($group);
        
        $sender->sendMessage(TextFormat::YELLOW . "=== Player Info: " . TextFormat::AQUA . $player->getName() . TextFormat::YELLOW . " ===");
        $sender->sendMessage(TextFormat::GRAY . "Group: " . TextFormat::WHITE . $group);
        
        $inheritanceChain = $this->getInheritanceChain($group);
        if (count($inheritanceChain) > 1) {
            $sender->sendMessage(TextFormat::GRAY . "Inheritance: " . TextFormat::WHITE . implode(" → ", $inheritanceChain));
        }
        
        $sender->sendMessage(TextFormat::GRAY . "Direct Permissions: " . TextFormat::WHITE . (empty($directPermissions) ? "None" : implode(", ", $directPermissions)));
        $sender->sendMessage(TextFormat::GRAY . "All Permissions: " . TextFormat::WHITE . (empty($allPermissions) ? "None" : implode(", ", $allPermissions)));
        
        $hasAttachment = isset($this->playerAttachments[$player->getName()]);
        $sender->sendMessage(TextFormat::GRAY . "Has Permission Attachment: " . TextFormat::WHITE . ($hasAttachment ? "Yes" : "No"));
        
        $isMuted = $this->isPlayerMuted($player->getName());
        $sender->sendMessage(TextFormat::GRAY . "Is Muted: " . TextFormat::WHITE . ($isMuted ? "Yes" : "No"));
        
        $testPermissions = array_merge($allPermissions, ["chatperms.basic", "chatperms.vip", "chatperms.admin"]);
        $sender->sendMessage(TextFormat::GRAY . "Permission Tests:");
        foreach (array_unique($testPermissions) as $perm) {
            $hasPermission = $player->hasPermission($perm);
            $color = $hasPermission ? TextFormat::GREEN : TextFormat::RED;
            $status = $hasPermission ? "✓" : "✗";
            $sender->sendMessage(TextFormat::WHITE . "  " . $perm . ": " . $color . $status);
        }
        
        return true;
    }

    private function getInheritanceChain(string $group): array {
        $chain = [];
        $current = $group;
        $visited = [];

        while ($current && !in_array($current, $visited)) {
            $visited[] = $current;
            $chain[] = $current;
            $current = $this->groups[$current]['inherits'] ?? null;
        }

        return array_reverse($chain);
    }
    
    private function handleHelpCommand(CommandSender $sender): bool {
        $sender->sendMessage(TextFormat::YELLOW . "ChatPerms Plugin Commands:");
        $sender->sendMessage(TextFormat::GREEN . "/cp setgroup " . TextFormat::AQUA . "<player> <group>" . TextFormat::WHITE . " - Set a player's group");
        $sender->sendMessage(TextFormat::GREEN . "/cp creategroup " . TextFormat::AQUA . "<group> <chat_format> <nametag_format> [permissions...]" . TextFormat::WHITE . " - Create a new group");
        $sender->sendMessage(TextFormat::GREEN . "/cp removegroup " . TextFormat::AQUA . "<group>" . TextFormat::WHITE . " - Remove a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp addgroupperm " . TextFormat::AQUA . "<group> <permission>" . TextFormat::WHITE . " - Add a permission to a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp removegroupperm " . TextFormat::AQUA . "<group> <permission>" . TextFormat::WHITE . " - Remove a permission from a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp listgroups" . TextFormat::WHITE . " - List all groups");
        $sender->sendMessage(TextFormat::GREEN . "/cp checkplayer " . TextFormat::AQUA . "<player>" . TextFormat::WHITE . " - Check a player's permissions");
        $sender->sendMessage(TextFormat::GREEN . "/cp mute " . TextFormat::AQUA . "<player> [duration] [reason]" . TextFormat::WHITE . " - Mute a player");
        $sender->sendMessage(TextFormat::GREEN . "/cp unmute " . TextFormat::AQUA . "<player>" . TextFormat::WHITE . " - Unmute a player");
        $sender->sendMessage(TextFormat::GREEN . "/cp mutelist" . TextFormat::WHITE . " - List all muted players");
        $sender->sendMessage(TextFormat::GREEN . "/cp stats " . TextFormat::AQUA . "[player]" . TextFormat::WHITE . " - Show chat statistics");
        $sender->sendMessage(TextFormat::GREEN . "/cp tempgroup " . TextFormat::AQUA . "<player> <group> <duration> [reason]" . TextFormat::WHITE . " - Create a temporary group");
        $sender->sendMessage(TextFormat::GREEN . "/cp tempgroupcheck" . TextFormat::WHITE . " - Check temporary group status");
        $sender->sendMessage(TextFormat::GREEN . "/cp help" . TextFormat::WHITE . " - Show this help message");
        return true;
    }

    public function onChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if ($this->isPlayerMuted($playerName)) {
            $event->cancel();
            $muteInfo = $this->muteData->get($playerName);
            $reason = $muteInfo['reason'] ?? "No reason";
            $expires = $muteInfo['expires'] ?? 0;
            
            if ($expires > 0) {
                $timeLeft = $this->formatTime($expires - time());
                $player->sendMessage($this->getMessage("player_muted", [
                    "REASON" => $reason,
                    "TIME" => $timeLeft
                ]));
            } else {
                $player->sendMessage($this->getMessage("player_muted_permanent", ["REASON" => $reason]));
            }
            return;
        }
        
        $group = $this->getPlayerGroup($player);
        $cooldown = $this->groups[$group]['chat_cooldown'] ?? 0;
        
        if ($cooldown > 0) {
            $lastTime = $this->lastChatTime[$playerName] ?? 0;
            $currentTime = time();
            $timeSince = $currentTime - $lastTime;
            
            if ($timeSince < $cooldown) {
                $event->cancel();
                $remaining = $cooldown - $timeSince;
                $player->sendMessage($this->getMessage("chat_cooldown", ["SECONDS" => $remaining]));
                return;
            }
            
            $this->lastChatTime[$playerName] = $currentTime;
        }
        
        $message = $event->getMessage();
        $format = $this->getGroupChatFormat($group);
        
        $formattedMessage = $this->replacePlaceholders($format, $player, $message, false);
        
        $event->cancel();
        
        foreach ($event->getRecipients() as $recipient) {
            $recipient->sendMessage($formattedMessage);
        }

        $this->updateChatStats($player);
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        
        $this->checkPlayerTempGroup($player);
        
        $this->updatePlayerPermissions($player);
        $this->updatePlayerNameTag($player);
    }

    public function onPlayerQuit(PlayerQuitEvent $event): void {
        $player = $event->getPlayer();
        $playerName = $player->getName();
        
        if (isset($this->playerAttachments[$playerName])) {
            $player->removeAttachment($this->playerAttachments[$playerName]);
            unset($this->playerAttachments[$playerName]);
        }
        
        unset($this->lastChatTime[$playerName]);
    }

    private function getPlayerGroup(Player $player): string {
        $name = $player->getName();
        return $this->playerGroups->get($name, "default");
    }

    private function getGroupChatFormat(string $group): string {
        return $this->groups[$group]['chat_format'] ?? "{PLAYER}: {MESSAGE}";
    }

    private function getGroupNameTagFormat(string $group): string {
        return $this->groups[$group]['nametag_format'] ?? "{PLAYER}";
    }

    private function replacePlaceholders(string $format, Player $player, string $message, bool $isNameTag): string {
        $placeholders = [
            "{PLAYER}" => $player->getName(),
            "{MESSAGE}" => $message,
            "{WORLD}" => $player->getWorld()->getFolderName(),
        ];
        
        if ($isNameTag) {
            $placeholders["{HEALTH}"] = $this->getColoredHealth($player);
        }
        
        if ($this->factionPlugin !== null) {
            $placeholders["{FACTION}"] = $this->getPlayerFaction($player);
        }
        
        return str_replace(array_keys($placeholders), array_values($placeholders), $format);
    }

    private function getPlayerFaction(Player $player): string {
        if ($this->factionPlugin === null) {
            return "";
        }

        $faction = "";
        $pluginName = $this->factionPlugin->getName();

        switch ($pluginName) {
            case "FactionsPro":
                $faction = $this->factionPlugin->getPlayerFaction($player->getName());
                break;
            case "PiggyFactions":
                $member = $this->factionPlugin->getPlayerManager()->getPlayer($player);
                if ($member !== null) {
                    $faction = $member->getFaction() !== null ? $member->getFaction()->getName() : "";
                }
                break;
            case "SimpleFaction":
                $faction = $this->factionPlugin->getPlayerFaction($player->getName());
                break;
            case "Factions":
                $factionPlayer = $this->factionPlugin->getPlayerManager()->getPlayer($player);
                if ($factionPlayer !== null) {
                    $faction = $factionPlayer->getFaction() !== null ? $factionPlayer->getFaction()->getName() : "";
                }
                break;
        }

        return $faction !== "" ? "[$faction] " : "";
    }

    private function getColoredHealth(Player $player): string {
        $health = $player->getHealth();
        $maxHealth = $player->getMaxHealth();
        
        if ($health > $maxHealth * 0.75) {
            $color = TextFormat::GREEN;
        } elseif ($health > $maxHealth * 0.5) {
            $color = TextFormat::YELLOW;
        } elseif ($health > $maxHealth * 0.25) {
            $color = TextFormat::GOLD;
        } else {
            $color = TextFormat::RED;
        }
        
        return $color . (int)$health . TextFormat::RESET;
    }

    public function setPlayerGroup(Player $player, string $group): void {
        if (!isset($this->groups[$group])) {
            return;
        }
        $this->playerGroups->set($player->getName(), $group);
        $this->playerGroups->save();
        $this->updatePlayerPermissions($player);
        $this->updatePlayerNameTag($player);
    }

    private function updatePlayerPermissions(Player $player): void {
        $group = $this->getPlayerGroup($player);
        $permissions = $this->getAllGroupPermissions($group);
        
        if (isset($this->playerAttachments[$player->getName()])) {
            $player->removeAttachment($this->playerAttachments[$player->getName()]);
            unset($this->playerAttachments[$player->getName()]);
        }

        $attachment = $player->addAttachment($this);
        $attachment->setPermission("chatperms.group." . $group, true);
        foreach ($permissions as $permission) {
            $attachment->setPermission($permission, true);
        }
        
        $this->playerAttachments[$player->getName()] = $attachment;
    }

    private function getAllGroupPermissions(string $group): array {
        if (!isset($this->groups[$group])) {
            return [];
        }

        $allPermissions = [];
        $visited = [];
        $this->collectInheritedPermissions($group, $allPermissions, $visited);
        
        return array_unique($allPermissions);
    }

    private function collectInheritedPermissions(string $group, array &$permissions, array &$visited): void {
        if (in_array($group, $visited) || !isset($this->groups[$group])) {
            return;
        }

        $visited[] = $group;
        $groupData = $this->groups[$group];

        if (isset($groupData['inherits'])) {
            $parentGroup = $groupData['inherits'];
            $this->collectInheritedPermissions($parentGroup, $permissions, $visited);
        }

        if (isset($groupData['permissions'])) {
            foreach ($groupData['permissions'] as $permission) {
                $permissions[] = $permission;
            }
        }
    }

    public function onEntityDamage(EntityDamageEvent $event): void {
        $entity = $event->getEntity();
        if ($entity instanceof Player) {
            $this->updatePlayerNameTag($entity);
        }
    }

    private function updatePlayerNameTag(Player $player): void {
        $group = $this->getPlayerGroup($player);
        $format = $this->getGroupNameTagFormat($group);
        $nameTag = $this->replacePlaceholders($format, $player, "", true);
        $player->setNameTag($nameTag);
    }
    
    private function getMessage(string $key, array $placeholders = []): string {
        $message = $this->messages[$key] ?? "Message '$key' not found";
        
        foreach ($placeholders as $placeholder => $value) {
            $message = str_replace("{{$placeholder}}", $value, $message);
        }
        
        return $message;
    }

    private function handleStatsCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.stats")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) === 0) {
            $this->showServerStats($sender);
        } else {
            $playerName = $args[0];
            $this->showPlayerStats($sender, $playerName);
        }

        return true;
    }

    private function showServerStats(CommandSender $sender): void {
        $allStats = $this->chatStats->getAll();
        if (empty($allStats)) {
            $sender->sendMessage(TextFormat::YELLOW . "No chat statistics available yet.");
            return;
        }

        $totalMessages = 0;
        $totalPlayers = count($allStats);
        $mostActivePlayer = "";
        $mostMessages = 0;

        foreach ($allStats as $playerName => $stats) {
            $messageCount = $stats['total_messages'] ?? 0;
            $totalMessages += $messageCount;
            
            if ($messageCount > $mostMessages) {
                $mostMessages = $messageCount;
                $mostActivePlayer = $playerName;
            }
        }

        $sender->sendMessage(TextFormat::YELLOW . "=== Server Chat Statistics ===");
        $sender->sendMessage(TextFormat::GREEN . "Total Messages: " . TextFormat::WHITE . number_format($totalMessages));
        $sender->sendMessage(TextFormat::GREEN . "Active Players: " . TextFormat::WHITE . $totalPlayers);
        $sender->sendMessage(TextFormat::GREEN . "Most Active Player: " . TextFormat::WHITE . $mostActivePlayer . TextFormat::GRAY . " (" . number_format($mostMessages) . " messages)");
        
        if ($totalMessages > 0) {
            $avgMessages = round($totalMessages / $totalPlayers, 1);
            $sender->sendMessage(TextFormat::GREEN . "Average per Player: " . TextFormat::WHITE . $avgMessages . " messages");
        }
    }

    private function showPlayerStats(CommandSender $sender, string $playerName): void {
        if (!$this->chatStats->exists($playerName)) {
            $sender->sendMessage(TextFormat::YELLOW . "No statistics found for player " . TextFormat::WHITE . $playerName);
            return;
        }

        $stats = $this->chatStats->get($playerName);
        $totalMessages = $stats['total_messages'] ?? 0;
        $firstMessage = $stats['first_message'] ?? 0;
        $lastMessage = $stats['last_message'] ?? 0;
        $hourlyStats = $stats['hourly_stats'] ?? [];

        $sender->sendMessage(TextFormat::YELLOW . "=== Chat Stats: " . TextFormat::AQUA . $playerName . TextFormat::YELLOW . " ===");
        $sender->sendMessage(TextFormat::GREEN . "Total Messages: " . TextFormat::WHITE . number_format($totalMessages));
        
        if ($firstMessage > 0) {
            $sender->sendMessage(TextFormat::GREEN . "First Message: " . TextFormat::WHITE . date("Y-m-d H:i:s", $firstMessage));
        }
        
        if ($lastMessage > 0) {
            $sender->sendMessage(TextFormat::GREEN . "Last Message: " . TextFormat::WHITE . date("Y-m-d H:i:s", $lastMessage));
        }

        if (!empty($hourlyStats)) {
            $maxHour = array_keys($hourlyStats, max($hourlyStats))[0];
            $maxMessages = $hourlyStats[$maxHour];
            $sender->sendMessage(TextFormat::GREEN . "Most Active Hour: " . TextFormat::WHITE . $maxHour . ":00 - " . ($maxHour + 1) . ":00 " . TextFormat::GRAY . "(" . $maxMessages . " messages)");
        }

        if ($totalMessages > 0) {
            $daysSinceFirst = max(1, (time() - $firstMessage) / 86400);
            $messagesPerDay = round($totalMessages / $daysSinceFirst, 1);
            $sender->sendMessage(TextFormat::GREEN . "Average per Day: " . TextFormat::WHITE . $messagesPerDay . " messages");
        }
    }

    private function updateChatStats(Player $player): void {
        $playerName = $player->getName();
        $currentTime = time();
        $currentHour = (int)date("H", $currentTime);

        $stats = $this->chatStats->get($playerName, [
            'total_messages' => 0,
            'first_message' => $currentTime,
            'last_message' => 0,
            'hourly_stats' => []
        ]);

        $stats['total_messages']++;
        $stats['last_message'] = $currentTime;
        
        if (!isset($stats['hourly_stats'][$currentHour])) {
            $stats['hourly_stats'][$currentHour] = 0;
        }
        $stats['hourly_stats'][$currentHour]++;

        $this->chatStats->set($playerName, $stats);
        
        if ($stats['total_messages'] % 10 === 0) {
            $this->chatStats->save();
        }
    }

    private function handleTempGroupCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.tempgroup")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) < 3) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp tempgroup <player> <group> <duration> [reason]");
            return true;
        }

        $playerName = $args[0];
        $group = $args[1];
        $duration = $args[2];
        $reason = isset($args[3]) ? implode(" ", array_slice($args, 3)) : "No reason specified";

        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }

        if (!isset($this->groups[$group])) {
            $sender->sendMessage($this->getMessage("group_not_found"));
            return true;
        }

        $expireTime = $this->parseDuration($duration);
        if ($expireTime === false) {
            $sender->sendMessage($this->getMessage("invalid_duration"));
            return true;
        }
        $expireTime += time();

        $originalGroup = $this->getPlayerGroup($player);

        $this->tempGroups->set($playerName, [
            'temp_group' => $group,
            'original_group' => $originalGroup,
            'expires' => $expireTime,
            'reason' => $reason,
            'assigned_by' => $sender->getName(),
            'assigned_at' => time()
        ]);
        $this->tempGroups->save();

        $this->setPlayerGroup($player, $group);

        $sender->sendMessage("§aAssigned temporary group §6$group §ato §b$playerName §afor $duration. Reason: $reason");
        $player->sendMessage("§aYou have been assigned to temporary group §6$group §afor $duration. Reason: $reason");

        return true;
    }

    private function startTempGroupCheckTask(): void {
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(
            function(): void {
                $this->checkExpiredTempGroups();
            }
        ), 200);
    }

    private function checkExpiredTempGroups(): void {
        $currentTime = time();
        $tempGroups = $this->tempGroups->getAll();
        
        if (empty($tempGroups)) {
            return;
        }
        
        $updated = false;

        foreach ($tempGroups as $playerName => $tempData) {
            if (!is_array($tempData) || !isset($tempData['expires'])) {
                $this->tempGroups->remove($playerName);
                $updated = true;
                continue;
            }
            
            if ($tempData['expires'] <= $currentTime) {
                $originalGroup = $tempData['original_group'] ?? 'default';
                $tempGroup = $tempData['temp_group'] ?? 'unknown';
                
                $this->tempGroups->remove($playerName);
                $updated = true;

                $player = $this->getServer()->getPlayerExact($playerName);
                if ($player !== null) {
                    $this->setPlayerGroup($player, $originalGroup);
                    $player->sendMessage($this->getMessage("tempgroup_expired", [
                        "GROUP" => $tempGroup,
                        "PLAYER" => $playerName
                    ]));
                } else {
                    $this->playerGroups->set($playerName, $originalGroup);
                    $this->playerGroups->save();
                }
            }
        }

        if ($updated) {
            $this->tempGroups->save();
        }
    }

    private function checkPlayerTempGroup(Player $player): void {
        $playerName = $player->getName();
        $tempData = $this->tempGroups->get($playerName, null);
        if ($tempData !== null && is_array($tempData) && isset($tempData['expires']) && $tempData['expires'] <= time()) {
            $originalGroup = $tempData['original_group'] ?? 'default';
            $tempGroup = $tempData['temp_group'] ?? 'unknown';
            $this->tempGroups->remove($playerName);
            $this->tempGroups->save();
            
            $this->setPlayerGroup($player, $originalGroup);
            
            $player->sendMessage($this->getMessage("tempgroup_expired", [
                "GROUP" => $tempGroup,
                "PLAYER" => $playerName
            ]));
            
            $this->getLogger()->info("Restored player $playerName from temp group $tempGroup to $originalGroup");
        }
    }

    private function handleTempGroupCheckCommand(CommandSender $sender, array $args): bool {
        if (!$sender->hasPermission("chatperms.command.tempgroupcheck")) {
            $sender->sendMessage($this->getMessage("no_permission"));
            return true;
        }

        if (count($args) !== 1) {
            $sender->sendMessage(TextFormat::YELLOW . "Usage: " . TextFormat::WHITE . "/cp tempgroupcheck <player>");
            return true;
        }

        $playerName = $args[0];
        $player = $this->getServer()->getPlayerExact($playerName);
        if ($player === null) {
            $sender->sendMessage($this->getMessage("player_not_found"));
            return true;
        }

        $tempData = $this->tempGroups->get($playerName, null);
        if ($tempData === null || !is_array($tempData)) {
            $sender->sendMessage(TextFormat::YELLOW . "Player " . TextFormat::WHITE . $playerName . TextFormat::YELLOW . " is not in a temporary group.");
            return true;
        }

        $group = $tempData['temp_group'] ?? "unknown";
        $reason = $tempData['reason'] ?? "No reason specified";
        $remainingTime = $tempData['expires'] - time();
        $duration = $remainingTime > 0 ? $this->formatTime($remainingTime) : "EXPIRED";

        $sender->sendMessage(TextFormat::YELLOW . "=== Temporary Group Info: " . TextFormat::AQUA . $playerName . TextFormat::YELLOW . " ===");
        $sender->sendMessage(TextFormat::GREEN . "Group: " . TextFormat::WHITE . $group);
        $sender->sendMessage(TextFormat::GREEN . "Reason: " . TextFormat::WHITE . $reason);
        $sender->sendMessage(TextFormat::GREEN . "Time Remaining: " . TextFormat::WHITE . $duration);
        
        if ($remainingTime <= 0) {
            $sender->sendMessage(TextFormat::RED . "⚠ This temp group has expired and should be removed on next check!");
        }

        return true;
    }
}