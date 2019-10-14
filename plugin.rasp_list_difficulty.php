<?php

global $aseco, $jb_buffer, $rld_fixedDifficulty, $rld_minRecords, $rld_widgedEnabled, $rld_widgetPos;

Aseco::registerEvent('onSync', 'rld_init');
Aseco::registerEvent('onPlayerManialinkPageAnswer',	'rld_onPlayerManialinkPageAnswer');
Aseco::registerEvent('onNewChallenge', 'rld_onNewChallenge');
Aseco::registerEvent('onEndRace', 'rld_onEndRace');;
Aseco::registerEvent('onPlayerConnect', 'rld_onPlayerConnect');

Aseco::addChatCommand("difficulty","Creates the difficulty environment");

// Config
$rld_fixedDifficulty = false;
$rld_minRecords = 4;
$rld_widgedEnabled = true;
$rld_widgetPos = array(20,25); //Position of the widget (xPos,yPos)

function rld_init($aseco) {
global $rld_fixedDifficulty;	
	$aseco->console('**********[chat.rasp_list_difficulty.php'. $aseco->server->getGame() .']**********');

	//Check if needed plugins are installed
	$aseco->console('>> Checking for required plugins...');
	
	$required = array(
			'plugin.localdatabase.php',
			//'plugin.rasp_funcs.php',
			'plugin.rasp_jukebox.php'
		);
		
	foreach ($required as $plugin) {
		foreach ($aseco->plugins as &$installed_plugin) {
			if ($plugin == $installed_plugin) {
				// Found, skip to next plugin
				continue 2;
			}
		}
		trigger_error('[chat.rasp_list_difficulty.php] Unmet requirements! With your current configuration you need to activate "'. $plugin .'" in your "plugins.xml" to run this Plugin!', E_USER_ERROR);
	}
	unset($installed_plugin, $plugin, $required);
	
	//Check if needed columns exist already
	$aseco->console('>> Checking Database for required extensions...');
	
	if ($rld_fixedDifficulty == false) {
		mysql_query('CREATE TABLE IF NOT EXISTS `rs_difficulty` (
		  `Id` int(11) NOT NULL auto_increment,
		  `ChallengeId` mediumint(9) NOT NULL default 0,
		  `PlayerId` mediumint(9) NOT NULL default 0,
		  `Score` tinyint(1) NOT NULL default 0,
		  PRIMARY KEY (`Id`),
		  UNIQUE KEY `PlayerId` (`PlayerId`,`ChallengeId`),
		  KEY `ChallengeId` (`ChallengeId`)
		) ENGINE=MyISAM;');
		$aseco->console('   + Table rs_difficulty got created if didn\'t exist yet.');
	} else {
		$fields = array();
		$result = mysql_query('SHOW COLUMNS FROM `challenges`;');
		
		if ($result) {
			while ($row = mysql_fetch_row($result)) {
				$fields[] = $row[0];
			}
			mysql_free_result($result);
		}
		
		// Add `difficulty` column to `challenges` table if not yet done
		if ( !in_array('difficulty', $fields) ) {
				$aseco->console('   + Adding column `difficulty` at table `challenges`.');
				mysql_query('ALTER TABLE challenges ADD difficulty tinyint(1) DEFAULT 0 COMMENT "Added by plugin.rasp_list_difficulty.php"');
		} else { $aseco->console('   + Found column `difficulty` at table `challenges`.'); }
	}
}

