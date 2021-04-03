<?php

declare(strict_types=1);

namespace brokiem\snpc;

use brokiem\snpc\commands\Commands;
use brokiem\snpc\commands\RcaCommand;
use brokiem\snpc\entity\BaseNPC;
use brokiem\snpc\entity\CustomHuman;
use brokiem\snpc\entity\WalkingHuman;
use brokiem\snpc\manager\NPCManager;
use brokiem\snpc\task\async\CheckUpdateTask;
use pocketmine\entity\Entity;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use ReflectionClass;

class SimpleNPC extends PluginBase
{
    public const ENTITY_HUMAN = "human_snpc";
    public const ENTITY_WALKING_HUMAN = "walking_human_snpc";

    /** @var self */
    private static $i;
    /** @var array */
    public $migrateNPC = [];
    /** @var array */
    public static $npcType = [];
    /** @var array */
    public static $entities = [];
    /** @var array */
    public $removeNPC = [];
    /** @var array */
    public $settings = [];
    /** @var array */
    public $lastHit = [];
    /** @var array */
    public $cachedUpdate = [];
    /** @var bool */
    private $isDev = false;
    /** @var array */
    public $idPlayers = [];

    public function onEnable(): void
    {
        self::$i = $this;

        if ($this->isDev) {
            $this->getLogger()->warning("You are using the Development version of SimpleNPC. The plugin will experience errors, crashes, or bugs. Only use this version if you are testing. Don't use the Dev version in production!");
        }

        self::registerEntity(CustomHuman::class, self::ENTITY_HUMAN);
        self::registerEntity(WalkingHuman::class, self::ENTITY_WALKING_HUMAN);
        NPCManager::registerAllNPC();

        $this->initConfiguration();
        $this->getServer()->getCommandMap()->registerAll("SimpleNPC", [
            new Commands("snpc", $this),
            new RcaCommand("rca", $this)
        ]);
        $this->getServer()->getPluginManager()->registerEvents(new EventHandler($this), $this);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void {
            $this->getServer()->getAsyncPool()->submitTask(new CheckUpdateTask($this->getDescription()->getVersion(), $this));
        }), 864000); // 12 hours
    }

    public static function getInstance(): self
    {
        return self::$i;
    }

    private function initConfiguration(): void
    {
        if ($this->getConfig()->get("config-version") !== 2) {
            $this->getLogger()->notice("Your configuration file is outdated, updating the config.yml...");
            $this->getLogger()->notice("The old configuration file can be found at config.old.yml");

            rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");

            $this->saveDefaultConfig();
            $this->reloadConfig();
        }

        $this->settings["lookToPlayersEnabled"] = $this->getConfig()->get("enable-look-to-players", true);
        $this->settings["maxLookDistance"] = $this->getConfig()->get("max-look-distance", 8);
        $this->settings["commandExecuteColdown"] = (float)$this->getConfig()->get("command-execute-coldown", 1.0);
    }

    public static function registerEntity(string $entityClass, string $name, bool $force = true, array $saveNames = []): bool
    {
        $class = new ReflectionClass($entityClass);
        if (is_a($entityClass, BaseNPC::class, true) or is_a($entityClass, CustomHuman::class, true) and !$class->isAbstract()) {
            self::$entities[$entityClass] = array_merge($saveNames, [$name]);
            self::$npcType[] = $name;

            foreach (array_merge($saveNames, [$name]) as $saveName) {
                self::$entities[$saveName] = $entityClass;
            }

            return Entity::registerEntity($entityClass, $force, array_merge($saveNames, [$name]));
        }

        return false;
    }
}