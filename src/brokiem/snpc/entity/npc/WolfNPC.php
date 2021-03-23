<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class WolfNPC extends BaseNPC {

    public const NETWORK_ID = EntityIds::WOLF;

    public float $height = 0.85;
    public float $width = 1;
}
