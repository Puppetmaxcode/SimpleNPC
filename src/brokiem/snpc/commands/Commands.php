<?php

declare(strict_types=1);

namespace brokiem\snpc\commands;

use brokiem\snpc\entity\BaseNPC;
use brokiem\snpc\entity\CustomHuman;
use brokiem\snpc\entity\WalkingHuman;
use brokiem\snpc\manager\NPCManager;
use brokiem\snpc\SimpleNPC;
use brokiem\snpc\task\async\SpawnHumanNPCTask;
use EasyUI\element\Button;
use EasyUI\element\Input;
use EasyUI\utils\FormResponse;
use EasyUI\variant\CustomForm;
use EasyUI\variant\SimpleForm;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Entity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\utils\TextFormat;

class Commands extends Command implements PluginOwned
{
    /** @var SimpleNPC */
    private SimpleNPC $owner;

    public function __construct(string $name, SimpleNPC $owner)
    {
        parent::__construct($name);
        $this->setDescription("SimpleNPC commands");
        $this->owner = $owner;
    }

    public function getOwningPlugin(): Plugin
    {
        return $this->owner;
    }

    public function execute(CommandSender $sender, string $commandLabel, array $args): bool
    {
        if (!$this->testPermission($sender)) {
            return true;
        }

        /** @var SimpleNPC $plugin */
        $plugin = $this->getOwningPlugin();

        if (isset($args[0])) {
            switch ($args[0]) {
                case "spawn":
                case "add":
                    if (!$sender instanceof Player) {
                        $sender->sendMessage("Only player can run this command!");
                        return true;
                    }

                    if (!$sender->hasPermission("snpc.spawn")) {
                        return true;
                    }

                    if (isset($args[1])) {
                        if (in_array(strtolower($args[1]), SimpleNPC::$npcType, true)) {
                            if ($args[1] === SimpleNPC::ENTITY_HUMAN) {
                                if (isset($args[4])) {
                                    if (!preg_match('/https?:\/\/[^?]*\.png(?![\w.\-_])/', $args[4])) {
                                        $sender->sendMessage(TextFormat::RED . "Invalid skin file format! (Only PNG Supported)");
                                        return true;
                                    }

                                    $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($args[2], $sender->getName(), $plugin->getDataFolder(), $args[3] === "true", $args[4]));
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                if (isset($args[3])) {
                                    $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($args[2], $sender->getName(), $plugin->getDataFolder(), $args[3] === "true"));
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                if (isset($args[2])) {
                                    $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask($args[2], $sender->getName(), $plugin->getDataFolder()));
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                $plugin->getServer()->getAsyncPool()->submitTask(new SpawnHumanNPCTask(null, $sender->getName(), $plugin->getDataFolder()));
                                $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC without nametag for you...");
                            } else {
                                if (isset($args[2])) {
                                    NPCManager::createNPC($args[1], $sender, $args[2]);
                                    $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC with nametag $args[2] for you...");
                                    return true;
                                }

                                NPCManager::createNPC($args[1], $sender);
                                $sender->sendMessage(TextFormat::DARK_GREEN . "Creating " . ucfirst($args[1]) . " NPC without nametag for you...");
                            }
                        } else {
                            $sender->sendMessage(TextFormat::RED . "Invalid entity type or entity not registered!");
                        }
                    } else {
                        $sender->sendMessage(TextFormat::RED . "Usage: /snpc spawn <type> optional: <nametag> <canWalk> <skinUrl>");
                    }
                    break;
                case "delete":
                case "remove":
                    if (!$sender->hasPermission("snpc.remove")) {
                        return true;
                    }

                    if (isset($args[1]) and is_numeric($args[1])) {
                        $entity = $plugin->getServer()->getWorldManager()->findEntity((int)$args[1]);

                        if ($entity instanceof BaseNPC || $entity instanceof CustomHuman) {
                            if (!$entity->isFlaggedForDespawn()) {
                                $entity->flagForDespawn();
                                $sender->sendMessage(TextFormat::GREEN . "The NPC was successfully removed!");
                            }
                        } else {
                            $sender->sendMessage(TextFormat::YELLOW . "SimpleNPC Entity with ID: " . $args[1] . " not found!");
                        }

                        return true;
                    }

                    if (!isset($plugin->removeNPC[$sender->getName()])) {
                        $plugin->removeNPC[$sender->getName()] = true;
                        $sender->sendMessage(TextFormat::DARK_GREEN . "Hit the npc that you want to delete or remove");
                    } else {
                        unset($plugin->removeNPC[$sender->getName()]);
                        $sender->sendMessage(TextFormat::GREEN . "Remove npc by hitting has been canceled");
                    }
                    break;
                case "edit":
                case "manage":
                    if (!$sender->hasPermission("snpc.edit") or !$sender instanceof Player) {
                        return true;
                    }

                    if (!isset($args[1]) or !is_numeric($args[1])) {
                        $sender->sendMessage(TextFormat::RED . "Usage: /snpc edit <id>");
                        return true;
                    }

                    $entity = $plugin->getServer()->getWorldManager()->findEntity((int)$args[1]);

                    $customForm = new CustomForm("Manage NPC");
                    $simpleForm = new SimpleForm("Manage NPC");

                    if ($entity instanceof BaseNPC || $entity instanceof CustomHuman) {
                        $editUI = new SimpleForm("Manage NPC", "§aID:§2 $args[1]\n§aClass: §2" . get_class($entity) . "\n§aNametag: §2" . $entity->getNameTag() . "\n§aPosition: §2" . $entity->getPosition()->getFloorX() . "/" . $entity->getPosition()->getFloorY() . "/" . $entity->getPosition()->getFloorZ());

                        $editUI->addButton(new Button("Add Command", null, function (Player $sender) use ($customForm) {
                            $customForm->addElement("addcmd", new Input("Enter the command here"));
                            $sender->sendForm($customForm);
                        }));
                        $editUI->addButton(new Button("Teleport", null, function (Player $sender) use ($simpleForm, $entity) {
                            $simpleForm->addButton(new Button("You to Entity", null, function (Player $sender) use ($entity): void {
                                $sender->teleport($entity->getLocation());
                                $sender->sendMessage(TextFormat::GREEN . "Teleported!");
                            }));
                            $simpleForm->addButton(new Button("Entity to You", null, function (Player $sender) use ($entity): void {
                                $entity->teleport($sender->getLocation());
                                if ($entity instanceof WalkingHuman) {
                                    $entity->randomPosition = $entity->getLocation();
                                }
                                $sender->sendMessage(TextFormat::GREEN . "Teleported!");
                            }));

                            $sender->sendForm($simpleForm);
                        }));

                        $customForm->setSubmitListener(function (Player $player, FormResponse $response) use ($entity, $customForm) {
                            $submittedText = $response->getInputSubmittedText("addcmd");

                            if ($submittedText === "") {
                                $customForm->addElement("addcmd", new Input(TextFormat::RED . "Please enter a valid command"));
                                $player->sendForm($customForm);
                            } else {
                                $commands = $entity->namedtag->getCompoundTag("Commands") ?? new CompoundTag("Commands");

                                if ($commands->hasTag($submittedText)) {
                                    $player->sendMessage(TextFormat::RED . "'" . $submittedText . "' command has already been added.");
                                    return;
                                }

                                $commands->setString($submittedText, $submittedText);
                                $entity->namedtag->setTag($commands);
                                $player->sendMessage(TextFormat::GREEN . "Successfully added command '" . $submittedText . "' to entity ID: " . $entity->getId());
                            }
                        });

                        $sender->sendForm($editUI);
                    } else {
                        $sender->sendMessage(TextFormat::YELLOW . "SimpleNPC Entity with ID: " . $args[1] . " not found!");
                    }
                    break;
                case "list":
                    if (!$sender->hasPermission("snpc.list")) {
                        return true;
                    }

                    foreach ($plugin->getServer()->getWorldManager()->getWorlds() as $world) {
                        $entityNames = array_map(static function (Entity $entity): string {
                            return TextFormat::YELLOW . "ID: (" . $entity->getId() . ") " . TextFormat::DARK_GREEN . $entity->getNameTag() . " §7-- §3" . $entity->getWorld()->getFolderName() . ": " . $entity->getPosition()->getFloorX() . "/" . $entity->getPosition()->getFloorY() . "/" . $entity->getPosition()->getFloorZ();
                        }, array_filter($world->getEntities(), static function (Entity $entity): bool {
                            return $entity instanceof BaseNPC or $entity instanceof CustomHuman;
                        }));

                        $sender->sendMessage("§csNPC List and Location: (" . count($entityNames) . ")\n §3- " . implode("\n - ", $entityNames));
                    }
                    break;
                default:
                    $sender->sendMessage("§7---- ---- [ §3SimpleNPC§7 ] ---- ----\n§bAuthor: @brokiem\n§3Source Code: github.com/brokiem/SimpleNPC\nVersion " . $this->getOwningPlugin()->getDescription()->getVersion() . "\n\n§eCommand List:\n§2» /snpc spawn <type> <nametag> <canWalk> <skinUrl>\n§2» /snpc edit <id>\n§2» /snpc remove <id>\n§2» /snpc migrate <confirm | cancel>\n§2» /snpc list\n§7---- ---- ---- - ---- ---- ----");
                    break;
            }
        } else {
            $sender->sendMessage("§7---- ---- [ §3SimpleNPC§7 ] ---- ----\n§bAuthor: @brokiem\n§3Source Code: github.com/brokiem/SimpleNPC\nVersion " . $this->getOwningPlugin()->getDescription()->getVersion() . "\n\n§eCommand List:\n§2» /snpc spawn <type> <nametag> <canWalk> <skinUrl>\n§2» /snpc edit <id>\n§2» /snpc remove <id>\n§2» /snpc migrate <confirm | cancel>\n§2» /snpc list\n§7---- ---- ---- - ---- ---- ----");
        }

        return parent::execute($sender, $commandLabel, $args);
    }
}