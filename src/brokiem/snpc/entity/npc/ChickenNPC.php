<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class ChickenNPC extends BaseNPC {

    public const NETWORK_ID = EntityIds::CHICKEN;

    public float $height = 0.7;
    public float $width = 1;
}
