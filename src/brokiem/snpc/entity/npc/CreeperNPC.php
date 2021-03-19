<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\entity\Entity;

class CreeperNPC extends BaseNPC {

    public const NETWORK_ID = Entity::CREEPER;

    public $height = 1.7;
}