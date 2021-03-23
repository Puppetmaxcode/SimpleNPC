<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class SpiderNPC extends BaseNPC {

    public const NETWORK_ID = EntityIds::SPIDER;

    public float $height = 0.9;
    public float $width = 1;
}
