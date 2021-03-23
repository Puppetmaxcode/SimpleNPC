<?php

declare(strict_types=1);

namespace brokiem\snpc\entity\npc;

use brokiem\snpc\entity\BaseNPC;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;

class SkeletonNPC extends BaseNPC {

    public const NETWORK_ID = EntityIds::SKELETON;

    public float $height = 1.99;
    public float $width = 1;
}
