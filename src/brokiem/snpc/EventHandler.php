<?php

declare(strict_types=1);

namespace brokiem\snpc;

use brokiem\snpc\entity\BaseNPC;
use brokiem\snpc\entity\CustomHuman;
use brokiem\snpc\manager\NPCManager;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\math\Vector2;
use pocketmine\network\mcpe\protocol\MoveActorAbsolutePacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\Player;
use pocketmine\utils\TextFormat;

class EventHandler implements Listener
{

    /** @var SimpleNPC */
    private $plugin;

    public function __construct(SimpleNPC $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onJoin(PlayerJoinEvent $event): void
    {
        $player = $event->getPlayer();

        if ($player->hasPermission("snpc.notify") && !empty($this->plugin->cachedUpdate)) {
            [$latestVersion, $updateDate, $updateUrl] = $this->plugin->cachedUpdate;

            if ($this->plugin->getDescription()->getVersion() !== $latestVersion) {
                $player->sendMessage(" \n§aSimpleNPC §bv$latestVersion §ahas been released on §b$updateDate. §aDownload the new update at §b$updateUrl\n ");
            }
        }
    }

    public function onDamage(EntityDamageEvent $event): void
    {
        $entity = $event->getEntity();

        if ($entity instanceof CustomHuman || $entity instanceof BaseNPC) {
            $event->setCancelled();
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            if ($entity instanceof CustomHuman || $entity instanceof BaseNPC) {
                $damager = $event->getDamager();

                if ($damager instanceof Player) {
                    if (isset($this->plugin->idPlayers[$damager->getName()])) {
                        $damager->sendMessage(TextFormat::GREEN . "NPC ID: " . $entity->getId());
                        unset($this->plugin->idPlayers[$damager->getName()]);
                        return;
                    }

                    if (isset($this->plugin->removeNPC[$damager->getName()]) && !$entity->isFlaggedForDespawn()) {
                        if (NPCManager::removeNPC($entity->namedtag->getString("Identifier"), $entity)) {
                            $damager->sendMessage(TextFormat::GREEN . "The NPC was successfully removed!");
                        } else {
                            $damager->sendMessage(TextFormat::YELLOW . "The NPC was failed removed! (File not found)");
                        }
                        unset($this->plugin->removeNPC[$damager->getName()]);
                        return;
                    }

                    if (!isset($this->plugin->lastHit[$damager->getName()][$entity->getId()])) {
                        $this->plugin->lastHit[$damager->getName()][$entity->getId()] = microtime(true);
                        return;
                    }

                    $coldown = $this->plugin->settings["commandExecuteColdown"] ?? 1.0;
                    if (($coldown + (float)$this->plugin->lastHit[$damager->getName()][$entity->getId()]) > microtime(true)) {
                        return;
                    }

                    $this->plugin->lastHit[$damager->getName()][$entity->getId()] = microtime(true);

                    if (($commands = $entity->namedtag->getCompoundTag("Commands")) !== null) {
                        foreach ($commands as $stringTag) {
                            $this->plugin->getServer()->getCommandMap()->dispatch(new ConsoleCommandSender(), str_replace("{player}", '"' . $damager->getName() . '"', $stringTag->getValue()));
                        }
                    }
                }

                $event->setCancelled();
            }
        }
    }

    public function onMotion(EntityMotionEvent $event): void
    {
        $entity = $event->getEntity();

        if ($entity instanceof CustomHuman || $entity instanceof BaseNPC) {
            if ($entity->namedtag->hasTag("Walk") && $entity->namedtag->getShort("Walk") === 0) {
                $event->setCancelled();
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $player = $event->getPlayer();

        if (isset($this->plugin->lastHit[$player->getName()])) {
            unset($this->plugin->lastHit[$player->getName()]);
        }

        if (isset($this->plugin->removeNPC[$player->getName()])) {
            unset($this->plugin->removeNPC[$player->getName()]);
        }

        if (isset($this->plugin->idPlayers[$player->getName()])) {
            unset($this->plugin->idPlayers[$player->getName()]);
        }
    }

    public function onMove(PlayerMoveEvent $event): void
    {
        $player = $event->getPlayer();

        if ($this->plugin->settings["lookToPlayersEnabled"]) {
            if ($event->getFrom()->distance($event->getTo()) === 0.0) {
                return;
            }

            foreach ($player->getLevel()->getNearbyEntities($player->getBoundingBox()->expandedCopy($this->plugin->settings["maxLookDistance"], $this->plugin->settings["maxLookDistance"], $this->plugin->settings["maxLookDistance"]), $player) as $entity) {
                if (($entity instanceof CustomHuman) or $entity instanceof BaseNPC) {
                    $angle = atan2($player->z - $entity->z, $player->x - $entity->x);
                    $yaw = (($angle * 180) / M_PI) - 90;
                    $angle = atan2((new Vector2($entity->x, $entity->z))->distance($player->x, $player->z), $player->y - $entity->y);
                    $pitch = (($angle * 180) / M_PI) - 90;

                    if ($entity->namedtag->hasTag("Walk")) {
                        if ($entity instanceof CustomHuman and $entity->namedtag->getShort("Walk") === 0) {
                            $pk = new MovePlayerPacket();
                            $pk->entityRuntimeId = $entity->getId();
                            $pk->position = $entity->add(0, $entity->getEyeHeight());
                            $pk->yaw = $yaw;
                            $pk->pitch = $pitch;
                            $pk->headYaw = $yaw;
                            $pk->onGround = $entity->onGround;
                            $player->sendDataPacket($pk, false, false);
                        } elseif ($entity instanceof BaseNPC and $entity->namedtag->getShort("Walk") === 0) {
                            $pk = new MoveActorAbsolutePacket();
                            $pk->entityRuntimeId = $entity->getId();
                            $pk->position = $entity->asVector3();
                            $pk->xRot = $pitch;
                            $pk->yRot = $yaw;
                            $pk->zRot = $yaw;
                            $player->sendDataPacket($pk, false, false);
                        }
                    }
                }
            }
        }
    }
}