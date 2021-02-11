<?php /** @noinspection PhpInternalEntityUsedInspection */
declare(strict_types=1);

namespace kim\present\showysowing\entity;

use pocketmine\entity\object\ItemEntity;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\player\Player;

/**
 * An entity that functions like an item entity.
 * Re-written for return immediately to the owner player.
 * When save into chunk, it is saved as an ItemEntity.
 */
final class ReturnItemEntity extends ItemEntity{
    /** @override for auto pickup to owning player */
    protected function entityBaseTick(int $tickDiff = 1) : bool{
        if($this->closed){
            return false;
        }

        $hasUpdate = parent::entityBaseTick($tickDiff);

        if(!$this->isFlaggedForDespawn() && $this->pickupDelay === 0){
            $player = $this->getOwningEntity();
            if($player instanceof Player){
                $this->onCollideWithPlayer($player);
                $hasUpdate = true;
            }
        }

        return $hasUpdate;
    }

    /** @override for save with chunk like default item entity */
    public function saveNBT() : CompoundTag{
        return (new ItemEntity($this->location, $this->item))->saveNBT();
    }
}