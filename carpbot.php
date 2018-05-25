<?php 
/*
 * carpbot.php
 * 
 * Copyright 2018 Carp von Mda <07109B3AC9280E1458B8454552BC0A39AC3D23209E354CDD003BE962284B9254E65B38F4CE72>
 * 
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 * 
 * You should have received a copy of the GNU General Public License
 * along with this program; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston,
 * MA 02110-1301, USA.
 * 
 * 
 */

define("BOT_VERSION", "0.55"); 
define("RS_HOST", "http://127.0.0.1"); # retroshare web server's host
define("RS_PORT", "9090"); # retroshare web server's host
define("BOT_GXS_ID", "91c2af4320c9399e17cb01eb027982cf"); #show author in people tab > Identity ID
define("MAX_DC_ATTEMPTS", 40); 
define("HISTORY_MSG_COUNT", 120); 
define("STACK_SIZE",1000);
define("CYCLE_DELAY",5); #lower values is reducing the bot's reaction time, but increasing the CPU load
define("SAVE_CHAT_HISTORY",false); #saves history to a local file
define("SAVE_CHAT_HISTORY_FILENAME",'log/stack.json'); # if SAVE_CHAT_HISTORY is true; directory must be writable


function sigHandler($signo) 
{ 
	GLOBAL $chat;
	if (!SAVE_CHAT_HISTORY) { 
		exit();
	}
	$filename = SAVE_CHAT_HISTORY_FILENAME;
	if ($file = fopen($filename, 'w')) { 
		$stack = json_encode($chat->stack ?? [],JSON_UNESCAPED_UNICODE);
		fwrite($file, $stack);
		fclose($file);
		print_r("chat history writed to the local storage {$filename}\n");
	} else { 
		print_r("can't open file {$filename} for write\n");
	}	
	exit();
}

class Requests { 
	
	private static function check($result) 
	{ 
		if ($result) { 
			if (isset(json_decode($result)->returncode)) { 
				if (json_decode($result)->returncode === 'ok') { 
					return true;
				} 
//				print_r("check() ".json_decode($result)->debug_msg."\n"); // for debug
				return false;
			}
		}
		print_r("can't connect with retroshare's webserver\n");	
		return false;
	}
		
