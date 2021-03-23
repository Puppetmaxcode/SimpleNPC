<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class HorseNPC extends BaseNPC {

    public const NETWORK_ID = EntityIds::HORSE;

    public float $height = 1.6;
    public float $width = 1;
}
