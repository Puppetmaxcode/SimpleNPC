<?php

declare(strict_types=1);

namespace brokiem\snpc\manager;

use brokiem\snpc\entity\BaseNPC;
use brokiem\snpc\entity\npc\BatNPC;
use brokiem\snpc\entity\npc\BlazeNPC;
use brokiem\snpc\entity\npc\ChickenNPC;
use brokiem\snpc\entity\npc\CowNPC;
use brokiem\snpc\entity\npc\CreeperNPC;
use brokiem\snpc\entity\npc\EndermanNPC;
use brokiem\snpc\entity\npc\HorseNPC;
use brokiem\snpc\entity\npc\OcelotNPC;
use brokiem\snpc\entity\npc\PigNPC;
use brokiem\snpc\entity\npc\PolarBearNPC;
use brokiem\snpc\entity\npc\SheepNPC;
use brokiem\snpc\entity\npc\ShulkerNPC;
use brokiem\snpc\entity\npc\SkeletonNPC;
use brokiem\snpc\entity\npc\SlimeNPC;
use brokiem\snpc\entity\npc\SnowGolem;
use brokiem\snpc\entity\npc\SpiderNPC;
use brokiem\snpc\entity\npc\VillagerNPC;
use brokiem\snpc\entity\npc\WitchNPC;
use brokiem\snpc\entity\npc\WolfNPC;
use brokiem\snpc\entity\npc\ZombieNPC;
use brokiem\snpc\SimpleNPC;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\Location;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

class NPCManager
{

    private static array $npcs = [
        BatNPC::class => ["bat", "minecraft:bat"],
        BlazeNPC::class => ["blaze", "minecraft:blaze"],
        ChickenNPC::class => ["chicken", "minecraft:chicken"],
        CowNPC::class => ["cow", "minecraft:cow"],
        CreeperNPC::class => ["creeper", "minecraft:creeper"],
        EndermanNPC::class => ["enderman", "minecraft:enderman"],
        HorseNPC::class => ["horse", "minecraft:horse"],
        OcelotNPC::class => ["ocelot", "minecraft:ocelot"],
        PigNPC::class => ["pig", "minecraft:pig"],
        PolarBearNPC::class => ["polar_bear", "minecraft:polarbear"],
        SheepNPC::class => ["sheep", "minecraft:sheep"],
        ShulkerNPC::class => ["shulker", "minecraft:shulker"],
        SkeletonNPC::class => ["skeleton", "minecraft:skeleton"],
        SlimeNPC::class => ["slime", "minecraft:slime"],
        SnowGolem::class => ["snow_golem", "minecraft:snowgolem"],
        SpiderNPC::class => ["spider", "minecraft:spider"],
        VillagerNPC::class => ["villager", "minecraft:villager"],
        WitchNPC::class => ["witch", "minecraft:witch"],
        WolfNPC::class => ["wolf", "minecraft:wolf"],
        ZombieNPC::class => ["zombie", "minecraft:zombie"]
    ];

    public static function registerAllNPC(): void {
        foreach (self::$npcs as $class => $saveNames) {
            SimpleNPC::registerEntity($class, array_shift($saveNames),  $saveNames);
        }
    }

    // TODO: aaaaaaaaa
    public static function createNPC(BaseNPC $baseNPC, Player $player, ?string $nametag = null, CompoundTag $commands = null, Location $customPos = null): bool
    {
        $nbt = EntityDataHelper::createBaseNBT($player->getPosition(), null, $player->getLocation()->getYaw(), $player->getLocation()->getPitch());
        $nbt->setTag("commands", $commands ?? new CompoundTag("Commands", []));
        $nbt->setShort("Walk", 0);

        if ($customPos !== null) {
            $nbt = EntityDataHelper::createBaseNBT($customPos, null, $customPos->getYaw(), $customPos->getPitch());
        }

        $entity = new ZombieNPC($player->getLocation());

        if ($nametag !== null) {
            $entity->setNameTag($nametag);
            $entity->setNameTagAlwaysVisible();
        }

        $entity->spawnToAll();
        //TODO: $player->sendMessage(TextFormat::GREEN . "NPC " . ucfirst($type) . " created successfully!");
        return true;
    }
}