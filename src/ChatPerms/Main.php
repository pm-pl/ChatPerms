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

    public function onEnable(): void {
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->saveDefaultConfig();
        $this->config = $this->getConfig();
        $this->groups = $this->config->get("groups", []);
        $this->playerGroups = new Config($this->getDataFolder() . "players.yml", Config::YAML);
        
        // Initialize default messages if they don't exist
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
                "no_faction_detected" => "§cNo compatible faction plugin detected."
            ]);
            $this->config->save();
        }
        
        $this->messages = $this->config->get("messages");

        $this->registerPermissions();
        $this->startNameTagUpdateTask();
        
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
            $permManager->addPermission(new Permission("chatperms.group." . $group));
            foreach ($data['permissions'] as $perm) {
                $permManager->addPermission(new Permission($perm));
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
            case "help":
                return $this->handleHelpCommand($sender);
            default:
                $sender->sendMessage($this->getMessage("unknown_command", ["COMMAND" => $subCommand]));
                return true;
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
            'permissions' => $permissions
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
        unset($this->groups[$group]);
        $this->config->set("groups", $this->groups);
        $this->config->save();
        $sender->sendMessage($this->getMessage("group_removed", ["GROUP" => $group]));
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
        $sender->sendMessage($this->getMessage("permission_removed", [
            "PERMISSION" => $permission,
            "GROUP" => $group
        ]));
        return true;
    }

    private function handleHelpCommand(CommandSender $sender): bool {
        $sender->sendMessage(TextFormat::YELLOW . "ChatPerms Plugin Commands:");
        $sender->sendMessage(TextFormat::GREEN . "/cp setgroup " . TextFormat::AQUA . "<player> <group>" . TextFormat::WHITE . " - Set a player's group");
        $sender->sendMessage(TextFormat::GREEN . "/cp creategroup " . TextFormat::AQUA . "<group> <chat_format> <nametag_format> [permissions...]" . TextFormat::WHITE . " - Create a new group");
        $sender->sendMessage(TextFormat::GREEN . "/cp removegroup " . TextFormat::AQUA . "<group>" . TextFormat::WHITE . " - Remove a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp addgroupperm " . TextFormat::AQUA . "<group> <permission>" . TextFormat::WHITE . " - Add a permission to a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp removegroupperm " . TextFormat::AQUA . "<group> <permission>" . TextFormat::WHITE . " - Remove a permission from a group");
        $sender->sendMessage(TextFormat::GREEN . "/cp help" . TextFormat::WHITE . " - Show this help message");
        return true;
    }

    public function onChat(PlayerChatEvent $event): void {
        $player = $event->getPlayer();
        $message = $event->getMessage();
        
        $group = $this->getPlayerGroup($player);
        $format = $this->getGroupChatFormat($group);
        
        $formattedMessage = $this->replacePlaceholders($format, $player, $message, false);
        $event->setMessage($formattedMessage);
        
        $event->cancel();
        
        foreach ($event->getRecipients() as $recipient) {
            $recipient->sendMessage($formattedMessage);
        }
    }

    public function onPlayerJoin(PlayerJoinEvent $event): void {
        $player = $event->getPlayer();
        $this->updatePlayerNameTag($player);
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
        $permissions = $this->groups[$group]['permissions'] ?? [];
        
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
    
    /**
     * Gets a message from the config with placeholders replaced
     * 
     * @param string $key The message key in the config
     * @param array $placeholders Array of placeholders to replace [placeholder => value]
     * @return string The formatted message
     */
    private function getMessage(string $key, array $placeholders = []): string {
        $message = $this->messages[$key] ?? "Message '$key' not found";
        
        foreach ($placeholders as $placeholder => $value) {
            $message = str_replace("{{$placeholder}}", $value, $message);
        }
        
        return $message;
    }
}