	public static function get($link) 
	{ 
		$obj = curl_init();
		curl_setopt($obj, CURLOPT_URL, RS_HOST.$link); 
		curl_setopt($obj, CURLOPT_PORT, RS_PORT);
		curl_setopt($obj, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($obj);
		curl_close($obj);
		if (Requests::check($response)) { 
			return json_decode($response)->data;
		}
		return false;
	}
	public static function post($link, Array $params = []) 
	{ 
		$obj = curl_init();
		curl_setopt($obj, CURLOPT_URL, RS_HOST.$link); 
		curl_setopt($obj, CURLOPT_PORT, RS_PORT);
		curl_setopt($obj, CURLOPT_POST, true);
		curl_setopt($obj, CURLOPT_HTTPHEADER,array("Content-type: application/json; charset=utf-8"));
		curl_setopt($obj, CURLOPT_POSTFIELDS, json_encode($params,JSON_UNESCAPED_UNICODE)); 
		curl_setopt($obj, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($obj);
		curl_close($obj);		
		if (Requests::check($response)) { 
			return json_decode($response)->data;
		}
		return false;
	}

	public function webget($link) 
	{ 
		$obj = curl_init();
		curl_setopt($obj, CURLOPT_URL, $link); 
		$headers = [
			'Accept-Language: en-US,en;q=0.5',
			'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:59.0) Gecko/20100101 Firefox/59.0'		
		];
		curl_setopt($obj, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($obj, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($obj);
		curl_close ($obj);
		if (!$response) { 
			print_r("can't connect with {$link}\n");
			return false;
		} 
		return $response;
	}
	
	public function webpost($link, Array $params = []) 
	{ 
		$obj = curl_init();
		curl_setopt($obj, CURLOPT_URL, $link); 
		$headers = [
			'Accept-Language: en-US,en;q=0.5',
			'User-Agent: Mozilla/5.0 (X11; Linux x86_64; rv:59.0) Gecko/20100101 Firefox/59.0'		
		];
		curl_setopt($obj, CURLOPT_HTTPHEADER, $headers);
		curl_setopt($obj, CURLOPT_POST, true);
		curl_setopt($obj, CURLOPT_POSTFIELDS, $params); 
		curl_setopt($obj, CURLOPT_RETURNTRANSFER, true);
		$response = curl_exec($obj);
		curl_close ($obj);
		if (!$response) { 
			print_r("can't connect with {$link}\n");
			return false;
		} 
		return $response;
	}
	
}

class Chat { 
	
	function __construct() 
	{ 
		$this->subscribed = [];
		$this->lastMessage = [];
		$this->lastThread = 0; 
		$this->stack = [];
		if (SAVE_CHAT_HISTORY) { 
			$filename = SAVE_CHAT_HISTORY_FILENAME;
			if (file_exists($filename)) {
				$string = file_get_contents($filename);
				$this->stack = (array) json_decode($string) ?? [];
				foreach ($this->stack as &$lid) { 
					$lid = (array) $lid;
				}
			} else { 
				$this->stack = [];
				print_r("can't open file {$filename} for read\n");
			}
		}
	}
	
	public function getLobbiesInfo() 
	{ 
		$link = '/api/v2/chat/lobbies';
		$result = Requests::get($link); 
		foreach ($result as $lobby) { 
			if ($lobby->subscribed === true) { 
				$this->subscribed[] = [
					'id' => $lobby->id,
					'chat_id' => $lobby->chat_id
				];
				if (!isset($this->stack[$lobby->chat_id])) { 
					$this->stack[$lobby->chat_id] = [];
				}
				$this->lastMessage[$lobby->chat_id] = "0";
			}
		}
	}
	
	public function stateUpdate() 
	{ 
		$link = '/api/v2/statetokenservice';
		$result = Requests::get($link); 
		if (is_array($result)) { 
			return true;
		}
		return false;
	} 
	
	public function clearChat($id) 
	{ 
		$link = '/api/v2/chat/clear_lobby';
		$result = Requests::post($link,['id'=>$id]); 
		return true;
	}
	
	public function insert(string $chat_id, $obj) 
	{ 
		if (!isset($this->stack[$chat_id])) { 
			print_r("chat_id {$chat_id} is undefined\n");
			return false;
		}
		if (!isset($obj->msg) || !isset($obj->author_name) || !isset($obj->send_time) || !isset($obj->id)) { 
			print_r("entry array isn't valid\n");
			return false;			
		}
		if (count($this->stack[$chat_id]) >= STACK_SIZE) { 
			array_shift($this->stack[$chat_id]);
		}
		$this->stack[$chat_id][] = $obj;
	}
} 

class DT { 
	
	public function start() 
	{
		$this->initDistantChat()->checkDistantChat()->closeDistantChat();
	}	
	
	private function initDistantChat() 
	{ 
		$link = '/api/v2/chat/initiate_distant_chat';
		$params = [ 
			'own_gxs_hex' => $this->own_gxs_hex,
			'remote_gxs_hex' => $this->remote_gxs_hex
		];
		$this->chatId = Requests::post($link,$params)->chat_id;
		return $this;
	}
	
	private function checkDistantChat() 
	{ 
		$link = '/api/v2/chat/distant_chat_status';
		$params = [ 
			'chat_id' => $this->chatId
		];
		$i = 0;
		$status = Requests::post($link,$params)->status;
		while ($status !== '2') { 
			$status = Requests::post($link,$params)->status;
			$i++;
			sleep(2);
			if ($i == MAX_DC_ATTEMPTS) { 
				$chatMessage = "<b>Unable to send history to {$this->author}</b>";
				$link = '/api/v2/chat/send_message';
				Requests::post($link,['chat_id'=>$this->lobbyId,'msg'=>$chatMessage]);	
				return $this;
			}
		}
		$chatMessage = "<b>History sent to {$this->author}</b>";
		$link = '/api/v2/chat/send_message';
		Requests::post($link,['chat_id'=>$this->chatId,'msg'=>$this->data]);
		Requests::post($link,['chat_id'=>$this->lobbyId,'msg'=>$chatMessage]);
		return $this;		
	}	
	 
	private function closeDistantChat() 
	{ 
		$link = '/api/v2/chat/close_distant_chat';
		$params = [ 
			'chat_id' => $this->chatId
		];
		Requests::post($link,$params);		
	} 

}

class Lobby { 
	
	function __construct() 
	{ 
		$this->hello = [ 
			"uk" => [["вітаю","привіт"],"Тов. ***, пред'яви паспорт!"],
			"ru" => [["привет"],"Тов. ***, паспорт предъяви!"],
			"en" => [["hello","hi"], "Mr. ***, show your passport!"],
			"fr" => [["bonjour","salut"], "M. ***, montrez votre passeport!"],
			"mk" => [["здраво"],"Другар. ***, покажи го пасошот!"]
		];
		$this->commands = [ 
			"/help" => "sendHelp",
			"/history" => "sendHistory",
			"/commits" => "sendCommits",
			"/btc" => "sendBTC"
		];
	}	
	
	public function getMessages($after = "0") 
	{ 
		$link = '/api/v2/chat/messages/'.$this->chat_id;
		$result = Requests::post($link,['begin_after'=>$after]); 
		if (is_array($result)) { 
			return $result;
		} else { 
			return Array();
		}
	}
	
	private function checkHello() 
	{ 
		$hello = false;
		foreach ($this->words as $word) {
			$clearedWord = mb_strtolower(preg_replace("/[!?,.\s]/", '', $word));
			foreach ($this->hello as $key => $value) { 
				if (in_array($clearedWord,$value[0])) { 
					$hello = $key;
					break;
				}
			}
		}
		if ($hello) { 
			$msg = str_replace("***",$this->author,$this->hello[$hello][1]);
			$this->sendMessage('<span color="red">'.$msg.'</span>');			
		}
		return $this;
	}

	private function checkCommands() 
	{ 
		$command = mb_strtolower($this->command);
		if (isset($this->commands[$command])) { 
			$callback = $this->commands[$command];
			$this->$callback();
		}
		return $this;
	}

	private function sendMessage($message) 
	{ 
		$link = '/api/v2/chat/send_message';
		Requests::post($link,['chat_id'=>$this->chat_id,'msg'=>$message]);
	}
	
	public function parseMessage($obj) 
	{ 
		$this->words = explode(" ",$obj->msg);
		$this->command = $this->words[0];
		$this->author = $obj->author_name;
		$this->author_id = $obj->author_id;
		$this->checkHello()->checkCommands();
	}

	private function convertHistory() 
	{ 
		GLOBAL $chat;
		$array = $chat->stack[$this->chat_id];
		$last = array_slice($array,-HISTORY_MSG_COUNT);
		$str = '';
		foreach ($last as $message) { 
			$str .= "<b>{$message->author_name}</b>(".date('H:i:s',$message->send_time)."): ".$message->msg." <br>\n";
		}
		return $str;
	}
	
	/* callback functions */
	private function sendHelp() 
	{ 
		GLOBAL $chat;
		$uptime = time() - $chat->startTime;
		$intTime = $uptime % 86400;
		$days = floor($uptime / 86400);
		$time = date("H:i:s",$intTime);
		$version = BOT_VERSION;
		$message = "<b>List of available commands: </b><br>
						<span>/BTC </span><br>
						<span>/COMMITS</span><br>
						<span>/HELP</span><br>
						<span>/HISTORY</span><br>
						<i>Uptime: {$days}d. {$time}.</i><br>
						<i>Version: {$version}</i><br>
						";
		$this->sendMessage($message);
	}

	private function sendHistory() 
	{ 
		GLOBAL $chat;
		$chat->lastThread = time();
		$pid = pcntl_fork(); 
		if ($pid == 0) { 
			$thread = new DT;
			$thread->own_gxs_hex = BOT_GXS_ID;
			$thread->remote_gxs_hex = $this->author_id; 
			$thread->lobbyId = $this->chat_id;
			$thread->author = $this->author;
			$thread->data = $this->convertHistory();
			$thread->start();
			exit();
		} 
	}

	private function sendCommits() 
	{ 
		$response = Requests::webget('https://api.github.com/repos/RetroShare/RetroShare/commits');
		if ($response) { 
			$array = (array) json_decode($response);
			$sliced = array_slice($array,0,5);
			$str = '<span style=\"color: red; font-weight: bold;\">LAST COMMITS:</span><br>';
			foreach ($sliced as $commit) { 
				$str .= "[<a href=\"".$commit->html_url."\">".$commit->commit->message."</a>]<br>";
			}	
			$this->sendMessage($str);
		} else { 
			$this->sendMessage("Unable to get commits list");
		}
	}
	
	private function sendBTC() 
	{ 		
		$response = Requests::webget('https://blockchain.info/ru/ticker');
		if ($response) { 
			$array = json_decode($response);
			$message = "[{$array->USD->last}{$array->USD->symbol}, {$array->EUR->last}{$array->EUR->symbol}, {$array->CNY->last}{$array->CNY->symbol}, {$array->RUB->last}{$array->RUB->symbol}]";
			$this->sendMessage($message);
		} else { 
			$this->sendMessage("Unable to get BTC info");
		}
	}
	
} 

# ----------------------- COUNTER -------------------------------------------------- 

$chat = new Chat();
$chat->getLobbiesInfo();
$chat->startTime = time();
pcntl_signal(SIGINT, "sigHandler");

while(true) { 
	pcntl_signal_dispatch();
	if (!$chat->stateUpdate()) { 
		sleep(15);
		continue;
	}
	$unixTime = time();
	if (($unixTime - $chat->lastThread) > (MAX_DC_ATTEMPTS * 2 + 5)) { 
		while(pcntl_waitpid(0, $status) != -1); 
	}
	foreach ($chat->subscribed as $subscribedLobby) {
		$lobby = new Lobby();
		$lobby->chat_id = $subscribedLobby['chat_id'];
		$lobby->id = $subscribedLobby['id']; 
		$last = $chat->lastMessage[$lobby->chat_id];
		$messages = $lobby->getMessages($last);
		if (count($messages) > 0) { 
			foreach ($messages as $message) { 
				$obj = new stdClass();
				$obj->id = $message->id;
				$obj->send_time = $message->send_time;
				$obj->author_name = $message->author_name;
				$obj->msg = $message->msg;
				$obj->author_id =$message->author_id;
				$chat->insert($lobby->chat_id,$obj);
				print_r($message->author_name.": ".$message->msg."\n");
				if ($message->incoming) { 
					$lobby->parseMessage($obj);
				}
				unset($obj);
				$chat->lastMessage[$lobby->chat_id] = $message->id;
			}
		}
		$chat->clearChat($lobby->id);
		unset($lobby);
	}
	sleep(CYCLE_DELAY);	
}
