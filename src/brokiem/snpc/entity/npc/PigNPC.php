<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class PigNPC extends BaseNPC {

    public const NETWORK_ID = EntityIds::PIG;

    public float $height = 0.9;
    public float $width = 1;
}
