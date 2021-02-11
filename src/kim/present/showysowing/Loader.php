<?php
declare(strict_types=1);

namespace kim\present\showysowing;

use kim\present\showysowing\entity\SowFallingBlock;
use pocketmine\block\Crops;
use pocketmine\entity\Location;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\math\Facing;
use pocketmine\math\Vector3;
use pocketmine\plugin\PluginBase;

final class Loader extends PluginBase implements Listener{
    private int $maxStep;
    private float $risePerStep;
    private bool $clockwise;

    protected function onEnable() : void{
        $this->maxStep = (int) $this->getConfigFloat("max-step", 32);
        $this->risePerStep = $this->getConfigFloat("rise-per-step", 32);
        $this->clockwise = $this->getConfigBool("clockwise", true);
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /**
     * @priority LOWEST
     *
     * Works when holding a crops item and sneaking + right click.
     * The crops are rotated and placed based on the touched block.
     * The number of steps, direction of rotation, and amount of rising are read from config.yml.
     */
    public function onPlayerInteract(PlayerInteractEvent $event) : void{
        if($event->getAction() !== PlayerInteractEvent::RIGHT_CLICK_BLOCK)
            return;

        $item = $event->getItem();
        $block = $item->getBlock();
        if(!$block instanceof Crops)
            return;

        $player = $event->getPlayer();
        if(!$player->isSneaking() || SowFallingBlock::getCount($player) > 0)
            return;

        $event->cancel();
        $world = $player->getWorld();
        $pos = $event->getBlock()->getPos()->add(0.5, 1, 0.5);

        $baseDirection = $player->getHorizontalFacing();
        $direction = $baseDirection;
        $add = new Vector3(0, 0, 0);
        $range = 1;
        for($step = 0; $step < $this->maxStep && !$item->isNull(); ++$step, $item->pop()){
            $entity = new SowFallingBlock(Location::fromObject($pos->add($add->x, $step * $this->risePerStep, $add->z), $world), $player, clone $block, (int) $pos->y);
            $entity->spawnToAll();

            $next = $add->getSide($direction);
            if(abs($next->x) <= $range && abs($next->z) <= $range){
                $add = $next;
            }else{
                $direction = Facing::rotateY($direction, $this->clockwise);
                if($direction === $baseDirection){
                    $range++;
                }
                $add = $add->getSide($direction);
            }
        }
        $player->getInventory()->setItemInHand($item);
    }

    private function getConfigFloat(string $k, float $default) : float{
        return (float) $this->getConfig()->get($k, $default);
    }

    private function getConfigBool(string $k, bool $default) : bool{
        $value = $this->getConfig()->get($k, $default);

        if(is_bool($value))
            return $value;

        switch(strtolower($value)){
            case "on":
            case "true":
            case "1":
            case "yes":
                return true;
        }

        return false;
    }
}