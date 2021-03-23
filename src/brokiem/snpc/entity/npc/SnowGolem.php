<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class SnowGolem extends BaseNPC {

    public const NETWORK_ID = EntityIds::SNOW_GOLEM;

    public float $height = 1.9;
    public float $width = 1;
}
