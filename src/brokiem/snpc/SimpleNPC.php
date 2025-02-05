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
use pocketmine\entity\Skin;
use pocketmine\level\Location;
use pocketmine\nbt\tag\ByteArrayTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\UUID;
use ReflectionClass;
use RuntimeException;

class SimpleNPC extends PluginBase {
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

    public function onEnable(): void{
        self::$i = $this;

        if($this->isDev){
            $this->getLogger()->warning("You are using the Development version of SimpleNPC. The plugin will experience errors, crashes, or bugs. Only use this version if you are testing. Don't use the Dev version in production!");
        }

        self::registerEntity(CustomHuman::class, self::ENTITY_HUMAN);
        self::registerEntity(WalkingHuman::class, self::ENTITY_WALKING_HUMAN);
        NPCManager::registerAllNPC();

        $this->initConfiguration();
        $this->spawnAllNPCs();
        $this->getServer()->getCommandMap()->registerAll("SimpleNPC", [new Commands("snpc", $this), new RcaCommand("rca", $this)]);
        $this->getServer()->getPluginManager()->registerEvents(new EventHandler($this), $this);

        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(function (): void{
            $this->getServer()->getAsyncPool()->submitTask(new CheckUpdateTask($this->getDescription()->getVersion(), $this));
        }), 864000); // 12 hours
    }

    public static function getInstance(): self{
        return self::$i;
    }

    private function initConfiguration(): void{
        if(!is_dir($this->getDataFolder() . "npcs") && !mkdir($concurrentDirectory = $this->getDataFolder() . "npcs") && !is_dir($concurrentDirectory)){
            throw new RuntimeException(sprintf('Directory "%s" was not created', $concurrentDirectory));
        }

        if($this->getConfig()->get("config-version") !== 2){
            $this->getLogger()->notice("Your configuration file is outdated, updating the config.yml...");
            $this->getLogger()->notice("The old configuration file can be found at config.old.yml");

            rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "config.old.yml");

            $this->saveDefaultConfig();
            $this->reloadConfig();
        }

        $this->settings["lookToPlayersEnabled"] = $this->getConfig()->get("enable-look-to-players", true);
        $this->settings["maxLookDistance"] = $this->getConfig()->get("max-look-distance", 8);
        $this->settings["commandExecuteColdown"] = (float)$this->getConfig()->get("command-execute-coldown", 1.0);

        $this->getLogger()->debug("InitConfig: Successfully!");
    }

    public static function registerEntity(string $entityClass, string $name, bool $force = true, array $saveNames = []): bool{
        if(!class_exists($entityClass)){
            throw new \ClassNotFoundException("Class $entityClass not found.");
        }

        $class = new ReflectionClass($entityClass);
        if(is_a($entityClass, BaseNPC::class, true) or is_a($entityClass, CustomHuman::class, true) and !$class->isAbstract()){
            self::$entities[$entityClass] = array_merge($saveNames, [$name]);
            self::$npcType[] = $name;

            foreach(array_merge($saveNames, [$name]) as $saveName){
                self::$entities[$saveName] = $entityClass;
            }

            return Entity::registerEntity($entityClass, $force, array_merge($saveNames, [$name]));
        }

        return false;
    }

    private function spawnAllNPCs(): void{
        if (empty(glob($this->getDataFolder() . "npcs/*.json"))) { return; }

        foreach(glob($this->getDataFolder() . "npcs/*.json") as $path){
            $fileContents = file_get_contents($path);
            if ($fileContents === false) { continue; }
            /** @var array $decoded */
            $decoded = json_decode($fileContents, true);

            if(in_array(strtolower($decoded["type"]), self::$npcType, true)){
                if(($decoded["type"] === self::ENTITY_HUMAN) || $decoded["type"] === self::ENTITY_WALKING_HUMAN){
                    if($decoded["skinId"] === null || $decoded["skinData"] === null){
                        $decoded["skinId"] = UUID::fromRandom()->toString() . ".steveSkin";
                        $decoded["skinData"] = $this->getSteveSkinData();
                    }
                }

                $this->getServer()->loadLevel($decoded["world"]);
                $world = $this->getServer()->getLevelByName($decoded["world"]);
                if($world === null){ continue; }
                if(!$world->loadChunk((int)$decoded["position"][0] >> 4, (int)$decoded["position"][2] >> 4)){
                    $this->getLogger()->debug("Spawn Ignored for NPC " . basename($path, ".json") . " because chunk is not populated or chunk can't loaded");
                    continue;
                }
                $nbt = Entity::createBaseNBT(new Location($decoded["position"][0], $decoded["position"][1], $decoded["position"][2], $decoded["position"][3], $decoded["position"][4], $world));
                $commands = new CompoundTag("Commands");
                foreach($decoded["commands"] as $command){
                    $commands->setString($command, $command);
                }
                $nbt->setTag($commands);
                $nbt->setShort("Walk", !$decoded["walk"] ? 0 : 1);
                $nbt->setString("Identifier", basename($path, ".json"));

                if($decoded["type"] === self::ENTITY_HUMAN || $decoded["type"] === self::ENTITY_WALKING_HUMAN){
                    /** @phpstan-ignore-next-line */
                    $nbt->setTag(new CompoundTag("Skin", ["Name" => new StringTag("Name", $decoded["skinId"]), "Data" => new ByteArrayTag("Data", in_array(strlen(base64_decode($decoded["skinData"])), Skin::ACCEPTED_SKIN_SIZES, true) ? base64_decode($decoded["skinData"]) : $this->getSteveSkinData()), "CapeData" => new ByteArrayTag("CapeData", strlen(base64_decode($decoded["capeData"])) === 8192 ? base64_decode($decoded["capeData"]) : ""), "GeometryName" => new StringTag("GeometryName", $decoded["geometryName"]), "GeometryData" => new ByteArrayTag("GeometryData", base64_decode($decoded["geometryData"]))]));

                    $entity = $decoded["walk"] ? new WalkingHuman($world, $nbt) : new CustomHuman($world, $nbt);
                }else{
                    $entity = NPCManager::createEntity($decoded["type"], $world, $nbt);
                    if($entity === null){
                        $this->getLogger()->warning("Entity {$decoded["type"]} is invalid, make sure you register the entity first!");
                        return;
                    }
                    $entity->setGenericFlag(Entity::DATA_FLAG_SILENT, true);
                }

                if($decoded["showNametag"]){
                    $entity->setNameTag(str_replace("{line}", PHP_EOL, $decoded["nametag"]));
                    $entity->setNameTagAlwaysVisible();
                }

                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function () use ($path, $entity): void{
                    $this->getLogger()->debug("Spawned NPC Entity: " . get_class($entity) . " (" . basename($path) . ")");
                    $entity->spawnToAll();
                }), 80); // wait for chunk load
            }
        }

        $this->getLogger()->debug("SpawnAllNPCs: Successfully!");
    }

    /**
     * @return false|string
     */
    public function getSteveSkinData(){
        return base64_decode("AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAqHQ3\/Kh0N\/yQYCP8qHQ3\/Kh0N\/yQYCP8kGAj\/HxAL\/3VHL\/91Ry\/\/dUcv\/3VHL\/91Ry\/\/dUcv\/3VHL\/91Ry\/\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKh0N\/yQYCP8vHw\/\/Lx8P\/yodDf8kGAj\/JBgI\/yQYCP91Ry\/\/akAw\/4ZTNP9qQDD\/hlM0\/4ZTNP91Ry\/\/dUcv\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACodDf8vHw\/\/Lx8P\/yYaCv8qHQ3\/JBgI\/yQYCP8kGAj\/dUcv\/2pAMP8jIyP\/IyMj\/yMjI\/8jIyP\/akAw\/3VHL\/8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAkGAj\/Lx8P\/yodDf8kGAj\/Kh0N\/yodDf8vHw\/\/Kh0N\/3VHL\/9qQDD\/IyMj\/yMjI\/8jIyP\/IyMj\/2pAMP91Ry\/\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKh0N\/y8fD\/8qHQ3\/JhoK\/yYaCv8vHw\/\/Lx8P\/yodDf91Ry\/\/akAw\/yMjI\/8jIyP\/IyMj\/yMjI\/9qQDD\/dUcv\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACodDf8qHQ3\/JhoK\/yYaCv8vHw\/\/Lx8P\/y8fD\/8qHQ3\/dUcv\/2pAMP8jIyP\/IyMj\/yMjI\/8jIyP\/Uigm\/3VHL\/8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAqHQ3\/JhoK\/y8fD\/8pHAz\/JhoK\/x8QC\/8vHw\/\/Kh0N\/3VHL\/9qQDD\/akAw\/2pAMP9qQDD\/akAw\/2pAMP91Ry\/\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKh0N\/ykcDP8mGgr\/JhoK\/yYaCv8mGgr\/Kh0N\/yodDf91Ry\/\/dUcv\/3VHL\/91Ry\/\/dUcv\/3VHL\/91Ry\/\/dUcv\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAoGwr\/KBsK\/yYaCv8nGwv\/KRwM\/zIjEP8tIBD\/LSAQ\/y8gDf8rHg3\/Lx8P\/ygcC\/8kGAj\/JhoK\/yseDf8qHQ3\/LSAQ\/y0gEP8yIxD\/KRwM\/ycbC\/8mGgr\/KBsK\/ygbCv8qHQ3\/Kh0N\/yQYCP8qHQ3\/Kh0N\/yQYCP8kGAj\/HxAL\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKBsK\/ygbCv8mGgr\/JhoK\/yweDv8pHAz\/Kx4N\/zMkEf8rHg3\/Kx4N\/yseDf8zJBH\/QioS\/z8qFf8sHg7\/KBwL\/zMkEf8rHg3\/KRwM\/yweDv8mGgr\/JhoK\/ygbCv8oGwr\/Kh0N\/yQYCP8vHw\/\/Lx8P\/yodDf8kGAj\/JBgI\/yQYCP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACweDv8mGAv\/JhoK\/ykcDP8rHg7\/KBsL\/yQYCv8pHAz\/Kx4N\/7aJbP+9jnL\/xpaA\/72Lcv+9jnT\/rHZa\/zQlEv8pHAz\/JBgK\/ygbC\/8rHg7\/KRwM\/yYaCv8mGAv\/LB4O\/yodDf8vHw\/\/Lx8P\/yYaCv8qHQ3\/JBgI\/yQYCP8kGAj\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAoGwr\/KBoN\/y0dDv8sHg7\/KBsK\/ycbC\/8sHg7\/LyIR\/6p9Zv+0hG3\/qn1m\/62Abf+cclz\/u4ly\/5xpTP+caUz\/LyIR\/yweDv8nGwv\/KBsK\/yweDv8tHQ7\/KBoN\/ygbCv8kGAj\/Lx8P\/yodDf8kGAj\/Kh0N\/yodDf8vHw\/\/Kh0N\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKBsK\/ygbCv8oGwr\/JhoM\/yMXCf+HWDr\/nGNF\/zooFP+0hG3\/\/\/\/\/\/1I9if+1e2f\/u4ly\/1I9if\/\/\/\/\/\/qn1m\/zooFP+cY0X\/h1g6\/yMXCf8mGgz\/KBsK\/ygbCv8oGwr\/Kh0N\/y8fD\/8qHQ3\/JhoK\/yYaCv8vHw\/\/Lx8P\/yodDf8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAACgbCv8oGwr\/KBoN\/yYYC\/8sHhH\/hFIx\/5ZfQf+IWjn\/nGNG\/7N7Yv+3gnL\/akAw\/2pAMP++iGz\/ompH\/4BTNP+IWjn\/ll9B\/4RSMf8sHhH\/JhgL\/ygaDf8oGwr\/KBsK\/yodDf8qHQ3\/JhoK\/yYaCv8vHw\/\/Lx8P\/y8fD\/8qHQ3\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAsHg7\/KBsK\/y0dDv9iQy\/\/nWpP\/5pjRP+GUzT\/dUcv\/5BeQ\/+WX0D\/d0I1\/3dCNf93QjX\/d0I1\/49ePv+BUzn\/dUcv\/4ZTNP+aY0T\/nWpP\/2JDL\/8tHQ7\/KBsK\/yweDv8qHQ3\/JhoK\/y8fD\/8pHAz\/JhoK\/x8QC\/8vHw\/\/Kh0N\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAhlM0\/4ZTNP+aY0T\/hlM0\/5xnSP+WX0H\/ilk7\/3RIL\/9vRSz\/bUMq\/4FTOf+BUzn\/ek4z\/4NVO\/+DVTv\/ek4z\/3RIL\/+KWTv\/n2hJ\/5xnSP+aZEr\/nGdI\/5pjRP+GUzT\/hlM0\/3VHL\/8mGgr\/JhoK\/yYaCv8mGgr\/dUcv\/4ZTNP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABWScz\/VknM\/1ZJzP9WScz\/KCgo\/ygoKP8oKCj\/KCgo\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMzM\/3VHL\/91Ry\/\/dUcv\/3VHL\/91Ry\/\/dUcv\/wDMzP8AYGD\/AGBg\/wBgYP8AYGD\/AGBg\/wBgYP8AYGD\/AGBg\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKio\/wDMzP8AzMz\/AKio\/2pAMP9RMSX\/akAw\/1ExJf8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVknM\/1ZJzP9WScz\/VknM\/ygoKP8oKCj\/KCgo\/ygoKP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADMzP9qQDD\/akAw\/2pAMP9qQDD\/akAw\/2pAMP8AzMz\/AGBg\/wBgYP8AYGD\/AGBg\/wBgYP8AYGD\/AGBg\/wBgYP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADMzP8AzMz\/AMzM\/wDMzP9qQDD\/UTEl\/2pAMP9RMSX\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAFZJzP9WScz\/VknM\/1ZJzP8oKCj\/KCgo\/ygoKP8oKCj\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAzMz\/akAw\/2pAMP9qQDD\/akAw\/2pAMP9qQDD\/AMzM\/wBgYP8AYGD\/AGBg\/wBgYP8AYGD\/AGBg\/wBgYP8AYGD\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAzMz\/AMzM\/wDMzP8AqKj\/UTEl\/2pAMP9RMSX\/akAw\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABWScz\/VknM\/1ZJzP9WScz\/KCgo\/ygoKP8oKCj\/KCgo\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMzM\/3VHL\/91Ry\/\/dUcv\/3VHL\/91Ry\/\/dUcv\/wDMzP8AYGD\/AGBg\/wBgYP8AYGD\/AGBg\/wBgYP8AYGD\/AGBg\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKio\/wDMzP8AzMz\/AKio\/1ExJf9qQDD\/UTEl\/2pAMP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwKHL\/MChy\/yYhW\/8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8mIVv\/MChy\/zAocv9GOqX\/Rjql\/0Y6pf86MYn\/AH9\/\/wB\/f\/8Af3\/\/AFtb\/wCZmf8Anp7\/gVM5\/6JqR\/+BUzn\/gVM5\/wCenv8Anp7\/AH9\/\/wB\/f\/8Af3\/\/AH9\/\/wCenv8AqKj\/AKio\/wCoqP8Ar6\/\/AK+v\/wCoqP8AqKj\/AH9\/\/wB\/f\/8Af3\/\/AH9\/\/wCenv8AqKj\/AK+v\/wCoqP8Af3\/\/AH9\/\/wB\/f\/8Af3\/\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMChy\/yYhW\/8mIVv\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8wKHL\/JiFb\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/wB\/f\/8AaGj\/AGho\/wB\/f\/8AqKj\/AKio\/wCenv+BUzn\/gVM5\/wCenv8Ar6\/\/AK+v\/wB\/f\/8AaGj\/AGho\/wBoaP8AqKj\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wCvr\/8AqKj\/AKio\/wBoaP8AaGj\/AGho\/wB\/f\/8Ar6\/\/AKio\/wCvr\/8Anp7\/AH9\/\/wBoaP8AaGj\/AH9\/\/wCvr\/8Ar6\/\/AK+v\/wCvr\/8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADAocv8mIVv\/MChy\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/MChy\/yYhW\/8wKHL\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8AaGj\/AGho\/wBoaP8Af3\/\/AK+v\/wCvr\/8AqKj\/AJ6e\/wCZmf8AqKj\/AK+v\/wCvr\/8AaGj\/AGho\/wBoaP8AaGj\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wCoqP8Af3\/\/AGho\/wBoaP8Af3\/\/AKio\/wCvr\/8Ar6\/\/AK+v\/wB\/f\/8AaGj\/AGho\/wB\/f\/8Ar6\/\/AK+v\/wCvr\/8Ar6\/\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwKHL\/JiFb\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8mIVv\/MChy\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/AFtb\/wBoaP8AaGj\/AFtb\/wCvr\/8Ar6\/\/AK+v\/wCenv8AmZn\/AK+v\/wCvr\/8Ar6\/\/AFtb\/wBoaP8AaGj\/AFtb\/wCvr\/8Ar6\/\/AJmZ\/wCvr\/8AqKj\/AJmZ\/wCvr\/8AqKj\/AH9\/\/wBoaP8AaGj\/AH9\/\/wCenv8Ar6\/\/AK+v\/wCenv8Af3\/\/AGho\/wBoaP8Af3\/\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMChy\/yYhW\/8wKHL\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8wKHL\/MChy\/yYhW\/8wKHL\/OjGJ\/zoxif86MYn\/OjGJ\/wBoaP8AW1v\/AFtb\/wBbW\/8AmZn\/AJmZ\/wCvr\/8Ar6\/\/AJmZ\/wCvr\/8AmZn\/AJmZ\/wBbW\/8AW1v\/AFtb\/wBbW\/8Ar6\/\/AKio\/wCZmf8Ar6\/\/AKio\/wCZmf8Ar6\/\/AK+v\/5ZfQf+WX0H\/ll9B\/4dVO\/+qfWb\/qn1m\/6p9Zv+qfWb\/h1U7\/5ZfQf+WX0H\/ll9B\/6p9Zv+qfWb\/qn1m\/6p9Zv8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADAocv8mIVv\/MChy\/zAocv9GOqX\/OjGJ\/zoxif9GOqX\/MChy\/yYhW\/8mIVv\/MChy\/zoxif86MYn\/OjGJ\/zoxif8AW1v\/AFtb\/wBbW\/8AaGj\/AJmZ\/wCZmf8Ar6\/\/AKio\/wCZmf8Ar6\/\/AKio\/wCZmf8AaGj\/AFtb\/wBbW\/8AaGj\/AK+v\/wCZmf8AmZn\/AK+v\/wCoqP8AmZn\/AKio\/wCvr\/+WX0H\/ll9B\/5ZfQf+HVTv\/qn1m\/5ZvW\/+qfWb\/qn1m\/5ZfQf+HVTv\/ll9B\/5ZfQf+qfWb\/qn1m\/6p9Zv+qfWb\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwKHL\/JiFb\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8mIVv\/MChy\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/AGho\/wBbW\/8AW1v\/AGho\/wCZmf8Ar6\/\/AK+v\/wCZmf8AqKj\/AK+v\/wCoqP8AmZn\/AGho\/wBbW\/8AaGj\/AGho\/wCvr\/8AqKj\/AJmZ\/wCoqP8Ar6\/\/AJmZ\/wCZmf8Ar6\/\/h1U7\/5ZfQf+WX0H\/h1U7\/6p9Zv+Wb1v\/qn1m\/5ZvW\/+WX0H\/h1U7\/5ZfQf+WX0H\/qn1m\/5ZvW\/+Wb1v\/qn1m\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMChy\/zAocv8wKHL\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8wKHL\/JiFb\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/wB\/f\/8AaGj\/AGho\/wB\/f\/8AmZn\/AK+v\/wCvr\/8AmZn\/AKio\/wCvr\/8AqKj\/AJmZ\/wB\/f\/8AaGj\/AGho\/wBoaP8Ar6\/\/AK+v\/wCZmf8AqKj\/AK+v\/wCZmf8AmZn\/AK+v\/4dVO\/+WX0H\/ll9B\/5ZfQf+qfWb\/qn1m\/6p9Zv+Wb1v\/ll9B\/4dVO\/+WX0H\/h1U7\/6p9Zv+qfWb\/qn1m\/6p9Zv8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADAocv8wKHL\/MChy\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/MChy\/zAocv8wKHL\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8Af3\/\/AGho\/wBoaP8Af3\/\/AK+v\/wCvr\/8Ar6\/\/AJmZ\/wCoqP8Ar6\/\/AK+v\/wCZmf8Af3\/\/AGho\/wBoaP8Af3\/\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wCvr\/+HVTv\/ll9B\/4dVO\/+WX0H\/qn1m\/6p9Zv+qfWb\/lm9b\/5ZfQf+WX0H\/ll9B\/4dVO\/+qfWb\/qn1m\/6p9Zv+qfWb\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\/Pz\/\/Pz8\/\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8wKHL\/Pz8\/\/z8\/P\/9ra2v\/a2tr\/2tra\/9ra2v\/AH9\/\/wBoaP8Af3\/\/AH9\/\/wCZmf8AmZn\/AJmZ\/wCoqP8Ar6\/\/AKio\/wCvr\/8AmZn\/AH9\/\/wBoaP8AaGj\/AH9\/\/wCZmf8AmZn\/AJmZ\/wCvr\/8AmZn\/AJmZ\/wCvr\/8AqKj\/ll9B\/5ZfQf+HVTv\/ll9B\/6p9Zv+qfWb\/qn1m\/6p9Zv+WX0H\/ll9B\/5ZfQf+WX0H\/qn1m\/5ZvW\/+qfWb\/lm9b\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAPz8\/\/z8\/P\/8\/Pz\/\/Pz8\/\/2tra\/9ra2v\/a2tr\/2tra\/8\/Pz\/\/Pz8\/\/z8\/P\/8\/Pz\/\/a2tr\/2tra\/9ra2v\/a2tr\/zAocv8mIVv\/MChy\/yYhW\/9GOqX\/Rjql\/0Y6pf9GOqX\/Rjql\/zoxif8Ar6\/\/AJmZ\/wB\/f\/8mIVv\/JiFb\/zAocv9GOqX\/OjGJ\/zoxif8AqKj\/AJmZ\/wCZmf86MYn\/Rjql\/5ZfQf+WX0H\/h1U7\/5ZfQf+qfWb\/qn1m\/5ZvW\/+qfWb\/h1U7\/5ZfQf+HVTv\/ll9B\/6p9Zv+Wb1v\/qn1m\/5ZvW\/8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD8\/P\/8\/Pz\/\/Pz8\/\/z8\/P\/9ra2v\/a2tr\/2tra\/9ra2v\/Pz8\/\/z8\/P\/8\/Pz\/\/Pz8\/\/2tra\/9ra2v\/a2tr\/2tra\/8wKHL\/JiFb\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/0Y6pf9GOqX\/OjGJ\/wCZmf8wKHL\/JiFb\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/OjGJ\/wCZmf9GOqX\/Rjql\/0Y6pf+WX0H\/ll9B\/5ZfQf+WX0H\/lm9b\/6p9Zv+Wb1v\/lm9b\/4dVO\/+WX0H\/ll9B\/5ZfQf+qfWb\/lm9b\/6p9Zv+Wb1v\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABWScz\/VknM\/1ZJzP9WScz\/KCgo\/ygoKP8oKCj\/KCgo\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKio\/wDMzP8AzMz\/AKio\/1ExJf9qQDD\/UTEl\/2pAMP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAVknM\/1ZJzP9WScz\/VknM\/ygoKP8oKCj\/KCgo\/ygoKP8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADMzP8AzMz\/AMzM\/wDMzP9RMSX\/akAw\/1ExJf9qQDD\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAFZJzP9WScz\/VknM\/1ZJzP8oKCj\/KCgo\/ygoKP8oKCj\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAqKj\/AMzM\/wDMzP8AzMz\/akAw\/1ExJf9qQDD\/UTEl\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAABWScz\/VknM\/1ZJzP9WScz\/KCgo\/ygoKP8oKCj\/KCgo\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAKio\/wDMzP8AzMz\/AKio\/2pAMP9RMSX\/akAw\/1ExJf8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwKHL\/MChy\/yYhW\/8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8mIVv\/MChy\/zAocv86MYn\/Rjql\/0Y6pf9GOqX\/AH9\/\/wB\/f\/8Af3\/\/AH9\/\/wCoqP8Ar6\/\/AKio\/wCenv8Af3\/\/AH9\/\/wB\/f\/8Af3\/\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMChy\/zAocv8mIVv\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8wKHL\/JiFb\/yYhW\/8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/wB\/f\/8AaGj\/AGho\/wB\/f\/8Anp7\/AK+v\/wCoqP8Ar6\/\/AH9\/\/wBoaP8AaGj\/AGho\/wCvr\/8Ar6\/\/AK+v\/wCvr\/8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADAocv8wKHL\/JiFb\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/MChy\/zAocv8mIVv\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8Af3\/\/AGho\/wBoaP8Af3\/\/AK+v\/wCvr\/8Ar6\/\/AKio\/wB\/f\/8AaGj\/AGho\/wB\/f\/8Ar6\/\/AK+v\/wCvr\/8Ar6\/\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwKHL\/MChy\/yYhW\/8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8wKHL\/JiFb\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/AH9\/\/wBoaP8AaGj\/AH9\/\/wCenv8Ar6\/\/AK+v\/wCenv8Af3\/\/AGho\/wBoaP8Af3\/\/AK+v\/wCvr\/8Ar6\/\/AK+v\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMChy\/yYhW\/8wKHL\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8wKHL\/MChy\/yYhW\/8wKHL\/OjGJ\/zoxif86MYn\/OjGJ\/5ZfQf+WX0H\/ll9B\/4dVO\/+qfWb\/qn1m\/6p9Zv+qfWb\/h1U7\/5ZfQf+WX0H\/ll9B\/6p9Zv+qfWb\/qn1m\/6p9Zv8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADAocv8mIVv\/JiFb\/zAocv9GOqX\/OjGJ\/zoxif9GOqX\/MChy\/zAocv8mIVv\/MChy\/zoxif86MYn\/OjGJ\/zoxif+WX0H\/ll9B\/4dVO\/+WX0H\/qn1m\/6p9Zv+Wb1v\/qn1m\/4dVO\/+WX0H\/ll9B\/5ZfQf+qfWb\/qn1m\/6p9Zv+qfWb\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAwKHL\/MChy\/yYhW\/8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8wKHL\/JiFb\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/ll9B\/5ZfQf+HVTv\/ll9B\/5ZvW\/+qfWb\/lm9b\/6p9Zv+HVTv\/ll9B\/5ZfQf+HVTv\/qn1m\/5ZvW\/+Wb1v\/qn1m\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAMChy\/zAocv8mIVv\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf8wKHL\/MChy\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/4dVO\/+WX0H\/h1U7\/5ZfQf+Wb1v\/qn1m\/6p9Zv+qfWb\/ll9B\/5ZfQf+WX0H\/h1U7\/6p9Zv+qfWb\/qn1m\/6p9Zv8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAADAocv8wKHL\/MChy\/zAocv9GOqX\/Rjql\/0Y6pf9GOqX\/MChy\/zAocv8wKHL\/MChy\/0Y6pf9GOqX\/Rjql\/0Y6pf+HVTv\/ll9B\/5ZfQf+WX0H\/lm9b\/6p9Zv+qfWb\/qn1m\/5ZfQf+HVTv\/ll9B\/4dVO\/+qfWb\/qn1m\/6p9Zv+qfWb\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA\/Pz\/\/Pz8\/\/zAocv8wKHL\/Rjql\/0Y6pf9GOqX\/Rjql\/zAocv8wKHL\/Pz8\/\/z8\/P\/9ra2v\/a2tr\/2tra\/9ra2v\/ll9B\/5ZfQf+WX0H\/ll9B\/6p9Zv+qfWb\/qn1m\/6p9Zv+WX0H\/h1U7\/5ZfQf+WX0H\/lm9b\/6p9Zv+Wb1v\/qn1m\/wAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAPz8\/\/z8\/P\/8\/Pz\/\/Pz8\/\/2tra\/9ra2v\/a2tr\/2tra\/8\/Pz\/\/Pz8\/\/z8\/P\/8\/Pz\/\/a2tr\/2tra\/9ra2v\/a2tr\/5ZfQf+HVTv\/ll9B\/4dVO\/+qfWb\/lm9b\/6p9Zv+qfWb\/ll9B\/4dVO\/+WX0H\/ll9B\/5ZvW\/+qfWb\/lm9b\/6p9Zv8AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAD8\/P\/8\/Pz\/\/Pz8\/\/z8\/P\/9ra2v\/a2tr\/2tra\/9ra2v\/Pz8\/\/z8\/P\/8\/Pz\/\/Pz8\/\/2tra\/9ra2v\/a2tr\/2tra\/+WX0H\/ll9B\/5ZfQf+HVTv\/lm9b\/5ZvW\/+qfWb\/lm9b\/5ZfQf+WX0H\/ll9B\/5ZfQf+Wb1v\/qn1m\/5ZvW\/+qfWb\/AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA==");
    }
}