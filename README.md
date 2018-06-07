# Retroshare chat bot

Carp Bot - chat bot for retroshare, written in php. 

## Getting Started

1) Rename "config.php.default" to "config.php";

2) Start retroshare with an active webserver. To start nogui version with a web api: 

```
$ retroshare-nogui --webinterface 9090 -i 127.0.0.1 --http-allow-all
```
Highly recomended to start retroshare node and carpbot in a screens: 

```
$ screen -S retroshare
$ retroshare-nogui --webinterface 9090 -i 127.0.0.1 --http-allow-all
``` 
For the more info read "man screen". 

3) Change these constants in the "config.php":
* RS_HOST
* RS_PORT
* BOT_GXS_ID

4) If you want to save history after SIGINT, set "SAVE_CHAT_HISTORY" to (bool) true, and change the log path in "SAVE_CHAT_HISTORY_FILENAME";

5) Start chat bot:
```
$ screen -S carpbot
$ cd ~/[bot_dir]
$ php carpbot.php
``` 
