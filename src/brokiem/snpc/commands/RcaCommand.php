<?php

declare(strict_types=1);

namespace brokiem\snpc\commands;

use brokiem\snpc\SimpleNPC;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use pocketmine\utils\TextFormat;

class RcaCommand extends Command implements PluginOwned
{

    /**
     * @var SimpleNPC
     */
    private SimpleNPC $owner;

    public function __construct(string $name, SimpleNPC $owner)
    {
        parent::__construct($name);
        $this->setPermission("snpc.rca");
        $this->setDescription("Execute command by player like sudo");
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

        if (count($args) < 2) {
            $sender->sendMessage(TextFormat::RED . "Please enter a player and a command.");
            return true;
        }

        $player = $this->getOwningPlugin()->getServer()->getPlayerExact(array_shift($args));
        if ($player instanceof Player) {
            $this->getOwningPlugin()->getServer()->getCommandMap()->dispatch($player, trim(implode(" ", $args)));
            return true;
        }

        $sender->sendMessage(TextFormat::RED . "Player not found.");

        return parent::execute($sender, $commandLabel, $args);
    }
}