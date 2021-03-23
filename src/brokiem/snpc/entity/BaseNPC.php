<?php

declare(strict_types=1);

namespace brokiem\snpc\entity;

use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;

class BaseNPC extends Entity
{
    protected $gravity = 0.0;
    protected const NETWORK_ID = "";

    protected float $height = 1;
    protected float $width = 1;

    protected function getInitialSizeInfo(): EntitySizeInfo
    {
        return new EntitySizeInfo($this->height, $this->width);
    }

    public static function getNetworkTypeId(): string
    {
        return self::NETWORK_ID;
    }
}