function rld_getChallengesByDifficulty($player, $order) {
global $aseco, $rld_fixedDifficulty;	
	
	if ($order == 'easiest') {
		$order = 'ASC';
	} elseif (is_numeric($order)) {
		if ($order < 0 || $order > 9) {
			return;
		}
	} else {
		$order = 'DESC';
	}
	if ($rld_fixedDifficulty == true && !is_numeric($order)) {
		$sql = 'SELECT uid, difficulty FROM challenges ORDER BY difficulty ' . $order;
	} elseif ($rld_fixedDifficulty == true && is_numeric($order)) {
		$sql = 'SELECT uid, difficulty FROM challenges WHERE difficulty = ' . quotedString($order);
	} elseif ($rld_fixedDifficulty == false && is_numeric($order)) {
		$sql = 'SELECT ChallengeId, ROUND(AVG(Score),2) AS difficulty FROM rs_difficulty GROUP BY ChallengeId ORDER BY difficulty DESC';
	} else {
		$sql = 'SELECT ChallengeId, ROUND(AVG(Score),2) AS difficulty FROM rs_difficulty GROUP BY ChallengeId ORDER BY difficulty ' . $order;
	}
	$result = mysql_query($sql);
	if (mysql_num_rows($result) == 0) {
		mysql_free_result($result);
		return;
	}
	// get new/cached list of tracks
	$newlist = getChallengesCache($aseco);
	
	if ($aseco->server->getGame() == 'TMN') {
		return;
	} elseif ($aseco->server->getGame() == 'TMF') {
		$envids = array('Stadium' => 11, 'Alpine' => 12, 'Bay' => 13, 'Coast' => 14, 'Island' => 15, 'Rally' => 16, 'Speed' => 17);
		$head = 'Tracks by Difficulty (' . $order . '):';
		$msg = array();
		if ($aseco->server->packmask != 'Stadium')
			$msg[] = array('Id', 'Difficulty', 'Name', 'Author', 'Env');
		else
			$msg[] = array('Id', 'Difficulty', 'Name', 'Author');
		$tid = 1;
		$lines = 0;
		$player->msgs = array();
		// reserve extra width for $w tags
		$extra = ($aseco->settings['lists_colortracks'] ? 0.2 : 0);
		if ($aseco->server->packmask != 'Stadium')
			$player->msgs[0] = array(1, $head, array(1.44+$extra, 0.12, 0.15, 0.6+$extra, 0.4, 0.17), array('Icons128x128_1', 'NewTrack', 0.02));
		else
			$player->msgs[0] = array(1, $head, array(1.27+$extra, 0.12, 0.15, 0.6+$extra, 0.4), array('Icons128x128_1', 'NewTrack', 0.02));

		while ($dbrow = mysql_fetch_array($result)) {
			// does the uid exist in the current server track list?
			if ($rld_fixedDifficulty == false && is_numeric($order)) {
				if (Round($dbrow[1]) != $order) {
					continue;
				}
			}
			if ($rld_fixedDifficulty) {
				
			} else {
				$dbrow[0] = rld_getChallengeUid($dbrow[0]);
			}
			if (array_key_exists($dbrow[0], $newlist)) {
				$row = $newlist[$dbrow[0]];
				// store track in player object for jukeboxing
				$trkarr = array();
				$trkarr['name'] = $row['Name'];
				$trkarr['author'] = $row['Author'];
				$trkarr['environment'] = $row['Environnement'];
				$trkarr['filename'] = $row['FileName'];
				$trkarr['uid'] = $row['UId'];
				$player->tracklist[] = $trkarr;

				// format track name
				$trackname = $row['Name'];
				if (!$aseco->settings['lists_colortracks'])
					$trackname = stripColors($trackname);
				// grey out if in history
				if (in_array($row['UId'], $jb_buffer))
					$trackname = '{#grey}' . stripColors($trackname);
				else {
					$trackname = '{#black}' . $trackname;
				}
				// format author name
				$trackauthor = $row['Author'];
				// format karma
				$trackdifficulty = str_pad(str_replace('.', ',', $dbrow[1]), 4, '  ', STR_PAD_LEFT);
				// format env name
				$trackenv = $row['Environnement'];
				// add clickable button
				if ($aseco->settings['clickable_lists'])
					$trackenv = array($trackenv, $envids[$row['Environnement']]);

				// add clickable buttons
				if ($aseco->settings['clickable_lists'] && $tid <= 1900) {
					$trackname = array($trackname, $tid+100);  // action ids
					$trackauthor = array($trackauthor, -100-$tid);
					//$trackdifficulty = array($trackdifficulty, -6000-$tid);
				}

				if ($aseco->server->packmask != 'Stadium')
					$msg[] = array(str_pad($tid, 3, '0', STR_PAD_LEFT) . '.',
					               $trackdifficulty, $trackname, $trackauthor, $trackenv);
				else
					$msg[] = array(str_pad($tid, 3, '0', STR_PAD_LEFT) . '.',
					               $trackdifficulty, $trackname, $trackauthor);
				$tid++;
				if (++$lines > 14) {
					$player->msgs[] = $msg;
					$lines = 0;
					$msg = array();
					if ($aseco->server->packmask != 'Stadium')
						$msg[] = array('Id', 'Difficulty', 'Name', 'Author', 'Env');
					else
						$msg[] = array('Id', 'Difficulty', 'Name', 'Author');
				}
			}
		}
		// add if last batch exists
		if (count($msg) > 1)
			$player->msgs[] = $msg;
	}

	mysql_free_result($result);
}

