<?php /** @noinspection PhpInternalEntityUsedInspection */
declare(strict_types=1);

namespace kim\present\showysowing\entity;

use pocketmine\block\BlockLegacyIds;
use pocketmine\block\Crops;
use pocketmine\entity\Entity;
use pocketmine\entity\EntitySizeInfo;
use pocketmine\entity\Location;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\player\PlayerDropItemEvent;
use pocketmine\item\Item;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\RuntimeBlockMapping;
use pocketmine\network\mcpe\protocol\types\entity\EntityIds;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataCollection;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\player\Player;
use pocketmine\world\BlockTransaction;
use pocketmine\world\sound\BlockPlaceSound;
use pocketmine\world\World;

/**
 * An entity that functions like an falling block.
 * Re-written for call events (block place or item drop) via owner player.
 * When it drop an item, drop SowItemEntity instead ItemEntity.
 */
final class SowFallingBlock extends Entity{
    /**
     * Counts of entities summoned by the each player
     * It is used to disable continuous use when a function is already in use.
     *
     * @var int[] string $playerHash => int $count
     */
    private static array $counts = [];

    public static function getCount(Player $player) : int{
        return self::$counts[spl_object_hash($player)] ?? 0;
    }

    private Crops $block;
    private Player $owningPlayer;
    private int $targetY;

    public function __construct(Location $location, Player $owningPlayer, Crops $block, int $targetY, ?CompoundTag $nbt = null){
        parent::__construct($location, $nbt);
        $this->gravity = 0.04;
        $this->drag = 0.02;

        $this->owningPlayer = $owningPlayer;
        $this->block = $block;
        $this->targetY = $targetY;

        if(!isset(self::$counts[$hash = spl_object_hash($owningPlayer)])){
            self::$counts[$hash] = 0;
        }
        self::$counts[$hash]++;
    }

    /** @override for working like falling block */
    protected function entityBaseTick(int $tickDiff = 1) : bool{
        if($this->closed)
            return false;

        $hasUpdate = parent::entityBaseTick($tickDiff);

        if($this->owningPlayer->isClosed() || !$this->owningPlayer->isConnected()){
            $this->kill();
        }elseif(!$this->isFlaggedForDespawn() && $this->onGround){
            if(!$this->place($this->getWorld(), $this->location->add(-0.5, -1, -0.5)->floor())){
                $this->kill();
            }

            $this->flagForDespawn();
            $hasUpdate = true;
        }

        return $hasUpdate;
    }

    /** @override for pass when more than 2 spaces higher than the target y */
    protected function move(float $dx, float $dy, float $dz) : void{
        $this->keepMovement = $this->location->y - $this->targetY > 2;
        parent::move($dx, $dy, $dz);
    }

    /** @override for drop item */
    protected function onDeath() : void{
        $event = new PlayerDropItemEvent($this->owningPlayer, $this->block->getPickedItem());
        $event->call();

        $item = $event->getItem();
        $inv = $this->owningPlayer->getInventory();
        if(!$event->isCancelled() || !empty($inv->addItem($item))){
            $itemEntity = new SowItemEntity(Location::fromObject($this->location, $this->getWorld()), $item);
            $itemEntity->setOwningEntity($this->owningPlayer);
            $itemEntity->setPickupDelay(10);
            $itemEntity->setMotion($motion ?? new Vector3(lcg_value() * 0.2 - 0.1, 0.2, lcg_value() * 0.2 - 0.1));
            $itemEntity->spawnToAll();
        }
    }

    /** @override for reduce summon count */
    protected function onDispose() : void{
        self::$counts[$hash = spl_object_hash($this->owningPlayer)]--;
        if(self::$counts[$hash] <= 0){
            unset(self::$counts[$hash]);
        }
        parent::onDispose();
    }

    /** @override for prevent damage */
    public function attack(EntityDamageEvent $source) : void{
        if($source->getCause() !== EntityDamageEvent::CAUSE_VOID){
            $source->cancel();
        }
    }

    /** @override for prevent save to chunk */
    public function canSaveWithChunk() : bool{
        return false;
    }

    /** @override for sync data of falling block id */
    public function getOffsetPosition(Vector3 $vector3) : Vector3{
        return $vector3->add(0, 0.5, 0);
    }

    /** @override for prevent interact from entity or blocks */
    protected function getInitialSizeInfo() : EntitySizeInfo{
        return new EntitySizeInfo(0.001, 0.001);
    }

    /** @override for spawn to falling block */
    public static function getNetworkTypeId() : string{
        return EntityIds::FALLING_BLOCK;
    }

    /** @override for spawn to falling block */
    protected function syncNetworkData(EntityMetadataCollection $properties) : void{
        parent::syncNetworkData($properties);

        $properties->setInt(EntityMetadataProperties::VARIANT, RuntimeBlockMapping::getInstance()->toRuntimeId($this->block->getFullId()));
    }

    /** Returns crops block */
    public function getBlock() : Crops{
        return clone $this->block;
    }

    /** Returns crops picked item */
    public function getItem() : Item{
        return $this->block->getPickedItem();
    }

    /**
     * Place crops block at given vector
     * It works like World::useItemOn(), but excludes PlayerInteractEvent
     *
     * @see World::useItemOn()
     */
    protected function place(World $world, Vector3 $vector) : bool{
        $blockClicked = $world->getBlock($vector);
        if($blockClicked->getId() === BlockLegacyIds::AIR)
            return false;

        $replaceVector = $vector->getSide(Facing::UP);
        $blockReplace = $world->getBlock($replaceVector);
        if(!$world->isInWorld($replaceVector->x, $replaceVector->y, $replaceVector->z))
            return false;

        $chunkX = $replaceVector->getFloorX() >> 4;
        $chunkZ = $replaceVector->getFloorZ() >> 4;
        if(!$world->isChunkLoaded($chunkX, $chunkZ) || !$world->isChunkGenerated($chunkX, $chunkZ) || $world->isChunkLocked($chunkX, $chunkZ))
            return false;

        $item = $this->getItem();
        $clickVector = new Vector3(0.5, 0, 0.5);
        if($blockClicked->onInteract($item, Facing::UP, $clickVector, $this->owningPlayer))
            return true;

        $hand = $item->getBlock(Facing::UP);
        $hand->position($world, $replaceVector->x, $replaceVector->y, $replaceVector->z);
        if($hand->canBePlacedAt($blockClicked, $clickVector, Facing::UP, true)){
            $blockReplace = $blockClicked;
            $hand->position($world, $replaceVector->x, $replaceVector->y, $replaceVector->z);
        }elseif(!$hand->canBePlacedAt($blockReplace, $clickVector, Facing::UP, false)){
            return false;
        }

        $ev = new BlockPlaceEvent($this->owningPlayer, $hand, $blockReplace, $blockClicked, $item);
        $ev->call();
        if($ev->isCancelled())
            return false;

        $tx = new BlockTransaction($world);
        if(!$hand->place($tx, $item, $blockReplace, $blockClicked, Facing::UP, $clickVector, $this->owningPlayer) || !$tx->apply())
            return false;

        foreach($tx->getBlocks() as [$x, $y, $z, $_]){
            $tile = $world->getTileAt($x, $y, $z);
            if($tile !== null){
                //TODO: seal this up inside block placement
                $tile->copyDataFromItem($item);
            }

            $world->getBlockAt($x, $y, $z)->onPostPlace();
        }

        $world->addSound($hand->getPos(), new BlockPlaceSound($hand));
        return true;
    }
}