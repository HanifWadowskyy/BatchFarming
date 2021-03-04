<?php

/**
 *  ____                           _   _  ___
 * |  _ \ _ __ ___  ___  ___ _ __ | |_| |/ (_)_ __ ___
 * | |_) | '__/ _ \/ __|/ _ \ '_ \| __| ' /| | '_ ` _ \
 * |  __/| | |  __/\__ \  __/ | | | |_| . \| | | | | | |
 * |_|   |_|  \___||___/\___|_| |_|\__|_|\_\_|_| |_| |_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author  PresentKim (debe3721@gmail.com)
 * @link    https://github.com/PresentKim
 * @license https://www.gnu.org/licenses/lgpl-3.0 LGPL-3.0 License
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 *
 * @noinspection PhpIllegalPsrClassPathInspection
 * @noinspection PhpDocSignatureInspection
 * @noinspection SpellCheckingInspection
 * @noinspection PhpDeprecationInspection
 */

declare(strict_types=1);

namespace kim\present\batchfarming\task;

use kim\present\batchfarming\object\SeedObject;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\player\Player;
use pocketmine\scheduler\Task;
use pocketmine\Server;
use pocketmine\world\World;

use function array_map;
use function array_values;
use function count;
use function spl_object_hash;

final class SeedingTask extends Task{
    /**
     * Counts of tasks launched by the each player
     * It is used to disable continuous use when a function is already in use.
     *
     * @var int[] string $playerHash => int $count
     */
    private static array $counts = [];

    public static function getCount(Player $player) : int{
        return self::$counts[spl_object_hash($player)] ?? 0;
    }

    private Player $owningPlayer;
    private int $targetY;
    private World $world;
    /** @var SeedObject[] */
    private array $seeds;

    /** @var Player[] */
    private array $hasSpawned = [];
    private bool $giveItemOnCancel;

    /** @param SeedObject[] $seeds */
    public function __construct(Player $owningPlayer, int $targetY, World $world, array $seeds){
        $this->owningPlayer = $owningPlayer;
        $this->targetY = $targetY;
        $this->world = $world;
        $this->seeds = $seeds;

        $this->giveItemOnCancel = $owningPlayer->hasFiniteResources();

        if(!isset(self::$counts[$hash = spl_object_hash($owningPlayer)])){
            self::$counts[$hash] = 0;
        }
        self::$counts[$hash]++;

        $chunkX = $owningPlayer->getPosition()->getFloorX() >> 4;
        $chunkZ = $owningPlayer->getPosition()->getFloorZ() >> 4;
        foreach($this->world->getChunkPlayers($chunkX, $chunkZ) as $player){
            if(!$player->hasReceivedChunk($chunkX, $chunkZ))
                continue;

            $this->hasSpawned[spl_object_hash($player)] = $player;
        }
        $this->broadcastEntitySpawn();
    }

    public function onRun() : void{
        if($this->owningPlayer->isClosed() || !$this->owningPlayer->isConnected()){
            $this->getHandler()->cancel();
            return;
        }

        $count = count($this->seeds);
        for($i = 0; $i < $count; ++$i){
            $seed = $this->seeds[$i];

            if($this->world->getBlock($seed)->isSolid()){
                if($seed->place($this->world, $this->owningPlayer)){
                    $this->giveItemOnCancel = false;
                }elseif($this->giveItemOnCancel){
                    $seed->collect($this->world, $this->owningPlayer);
                }
                $this->broadcastEntityDespawn($seed);
                unset($this->seeds[$i]);
                continue;
            }
            if($seed->motionY > -0.9){
                $seed->motionY -= 0.04;
            }
            $seed->y += $seed->motionY;
        }

        $this->seeds = array_values($this->seeds);
        if(empty($this->seeds)){
            $this->getHandler()->cancel();
        }else{
            $this->broadcastEntityMove();
        }
    }

    public function onCancel() : void{
        self::$counts[$hash = spl_object_hash($this->owningPlayer)]--;
        if(self::$counts[$hash] <= 0){
            unset(self::$counts[$hash]);
        }

        foreach($this->seeds as $seed){
            $this->broadcastEntityDespawn($seed);
            if($this->giveItemOnCancel){
                $seed->collect($this->world, $this->owningPlayer);
            }
        }
    }

    private function broadcastEntitySpawn() : void{
        if(!empty($this->seeds)){
            Server::getInstance()->broadcastPackets($this->hasSpawned, array_map(fn(SeedObject $seed) : ClientboundPacket => $seed->getSpawnPacket(), $this->seeds));
        }
    }

    private function broadcastEntityMove() : void{
        if(!empty($this->seeds)){
            Server::getInstance()->broadcastPackets($this->hasSpawned, array_map(fn(SeedObject $seed) : ClientboundPacket => $seed->getMovementPacket(), $this->seeds));
        }
    }

    private function broadcastEntityDespawn(SeedObject $seed) : void{
        Server::getInstance()->broadcastPackets($this->hasSpawned, [$seed->getDespawnPacket()]);
    }
}