function rld_getChallengeUid($id) {
	$query = 'SELECT Uid FROM challenges
		          WHERE Id=' . quotedString($id);
		$res = mysql_query($query);
		if (mysql_num_rows($res) > 0) {
			$row = mysql_fetch_row($res);
			$rtn = $row[0];
		} else {
			$rtn = 0;
		}
		mysql_free_result($res);
		return $rtn;
}

function chat_difficulty($aseco, $command) {
global $rld_fixedDifficulty;	
	
	$player = $command['author'];
	$login = $player->login;
	
	// check for relay server
	if ($aseco->server->isrelay) {
		$message = formatText($aseco->getChatMessage('NOTONRELAY'));
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		return;
	}

	// split params into array
	$arglist = preg_replace('/ +/', ' ', $command['params']);
	$command['params'] = explode(' ', $arglist);
	$cmdcount = count($command['params']);
	
	if ($cmdcount == 1 && $command['params'][0] == 'help') {
		$header = '{#black}/list$g will show tracks in rotation on the server:';
			$help = array();
			$help[] = array('...', '{#black}hardest',
			                'Shows most difficult maps');
			$help[] = array('...', '{#black}easiest',
			                'Shows least difficult maps');
			$help[] = array('...', '{#black}difficulty #',
			                'Shows maps with difficulty #');
			$help[] = array();
			$help[] = array('Pick an Id number from the list, and use {#black}/jukebox #');
		// display ManiaLink message
		display_manialink($login, $header, array('Icons64x64_1', 'TrackInfo', -0.01), $help, array(1.1, 0.05, 0.3, 0.75), 'OK');
	} elseif ($cmdcount == 1 && $command['params'][0] == '') {
		rld_showDifficultyWindow($player);
	} elseif ($cmdcount == 2 && $command['params'][0] == 'fixed' && ($command['params'][1] == 'on' || $command['params'][1] == 'off')) { 
		if($aseco->isAnyAdmin($player)) {
			if ($rld_fixedDifficulty == true && $command['params'][1] == 'on') {
				$message = '{#server}> {#error}Fixed difficulty is already enabled.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			} elseif ($rld_fixedDifficulty == false && $command['params'][1] == 'on') {
				$message = '{#server}> {#error}Fixed difficulty succesfully enabled.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
				$rld_fixedDifficulty = true;
				rld_init($aseco);
			} elseif ($rld_fixedDifficulty == true && $command['params'][1] == 'off') {
				$message = '{#server}> {#error}Fixed difficulty succesfully disabled.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
				$rld_fixedDifficulty = false;
				rld_init($aseco);
			} else {
				$message = '{#server}> {#error}Fixed difficulty is already disabled.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			}
		} else {
			$message = '{#server}> {#error}You don\'t have the required permission to do that.';
			$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
		}
	} elseif ($cmdcount == 1 && $command['params'][0] != '') { //edit difficulty of current map
		$difficulty = $command['params'][0];	
		$uid = $aseco->server->challenge->uid;
		
		if($rld_fixedDifficulty == true) {
			if($aseco->isAnyAdmin($player)) {
				//set new difficulty
				if(!is_numeric($difficulty) || $difficulty > 9 || $difficulty < 0) {
					$message = '{#server}> {#error}Choose a difficulty between 0 and 9!';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				rld_setDifficulty($uid,$difficulty);
				$message = '{#server}> You succesfully set the difficulty to: '. $difficulty .'!';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			} else {
				$message = '{#server}> {#error}Fixed difficulty is enabled. Voting is currently disabled.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			}
		} else {
			if(rld_getAmountLocals($player) >= $rld_minRecords) {
				if(!is_numeric($difficulty) || $difficulty > 9 || $difficulty < 0) {
					$message = '{#server}> {#error}Choose a difficulty between 0 and 9!';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				$id = $aseco->getChallengeId($uid);
				
				if(rld_checkVoting($id,$player)) {
					rld_editVoting($id,$difficulty,$player);
				} else {			
					rld_addVoting($id,$difficulty,$player);
				}
				$message = '{#server}> You succesfully voted the difficulty of this map as: '. $difficulty .'.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			} else {
				$message = '{#server}> {#error}In order to vote, you have to atleast finish '. $rld_minRecords .' maps in total.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $player->login);
			}
		}
	} elseif ($cmdcount == 2) { //used maybe /list before and uses id for putting difficulty
		$difficulty = $command['params'][1];
		
		if($rld_fixedDifficulty == true) {
			if($aseco->isAnyAdmin($player)) {
				if (empty($player->tracklist)) {
					$message = '{#server}> {#error}Use /list first.';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return; //Bail out if player hasnt use /list before to create his tracklist.
				}
				
				if (!is_numeric($command['params'][0])) { //Check if first parameter is nummeric (Its the /list id)
					$message = '{#server}> {#error}This is not a valid ID. ID\'s have to be numeric. Use /list to see available ID\'s.';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				if(!is_numeric($difficulty) || $difficulty > 9 || $difficulty < 0) {
					$message = '{#server}> {#error}Choose a difficulty between 0 and 9!';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				$jid = ltrim($command['params'][0], '0');
				$jid--;
				
				if (!array_key_exists($jid, $player->tracklist)) {
					$message = '{#server}> {#error}This is not a valid ID. Use /list to see available ID\'s.';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				$uid = $player->tracklist[$jid]['uid'];
				
				rld_setDifficulty($uid,$difficulty);
				$message = '{#server}> You succesfully set the difficulty to: '. $difficulty .'!';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			} else {
				$message = '{#server}> {#error}Fixed difficulty is enabled. Voting is currently disabled.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			}
		} else {
			if(rld_getAmountLocals($player) >= $rld_minRecords) {
				if (empty($player->tracklist)) {
					$message = '{#server}> {#error}Use /list first.';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return; //Bail out if player hasnt use /list before to create his tracklist.
				}
				
				if (!is_numeric($command['params'][0])) { //Check if first parameter is nummeric (Its the /list id)
					$message = '{#server}> {#error}This is not a valid ID. ID\'s have to be numeric. Use /list to see available ID\'s.';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				if(!is_numeric($difficulty) || $difficulty > 9 || $difficulty < 0) {
					$message = '{#server}> {#error}Choose a difficulty between 0 and 9!';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				$jid = ltrim($command['params'][0], '0');
				$jid--;
				
				if (!array_key_exists($jid, $player->tracklist)) {
					$message = '{#server}> {#error}This is not a valid ID. Use /list to see available ID\'s.';
					$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
					return;
				}
				
				$uid = $player->tracklist[$jid]['uid'];
				$id = $aseco->getChallengeId($uid);
				
				if(rld_checkVoting($id,$player)) {
					rld_editVoting($id,$difficulty,$player);
				} else {			
					rld_addVoting($id,$difficulty,$player);
				}
				$message = '{#server}> You succesfully voted the difficulty of '. rld_getChallengeName($uid) .'{#server} as: '. $difficulty .'.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
			} else {
				$message = '{#server}> {#error}In order to vote, you have to atleast finish '. $rld_minRecords .' maps in total.';
				$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $player->login);
			}
		}		
	} else {
		$message = '{#server}> {#error}The formatting has been wrong. Use /difficulty help for further information.';
		$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $login);
	}
}

function rld_getChallengeName($uid) {
	$query = 'SELECT Name FROM challenges
	          WHERE Uid=' . quotedString($uid);
	$res = mysql_query($query);
	if (mysql_num_rows($res) > 0) {
		$row = mysql_fetch_row($res);
		$rtn = $row[0];
	} else {
		$rtn = 0;
	}
	mysql_free_result($res);
	return $rtn;
} 

function rld_setDifficulty($uid,$difficulty) {
	mysql_query('UPDATE challenges SET difficulty = '. quotedString($difficulty) .' WHERE uid = '. quotedString($uid) .' LIMIT 1;');
}

function rld_getAmountLocals($player) {
	$query = 'SELECT COUNT(PlayerId) FROM records
	          WHERE PlayerId=' . quotedString($player->id);
	$res = mysql_query($query);
	if (mysql_num_rows($res) > 0) {
		$row = mysql_fetch_row($res);
		$rtn = $row[0];
	} else {
		$rtn = 0;
	}
	mysql_free_result($res);
	return $rtn;	
}

function rld_editVoting($id,$difficulty,$player) {
	mysql_query('UPDATE rs_difficulty SET Score = '. quotedString($difficulty) .' WHERE ChallengeId = '. quotedString($id) .' AND PlayerId = '. quotedString($player->id) .' LIMIT 1;');
}

function rld_addVoting($id,$difficulty,$player) {
	mysql_query('INSERT INTO `rs_difficulty`(`Id`, `ChallengeId`, `PlayerId`, `Score`) VALUES (DEFAULT,'. $id .','. $player->id .','. $difficulty .')');
}

function rld_getAmountVotes($id) {
	$res = mysql_query('SELECT ChallengeId FROM rs_difficulty WHERE ChallengeId = '. quotedString($id) .';');
	$amount = 0;
	while ($dbrow = mysql_fetch_array($res)) {
		$amount++;
	}
	mysql_free_result($res);
	return $amount;
}

function rld_getVoting($id,$player) {
	$res = mysql_query('SELECT Score FROM rs_difficulty WHERE ChallengeId = '. quotedString($id) .' AND PlayerId = '. quotedString($player->id) .' LIMIT 1;');
	if (mysql_num_rows($res) > 0) {
		$row = mysql_fetch_row($res);
		$rtn = $row[0];
	} else {
		$rtn = 0;
	}
	mysql_free_result($res);
	return $rtn;
}

function rld_checkVoting($id,$player) {
global $aseco;	
	$result = mysql_query('SELECT * FROM rs_difficulty WHERE ChallengeId = '. quotedString($id) .' AND PlayerId = '. quotedString($player->id) .' LIMIT 1');
	if (mysql_num_rows($result) == 0) {
		mysql_free_result($result);
		return false;
	} else {
		return true;
	}
}

function rld_getAverage($id) {
	$res = mysql_query('SELECT ChallengeId, ROUND(AVG(Score),2) AS difficulty FROM rs_difficulty GROUP BY ChallengeId ORDER BY difficulty DESC');
	$rtn = null;
	while ($dbrow = mysql_fetch_array($res)) {
		if($dbrow[0] == $id) {
			$rtn = $dbrow[1];
			break;
		}
	}
	if (is_null($rtn)) {
		$rtn = 'N/A';
	}
	mysql_free_result($res);
	return $rtn;
}

function rld_onPlayerConnect($aseco,$player) {
	// Do nothing at Startup!!
	if ($aseco->startup_phase == false) {
		rld_showWidget($player);
	}
}

function rld_onNewChallenge($aseco,$challenge) {
	rld_showWidgetAll();
}

function rld_onEndRace($aseco,$race) {
	rld_hideWidgetAll();
}

function rld_showWidget($player) {
global $aseco, $rld_widgedEnabled;
	if (!$rld_widgedEnabled) {
		return;
	}
	
	// Build widget default
	$widget = rld_buildDifficultyWidget($player);
	
	// Get player voting
	$id = $aseco->server->challenge->id;
	$voting = rld_getVoting($id,$player);
	
	// Get average
	$avg = strval(rld_getAverage($id));
	
	// Get Amount Votes
	$votes = rld_getAmountVotes($id);
	
	// Replace strings
	$votepos = ($voting * 1.2) - 1.85;
	$widget = str_replace('%YourVote%',$votepos,$widget);
	$widget = str_replace('%AmountVotes%',$votes,$widget);
	$widget = str_replace('%Average%',$avg,$widget);
	
	// Send Manialink
	$aseco->client->query('SendDisplayManialinkPageToLogin', $player->login, $widget, 0, false);
}

function rld_showWidgetAll() {
global $aseco, $rld_widgedEnabled;
	if (!$rld_widgedEnabled) {
		return;
	}
	
	// Build widget default
	$widget = rld_buildDifficultyWidget($player);
	
	//Get map ID for getVoting and getAvg functions
	$id = $aseco->server->challenge->id;
	
	// Get average
	$avg = strval(rld_getAverage($id));
	
	// Get Amount Votes
	$votes = rld_getAmountVotes($id);
	
	// Replace strings which are the same for all players
	$widget = str_replace('%AmountVotes%',$votes,$widget);
	$widget = str_replace('%Average%',$avg,$widget);
	
	foreach($aseco->server->players->player_list as $player) {
	// Replace strings which are individual
		// Get player voting
		$voting = rld_getVoting($id,$player);
		$votepos = ($voting * 1.2) - 1.85;
		$widgetInd = str_replace('%YourVote%',$votepos,$widget);
		
		// Send Manialink
		$aseco->client->query('SendDisplayManialinkPageToLogin', $player->login, $widgetInd, 0, false);
	}
}

function rld_hideWidget($payer) {
global $aseco, $rld_widgedEnabled;
	if (!$rld_widgedEnabled) {
		return;
	}
	
	$header  = '<manialink id="42000003">';
	$header .= '</manialink>';
	$aseco->client->query('SendDisplayManialinkPageToLogin', $player->login, $header, 0, false);
}

function rld_hideWidgetAll() {
global $aseco, $rld_widgedEnabled;
	if (!$rld_widgedEnabled) {
		return;
	}
	
	$header  = '<manialink id="42000003">';
	$header .= '</manialink>';
	
	foreach($aseco->server->players->player_list as $player) {
		$aseco->client->query('SendDisplayManialinkPageToLogin', $player->login, $header, 0, false);
	}
}

function rld_hideWindow($player) {
global $aseco;
	$header  = '<manialink id="42000002">';
	$header .= '</manialink>';
	$aseco->client->query('SendDisplayManialinkPageToLogin', $player->login, $header, 0, false);
}

function rld_hideWindowAll() {
global $aseco;
	$header  = '<manialink id="42000002">';
	$header .= '</manialink>';
	
	foreach($aseco->server->players->player_list as $player) {
		$aseco->client->query('SendDisplayManialinkPageToLogin', $player->login, $header, 0, false);
	}
}

function rld_showDifficultyWindow($player) {
global $aseco;
	$uid = $aseco->server->challenge->uid;
	$id = $aseco->getChallengeId($uid);	
	$name = rld_getChallengeName($uid);
	
	$header  = '<manialink id="42000002">';
	
	// Window
	$header .= '<frame posn="-20.05 30.45 -3">';	// BEGIN: Window Frame
	$header .= '<quad posn="0.8 -0.8 0.01" sizen="36.4 53.7" bgcolor="001B"/>';
	$header .= '<quad posn="-0.2 0.2 0.09" sizen="38.4 55.7" style="Bgs1InRace" substyle="BgCard3"/>';
	
	// Header Line
	$header .= '<quad posn="0.8 -1.3 0.02" sizen="36.4 3" bgcolor="09FC"/>';
	$header .= '<quad posn="0.8 -4.3 0.03" sizen="36.4 0.1" bgcolor="FFF9"/>';
	$header .= '<quad posn="1.8 -1.4 0.10" sizen="2.8 2.8" style="Icons128x128_1" substyle="NewTrack"/>';
	$header .= '<label posn="5.5 -1.8 0.10" sizen="32 0" halign="left" textsize="2" scale="0.9" textcolor="FFFF" text="Vote difficulty for '. $name .'"/>';
	
	// Close Button
	$header .= '<frame posn="35.4 1.3 0">';
	$header .= '<quad posn="0 0 0.10" sizen="4 4" style="Icons64x64_1" substyle="ArrowDown"/>';
	$header .= '<quad posn="1.1 -1.35 0.11" sizen="1.8 1.75" bgcolor="EEEF"/>';
	$header .= '<quad posn="0.65 -0.7 0.12" sizen="2.6 2.6" action="42000000" style="Icons64x64_1" substyle="Close"/>';
	$header .= '</frame>';
	
	// Vote Buttons
	$header .= '<frame posn="4 -6.8 0">';
	for ($i = 0; $i <= 9; $i++) {
		$header .= '<quad posn="0 '.  $i * -4.7 .' 1" sizen="5 2" action="4200010'. $i .'" style="Bgs1" substyle="BgIconBorder"/>';
		$header .= '<label posn="2.1 '.  (($i * -4.7) - 0.37) .' 1.1" sizen="32 0" textsize="1" scale="0.9" textcolor="09FE" text="$o'. $i .'"/>';
		
	}
	$header .= '</frame>';
	
	// Place Your Vote Indication
	if (rld_checkVoting($id,$player)) {
		$value = rld_getVoting($id,$player);
		$header .= '<frame posn="4 -6.8 0">';
		$header .= '<quad posn="-2.62 '.  (($value * -4.7) + 0.48) .' 1.1" sizen="2.6 2.6" style="BgRaceScore2" substyle="Fame"/>';
		$header .= '<label posn="5.3 '.  (($value * -4.7) - 0.18) .' 1.1" sizen="32 0" textsize="1" scale="0.9" textcolor="FC0E" text="Your vote"/>';
		$header .= '</frame>';
	}
	
	// Overall Average
	$average = rld_getAverage($id);
	$header .= '<frame posn="24 -6.8 0">';
	$header .= '<label posn="0 '.  (($average * -4.7) + 0.56) .' 1.1" sizen="32 0" textsize="2" scale="0.9" valign="center" textcolor="3C09" text="Average Voting"/>';
	$header .= '<label posn="4 '.  (($average * -4.7) - 2.2) .' 1.1" sizen="32 0" textsize="1" scale="0.9" valign="center" textcolor="3C09" text="'. $average .'"/>';
	$header .= '<quad posn="-7.1 '.  (($average * -4.7) - 0.85) .' 0.03" sizen="20 0.1" bgcolor="3C09"/>';
	$header .= '</frame>';
	
	// END: Window Frame
	$header .= '</frame>';				
	$header .= '</manialink>';
	
	$aseco->client->query('SendDisplayManialinkPageToLogin', $player->login, $header, 0, false);
}

function rld_onPlayerManialinkPageAnswer($aseco,$answer) {	
global $rld_minRecords;
	//42000000 = Closebutton
	//42000000 = Difficulty Window big
	//42000100 - 42000109 = Votings
	//42000110 - 42000119 = Votings Widget
	$value = $answer[2];
	$player = $aseco->server->players->getPlayer($answer[1]);
	
	if ($value == 42000000) {
		rld_hideWindow($player);
	} elseif ($value >= 42000100 && $value <= 42000109) {
		if(rld_getAmountLocals($player) >= $rld_minRecords) {
			$difficulty = mb_substr(strval($value), -1);
			$uid = $aseco->server->challenge->uid;
			$id = $aseco->getChallengeId($uid);	
			if(rld_checkVoting($id,$player)) {
				rld_editVoting($id,$difficulty,$player);
			} else {			
				rld_addVoting($id,$difficulty,$player);
			}
			rld_showDifficultyWindow($player);
			rld_showWidgetAll();
		} else {
			$message = '{#server}> {#error}In order to vote, you have to atleast finish '. $rld_minRecords .' maps in total.';
			$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $player->login);
		}
	} elseif ($value >= 42000110 && $value <= 42000119) {
		if(rld_getAmountLocals($player) >= $rld_minRecords) {
			$difficulty = mb_substr(strval($value), -1);
			$uid = $aseco->server->challenge->uid;
			$id = $aseco->getChallengeId($uid);	
			if(rld_checkVoting($id,$player)) {
				rld_editVoting($id,$difficulty,$player);
			} else {			
				rld_addVoting($id,$difficulty,$player);
			}
			rld_showWidgetAll();
		} else {
			$message = '{#server}> {#error}In order to vote, you have to atleast finish '. $rld_minRecords .' maps in total.';
			$aseco->client->query('ChatSendServerMessageToLogin', $aseco->formatColors($message), $player->login);
		}
	} elseif ($value == 42000002) {
		// Open Window - Click on Widget
		rld_showDifficultyWindow($player);
	}
}

function rld_buildDifficultyWidget() {
global $rld_widgetPos;

	$xpos = $rld_widgetPos[0];
	$ypos = $rld_widgetPos[1];
	
	//Replacements %YourVote% ((($i * 1.2) - 1.85)) %AmountVotes% %Average%
	
	// Begining
	$header  = '<manialink id="42000003">';
	
	// Window
	$header .= '<frame posn="'. $xpos .' '. $ypos .' -3">';	// BEGIN: Window Frame
	$header .= '<quad posn="0 0 0.02" sizen="15.76 10.75" action="42000002" style="Bgs1InRace" substyle="NavButton"/>';
	$header .= '<quad posn="-0.3 -7.4 0.05" sizen="3.5 3.5" image="http://maniacdn.net/undef.de/xaseco1/mania-karma/edge-open-ld-dark.png"/>';
	
	// Vote Frame
	$header .= '<frame posn="0 0 0">';
	
	// Title
	$header .= '<quad posn="0.4 -0.34 3" sizen="14.96 2.2" style="BgsPlayerCard" substyle="BgRacePlayerName"/>';
	// Position from icon and title to left
	$header .= '<quad posn="0.6 -0.15 3.1" sizen="2.3 2.3" style="Icons128x128_1" substyle="Challenge"/>';
	$header .= '<label posn="3.2 -0.6 3.2" sizen="10 0" halign="left" textsize="1" text="$FFFDifficulty"/>';
	
	// BG for Buttons to prevent flicker of the widget background (clickable too)
	$header .= '<frame posn="1.83 -8.3 1">';
	$header .= '<quad posn="0.2 -0.08 0.1" sizen="11.8 1.4" action="91118" bgcolor="0000"/>';
	$header .= '</frame>';
	
	//Buttons
	for ($i = 0; $i <= 9; $i++) {
		$header .= '<frame posn="3.83 -8.5 1">';
		$header .= '<label posn="'. (($i * 1.2) - 1.8) .' 0.4 1" sizen="1 2" action="4200011'. $i .'" focusareacolor1="'. strval($i) . strval(9-$i) .'0F" focusareacolor2="'. strval($i) . strval(9-$i) .'1C" text=" "/>';
		$header .= '</frame>';
	}
	
	// Legend
	$header .= '<frame posn="3.83 -8.5 1">';
	$header .= '<quad posn="-1.8 3 1" sizen="0.1 2.3" bgcolor="AAAB"/>';
	$header .= '<quad posn="10 3 1" sizen="0.1 2.3" bgcolor="AAAB"/>';
	$header .= '<label posn="-1.6 3 1" sizen="32 0" textsize="1" halign="left" scale="0.9" textcolor="090F" text="Easy"/>';
	$header .= '<label posn="9.8 3 1" sizen="32 0" textsize="1" halign="right" scale="0.9" textcolor="900F" text="Hard"/>';
	$header .= '</frame>';
	
	// Position of Your Vote Arrow
	$header .= '<frame posn="3.83 -8.5 1">';
	$header .= '<quad posn="%YourVote% 1.6 1.01" sizen="1.1 1.1" style="Icons64x64_1" substyle="YellowLow"/>';
	$header .= '</frame>';
	
	// Amount votes and Average in Label
	$header .= '<frame posn="3.83 -8.5 1">';
	$header .= '<label posn="-1.8 5 1" sizen="32 0" textsize="1" halign="left" scale="0.9" textcolor="FFFE" text="%AmountVotes% votes"/>';
	$header .= '<label posn="10 5 1" sizen="32 0" textsize="1" halign="right" scale="0.9" textcolor="FFFE" text="%Average% Ø"/>';
	$header .= '</frame>';
	
	// Closing
	$header .= '</frame>'; // Vote Frame
	$header .= '</frame>'; // MainWidget Frame
	$header .= '</manialink>';
	
	return $header;
}
?>