<?php

declare(strict_types=1);

namespace brokiem\snpc;

use brokiem\snpc\entity\BaseNPC;
use brokiem\snpc\entity\CustomHuman;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\lang\Language;
use pocketmine\player\Player;
use pocketmine\utils\TextFormat;

class EventHandler implements Listener
{

    /** @var SimpleNPC */
    private SimpleNPC $plugin;

    public function __construct(SimpleNPC $plugin)
    {
        $this->plugin = $plugin;
    }

    public function onDamage(EntityDamageEvent $event): void
    {
        $entity = $event->getEntity();

        if ($entity instanceof CustomHuman || $entity instanceof BaseNPC) {
            $event->cancel();
        }

        if ($event instanceof EntityDamageByEntityEvent) {
            if ($entity instanceof CustomHuman || $entity instanceof BaseNPC) {
                $damager = $event->getDamager();

                if ($damager instanceof Player) {
                    if (isset($this->plugin->removeNPC[$damager->getName()]) && !$entity->isFlaggedForDespawn()) {
                        $entity->flagForDespawn();
                        $damager->sendMessage(TextFormat::GREEN . "The NPC was successfully removed!");
                        unset($this->plugin->removeNPC[$damager->getName()]);
                        return;
                    }

                    if (!isset($this->plugin->lastHit[$damager->getName()][$entity->getId()])) {
                        $this->plugin->lastHit[$damager->getName()][$entity->getId()] = microtime(true);
                        return;
                    }

                    $coldown = $this->plugin->settings["commandExecuteColdown"] ?? 2.0;
                    if (($coldown + (float)$this->plugin->lastHit[$damager->getName()][$entity->getId()]) > microtime(true)) {
                        return;
                    }

                    $this->plugin->lastHit[$damager->getName()][$entity->getId()] = microtime(true);

                    if (($commands = $entity->namedtag->getCompoundTag("Commands")) !== null) {
                        foreach ($commands as $stringTag) {
                            $this->plugin->getServer()->getCommandMap()->dispatch(new ConsoleCommandSender($this->plugin->getServer(), new Language("eng")), str_replace("{player}", '"' . $damager->getName() . '"', $stringTag->getValue()));
                        }
                    }
                }

                $event->cancel();
            }
        }
    }

    public function onMotion(EntityMotionEvent $event): void
    {
        $entity = $event->getEntity();

        if ($entity instanceof CustomHuman || $entity instanceof BaseNPC) {
            if ($entity->namedtag->hasTag("Walk") && $entity->namedtag->getShort("Walk") === 0) {
                $event->cancel();
            }
        }
    }

    public function onQuit(PlayerQuitEvent $event): void
    {
        $player = $event->getPlayer();

        if (isset($this->plugin->lastHit[$player->getName()])) {
            unset($this->plugin->lastHit[$player->getName()]);
        }
    }
}