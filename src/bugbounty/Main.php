<?php

namespace bugbounty;

use bugbounty\tasks\SendAsync;
use pocketmine\command\ConsoleCommandSender;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\item\Item;
use pocketmine\plugin\PluginBase;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\utils\Config;
use pocketmine\utils\TextFormat as C;
use SQLite3;

class Main extends PluginBase implements Listener
{
    /** @var SQLite3 */
    public $db;
    public $args;
    public $sender;
    /** @var Config */
    public $config;
    /** @var Config */
    public $lang;

    public function onEnable()
    {
        @mkdir($this->getDataFolder());
        $this->db = new SQLite3($this->getDataFolder() . "bugbounty.db");
        $this->db->exec("CREATE TABLE IF NOT EXISTS bugbounty(id INTEGER PRIMARY KEY autoincrement, player TEXT, bug TEXT, fix TEXT, reward TEXT, rewardgive TEXT);");

        $this->saveDefaultConfig();

        $this->saveResource("config.yml");
        $this->config = new Config($this->getDataFolder() . "config.yml", Config::YAML);

        $this->saveResource("lang.yml");
        $this->lang = new Config($this->getDataFolder() . "lang.yml", Config::YAML);



        if ($this->config->get("version") !== "1.1") {
            rename($this->getDataFolder() . "config.yml", $this->getDataFolder() . "oldconfig.yml");
            $this->getLogger()->warning($this->lang->get("outdated_config"));
            $this->saveResource("config.yml");
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /**
     * @param CommandSender $sender
     * @param Command $command
     * @param string $label
     * @param array $args
     * @return bool
     */
    public function onCommand(CommandSender $sender, Command $command, string $label, array $args): bool
    {
        switch ($command->getName()) {
            case 'bug':
                if (empty($args)) {
                    $sender->sendMessage(C::RED . $this->lang->get("no_args"));
                    break;
                } else {
                    $this->bugcommand($sender, $args);
                    break;
                }
                break;

            case 'bugreward':
                if (!$sender->hasPermission("bug.reward")) return $this->lang->get("no_permission");

                if (!isset($args[0])) {
                    $sender->sendMessage($this->lang->get("no_id"));
                    return false;
                }
                if (!isset($args[1])) {
                    $sender->sendMessage($this->lang->get("no_number"));
                    return false;
                }
                $verify = ["0", "1", "2", "3", "4", "5"];
                if (!in_array($args[1], $verify)) {
                    $sender->sendMessage($this->lang->get("bad_number"));
                    return false;
                }
                $this->rewardcommand($sender, $args);
                break;

            case 'bugfix':
                if (!$sender->hasPermission("bug.fix")) return $this->lang->get("no_permission");
                if (!isset($args[0])) {
                    $sender->sendMessage($this->lang->get("no_id"));
                    return false;
                }
                $this->fixcommand($sender, $args);
                break;
            case 'buglist':
            if (!$sender->hasPermission("bug.list")) return $this->lang->get("no_permission");
                $this->buglistcommand($sender);
            break;
        }
        return true;
    }

    /**
     * @param $sender CommandSender
     * @param $args array
     * @return bool
     */
    public function bugcommand($sender, $args)
    {
        $args = implode($args, " ");
        $args = str_replace("@everyone", "EVERYONE PROTECT", $args);
        $args = str_replace("@here", "HERE PROTECT", $args);
        $args = str_replace("'", "%27", $args);

        if ($sender instanceof ConsoleCommandSender) {
            $msg = str_replace("{bug}", $args, $this->lang->get("discord_console_bug"));
            $this->sendMessage($msg);
            return true;
        }

        $buginfo = $this->db->prepare("INSERT OR REPLACE INTO bugbounty(id, player, bug, fix, reward, rewardgive) VALUES (:id, :player, :bug, :fix, :reward, :rewardgive);");
        $buginfo->bindValue(":player", $sender->getName());
        $buginfo->bindValue(":bug", $args);
        $buginfo->bindValue(":fix", "false");
        $buginfo->bindValue(":reward", "false");
        $buginfo->bindValue(":rewardgive", "false");
        $buginfo->execute();

        $sendername = $sender->getName();
        $info = $this->db->query("SELECT id FROM bugbounty WHERE bug = '$args' and player = '$sendername'");
        $id = $info->fetchArray(SQLITE3_ASSOC);

        $sender->sendMessage(str_replace("{id}", $id["id"], $this->lang->get("player_confirmation_bug")));
        $args = str_replace("%27", "'", $args);
        $oldmsg = str_replace("{player}", $sendername, $this->lang->get("discord_confirmation_bug"));
        $oldmsg = str_replace("{id}", $id["id"], $oldmsg);
        $msg = str_replace("{bug}", $args, $oldmsg);
        $this->sendMessage($msg);
        return true;
    }

    /**
     * @param CommandSender $sender
     * @param array $args
     * @return bool
     */
    public function rewardcommand(CommandSender $sender, array $args)
    {
        $info = $this->db->query("SELECT * FROM bugbounty WHERE id = '$args[0]';");
        $array = $info->fetchArray(SQLITE3_ASSOC);
        if (!isset($array["id"])) {
            $sender->sendMessage($this->lang->get("bad_id"));
            return false;
        }
        if ($array["rewardgive"] === "true") {
            $sender->sendMessage(str_replace("{player}", $array["player"], $this->lang->get("already_reward")));
            return false;
        } else {
            $this->db->query("UPDATE bugbounty SET reward = '$args[1]' WHERE id = {$args[0]};");

            $oldmsg = str_replace("{player}", $array["player"], $this->lang->get("sucess_reward"));
            $msg = str_replace("{id}", $array["id"], $oldmsg);
            $sender->sendMessage($msg.$args[1]);
            $oldmsg = str_replace("{reward}", $args[1], $this->lang->get("discord_sucess_reward"));
            $oldmsg = str_replace("{player}", $array["player"], $oldmsg);
            $msg = str_replace("{id}", $array["id"], $oldmsg);
            $this->sendMessage($msg);
            return true;
        }
    }

    /**
     * @param CommandSender $sender
     * @param array $args
     * @return bool
     */
    public function fixcommand(CommandSender $sender, array $args){
        $info = $this->db->query("SELECT * FROM bugbounty WHERE id = '$args[0]';");
        $array = $info->fetchArray(SQLITE3_ASSOC);
        if (!isset($array["id"])) {
            $sender->sendMessage($this->lang->get("bad_id"));
            return false;
        }
        if($array["fix"] === "false"){
            $sender->sendMessage(str_replace("{id}", "$args[0]", $this->lang->get("true_fix")));
            $this->db->query("UPDATE bugbounty SET fix = 'true' WHERE id = '$args[0]';");
        } else {
            $sender->sendMessage(str_replace("{id}", "$args[0]", $this->lang->get("false_fix")));
            $this->db->query("UPDATE bugbounty SET fix = 'false' WHERE id = '$args[0]';");
        }
        return true;
    }

    /**
     * @param CommandSender $sender
     */
    public function buglistcommand(Commandsender $sender){
        $buglist = $this->db->query("SELECT * FROM bugbounty LIMIT {$this->lang->get("buglist_limit")};");
        $sender->sendMessage($this->lang->get("buglist_format"));
        while ($result = $buglist->fetchArray(SQLITE3_ASSOC)) {
            if($this->config->get("delete_bug_logs") === "true"){
                if($result["fix"] === "true" && $result["rewardgive"] === "true"){
                    $this->db->query("DELETE FROM bugbounty WHERE id = '{$result["id"]}';");
                }
            }

            $sender->sendMessage(str_replace(array("{id}", "{player}", "{reward}", "{give}", "{fix}"), array($result["id"], $result["player"], $result["reward"], $result["rewardgive"], $result["fix"]), $this->lang->get("buglist")));
        }
    }

    /**
     * @param PlayerJoinEvent $event
     */
    public function onJoin(PlayerJoinEvent $event) : void 
    {
        $player = $event->getPlayer();
        $playername = $player->getName();
        $infos = $this->db->query("SELECT * FROM bugbounty WHERE player = '$playername' AND rewardgive = 'false';");
        $array = $infos->fetchArray(SQLITE3_ASSOC);
        if (empty($array)) return;

        if($this->config->get("delete_bug_logs") === "true" && $array["fix"] === "true"){
            $this->db->query("DELETE FROM bugbounty WHERE id = '{$array["id"]}';");
        } else {
            $this->db->query("UPDATE bugbounty SET rewardgive = 'true' WHERE id = {$array["id"]};");
        }

        if ($array["reward"] === "false") return;
        if ($array["rewardgive"] === "true") return;

        if ($array["reward"] === "0") str_replace("{id}", $array["id"], $this->lang->get("no_reward"));

        $list = $this->config->get("reward");

        $bases = [$list["{$array["reward"]}"]];
        $item = Item::get(95, 0, count($bases[0]) * 64);
        $i = $player->getInventory();

        if ($i->canAddItem($item)) {
            $oldmsg = str_replace("{lvl}", $array["reward"], $this->lang->get("give_confirmation"));
            $player->sendMessage(str_replace("{id}", $array["id"], $oldmsg));
        } else {
            $player->sendMessage($this->lang->get("full_inventory"));
            return;
        }

        foreach ($bases[0] as $base) {
            $item = $this->getItem($base);
            $i->addItem($item);
        }
    }

    /**
     * @param array $item
     * @return Item
     */
    public function getItem(array $item): Item
    {
        //ORIGINAL BY Virvolta FROM CustomCraftPlus VirVolta#8479 or https://discord.gg/kJ9dRdz
        $item0 = $item[0];

        if (is_string($item0)) {
            $data = Item::fromString($item0);
            $result = Item::get($data->getId(), $data->getDamage(), 1);
        } else {
            $result = Item::get($item0, $item[1], 1);
        }

        if (isset($item[1])) {
            $result->setCount($item[1]);
        }

        return $result;
    }

    /**
     * @param string $msg
     * @return bool
     */
    public function sendMessage(string $msg)
    {
        $name = $this->config->get("webhook_name");
        $webhook = $this->config->get("webhook_url");
        $curlopts = [
            "content" => $msg,
            "username" => $name
        ];

        $this->getServer()->getAsyncPool()->submitTask(new SendAsync($webhook, serialize($curlopts)));
        return true;
    }

}
