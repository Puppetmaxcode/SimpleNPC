<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class ShulkerNPC extends BaseNPC {

    public const NETWORK_ID = EntityIds::SHULKER;

    public float $height = 1;
    public float $width = 1;
}
