<?php
namespace TZusiMC;

use pocketmine\plugin\PluginBase;
use pocketmine\player\Player;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockPlaceEvent;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\block\SignChangeEvent;
use pocketmine\event\entity\EntitySpawnEvent;
use pocketmine\entity\object\ItemEntity;
use pocketmine\block\VanillaBlocks;
use pocketmine\item\Item;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\World;
use pocketmine\world\generator\Flat;
use pocketmine\world\WorldCreationOptions;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;

class Main extends PluginBase implements Listener {
    private array $data = [];
    private int $plotSize = 25, $roadSize = 5, $worldSize = 1500, $spawnRadius = 100;
    private array $ranks = [
        "Spieler"=>["plots"=>1,"homes"=>1,"fly"=>false,"color"=>"§7"],
        "VIP"=>["plots"=>2,"homes"=>2,"fly"=>true,"color"=>"§a"],
        "Ultra"=>["plots"=>3,"homes"=>3,"fly"=>true,"color"=>"§b"],
        "Rich"=>["plots"=>4,"homes"=>4,"fly"=>true,"color"=>"§6"],
        "WorldGuardian"=>["plots"=>6,"homes"=>6,"fly"=>true,"color"=>"§c"]
    ];

    public function onEnable(): void {
        $this->saveDefaultConfig();
        $this->data = $this->getConfig()->getAll();
        $this->getServer()->getPluginManager()->registerEvents($this,$this);

        $wm = $this->getServer()->getWorldManager();
        if(!$wm->isWorldGenerated("plotworld")){
            $wm->generateWorld("plotworld", WorldCreationOptions::create()->setGeneratorClass(Flat::class));
        }
        $wm->loadWorld("plotworld");

        // WorldBorder
        $world = $wm->getWorldByName("plotworld");
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn()=>$this->checkWorldBorder($world)),20);

        // ClearLag Items
        $this->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn()=>$this->clearItems()),20*60*10);
    }

    private function checkWorldBorder(World $world){
        foreach($world->getPlayers() as $p){
            $x = $p->getPosition()->getFloorX();
            $z = $p->getPosition()->getFloorZ();
            if(abs($x)>$this->worldSize || abs($z)>$this->worldSize){
                $p->teleport($world->getSpawnLocation());
                $p->sendMessage("§cWorldBorder erreicht!");
            }
        }
    }

    private function clearItems(){
        foreach($this->getServer()->getWorldManager()->getWorlds() as $w){
            foreach($w->getEntities() as $e){
                if($e instanceof ItemEntity) $e->flagForDespawn();
            }
        }
    }

    // ==================== Plotwelt Lazy-Generierung ====================
    private function isRoad(int $x,int $z): bool {
        $full = $this->plotSize+$this->roadSize;
        return !($x % $full < $this->plotSize && $z % $full < $this->plotSize);
    }

    public function lazyGeneratePlot(Player $player){
        $world = $player->getWorld();
        $pos = $player->getPosition()->floor();
        for($x=$pos->getX()-$this->plotSize;$x<=$pos->getX()+$this->plotSize;$x++){
            for($z=$pos->getZ()-$this->plotSize;$z<=$pos->getZ()+$this->plotSize;$z++){
                for($y=0;$y<=63;$y++){
                    if($y===0) $world->setBlockAt($x,$y,$z,VanillaBlocks::BEDROCK());
                    elseif($y<63) $world->setBlockAt($x,$y,$z,VanillaBlocks::DIRT());
                    elseif($y===63) $world->setBlockAt($x,$y,$z,$this->isRoad($x,$z)?VanillaBlocks::STONE():VanillaBlocks::GRASS());
                }
            }
        }
    }

    // ==================== Commands ====================
    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $args): bool{
        if(!$sender instanceof Player) return true;
        $name = $sender->getName();
        $rank = $this->data["rank"][$name] ?? "Spieler";

        switch($cmd->getName()){
            case "plot":
                $pos = $sender->getPosition();
                $this->lazyGeneratePlot($sender); // Lazy generieren
                $key = floor($pos->getX()/($this->plotSize+$this->roadSize)).":".floor($pos->getZ()/($this->plotSize+$this->roadSize));
                if($args[0]??""==="claim"){
                    if($this->isRoad($pos->getX(),$pos->getZ())){
                        $sender->sendMessage("§cKeine Straßen claimen!");
                        return true;
                    }
                    if(isset($this->data["plots"][$key])){
                        $sender->sendMessage("§cPlot schon vergeben!");
                        return true;
                    }
                    $owned=0;
                    foreach(($this->data["plots"]??[]) as $p) if($p["owner"]===$name) $owned++;
                    if($owned>=$this->ranks[$rank]["plots"]){
                        $sender->sendMessage("§cPlotlimit erreicht!");
                        return true;
                    }
                    $this->data["plots"][$key]=["owner"=>$name,"trusted"=>[]];
                    $this->save();
                    $sender->sendMessage("§aPlot geclaimt!");
                }
            break;

            case "fly":
                if(!$this->ranks[$rank]["fly"]){$sender->sendMessage("§cKein Fly-Rang!");return true;}
                $sender->setAllowFlight(!$sender->getAllowFlight());
            break;

            case "daily":
                if(isset($this->data["daily"][$name]) && time()-$this->data["daily"][$name]<86400){$sender->sendMessage("§cDaily schon abgeholt!");return true;}
                $this->data["money"][$name]=($this->data["money"][$name]??1000)+100;
                $this->data["daily"][$name]=time();
                $this->save();
                $sender->sendMessage("§a+100€ erhalten!");
            break;

            case "pay":
                $target = $this->getServer()->getPlayerExact($args[0]??"");
                $amount = (int)($args[1]??0);
                if(!$target||$amount<=0||($this->data["money"][$name]??1000)<$amount) return true;
                $this->data["money"][$name]-=$amount;
                $this->data["money"][$target->getName()]=($this->data["money"][$target->getName()]??1000)+$amount;
                $this->save();
            break;

            case "home":
                $h=$this->data["homes"][$name]??["x"=>$sender->getPosition()->getX(),"y"=>$sender->getPosition()->getY(),"z"=>$sender->getPosition()->getZ()];
                $sender->teleport($sender->getWorld()->getSafeSpawn());
            break;

            case "farmwelt":
                $wm=$this->getServer()->getWorldManager();
                if($wm->isWorldLoaded("farmwelt"))$sender->teleport($wm->getWorldByName("farmwelt")->getSafeSpawn());
            break;
        }
        return true;
    }

    // ==================== Events ====================
    public function onBreak(BlockBreakEvent $e): void{
        $p=$e->getPlayer();
        $x=$p->getPosition()->getFloorX(); $z=$p->getPosition()->getFloorZ();
        if(abs($x)<$this->spawnRadius && abs($z)<$this->spawnRadius){$e->cancel();return;}
        $key = floor($x/($this->plotSize+$this->roadSize)).":".floor($z/($this->plotSize+$this->roadSize));
        if(!isset($this->data["plots"][$key])){$e->cancel();return;}
        $plot=$this->data["plots"][$key];
        if($plot["owner"]!==$p->getName() && !in_array($p->getName(),$plot["trusted"])){$e->cancel();}
    }
    public function onPlace(BlockPlaceEvent $e): void{$this->onBreak($e);}
    public function onChat(PlayerChatEvent $e){$r=$this->data["rank"][$e->getPlayer()->getName()]??"Spieler";$e->setFormat($this->ranks[$r]["color"]."[$r] §f".$e->getPlayer()->getName().": ".$e->getMessage());}
    public function onSpawn(EntitySpawnEvent $e){if(!$e->getEntity() instanceof ItemEntity)$e->cancel();}

    // ==================== ChestShop ====================
    public function
