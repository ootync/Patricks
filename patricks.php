#!/usr/bin/env php
<?php
/** PulseAudio PHP CLI interface
 * @author o_O Tync, ICQ# 1227-700, JID: ootync@gmail.com
 * @version 1.0, 10.2010
 * Enjoy! :)
 */

set_include_path(get_include_path() . PATH_SEPARATOR . dirname(__FILE__));

require("PulseAudio.API.php");
require("pa.cli.php");

$PA = new PulseAudio(`pactl list`, `pactl stat`);


/* ==========[ PREPARE ] ========== */
function display_help(){
	$entities = implode(', ', PulseAudio::$ENT_LIST);
	print <<<EOF
PulseAudio Tricks!
Usage:
	patricks ls [<Entity>]								— Display a short list of entities of the specified type.
	patricks ls <Entity> <index|name>						— Display a detailed info on one entity
	patricks ls <Entity> <index|name> {volume|ports|profiles|properties}		— Display complex Entity properties.
	patricks mv {sink|source} {next|<index|name>} {all|<id> [...]}			— Move some/all Sink-Input/Source-Output to another Sink/Input.
	patricks set {sink|source} <index|name> default					— Set the default Sink/Source.
	patricks set {sink|source} <index|name> port {next|<index|name>}		— Change the port of a Sink/Source.
	patricks set card <index|name> profile {next|<index|name>}			— Change the profile of a card.
	patricks suspend {sink|source} <index|name> [{0|1}]				— Suspend a Sink/Source.
	patricks volume {sink|input|source} <index|name> mute [{0|1}]			— Set the mute switch, or toggle.
	patricks volume {sink|input|source} <index|name> set 100%			— Set the volume of a Sink/Source or a Sink-Input.
Entities:
	$entities
Feature: all the literals can be shortened! 'sinks' => 'si', 'volume' => 'vol'
Examples:
	patricks ls sinks
	patricks ls sink 0
	patricks ls si 'alsa_card.pci-0000_00_1b.0' vol
	patricks mv sink 0 all
	patricks mv sink next all
	patricks mv sink 'alsa_card.pci-0000_00_1b.0' 1 19 235
	patricks set sink 0 def
	patricks set source 0 port next
	patricks set card 0 prof 0
	patricks suspend sink 0 1
	patricks volume sink 0 mute
	patricks vol sink 0 set 10%
(c) 10.2010 o_O Tync, ICQ# 1227-700, JID: ootync@gmail.com
Enjoy!

EOF;
	}

$argc--;
if ($argc<1) { display_help(); exit(0); }

// Command
$CMD = $argv[1];
$ARGS = array_slice($argv, 2);

/* ==========[ COMMANDS ] ========== */

try { $CMD = strtoupper(str_imatch(array('ls', 'show', 'mv', 'set', 'suspend', 'volume'), $CMD, true)); }
	catch (ECli $e) { $CMD = '*UNKNOWN*'; }

try {
	switch ($CMD){
		case 'LS':
			$entities_t = PulseAudio::$ENT_LIST;
			// [0]: entity type
			if (isset($ARGS[0]))
				$entities_t = array(  str_imatch(PulseAudio::$ENT_LIST, $ARGS[0], true)  );
			// Iterate
			foreach ($entities_t as $entity_t){
				$Entities = $PA->$entity_t;
				// [1]: entity reference
				if (isset($ARGS[1])){
					if (!isset($Entities[$ARGS[1]]))
						exit(100);
					$Entities = array(  $Entities[$ARGS[1]]  );
					if (!isset($ARGS[2]))
						$ARGS[2] = 'FULL';
					}
				// [2]: display additional info
				$infotype = '';
				if (isset($ARGS[2]))
					$infotype = str_imatch(array('full', 'volume','ports','profiles','properties'), $ARGS[2], true );
				// Print
				foreach ($Entities as $Entity)
						switch ($infotype){
							case '':
								if ($Entity instanceof __PAent_Sink || $Entity instanceof __PAent_Source)
									printf("%s%s\n", $Entity->is_default?'>':' ',$Entity);
									else
									echo "$Entity\n";
								break;
							case 'full':
								echo PulseAudio::DisplayEntity($Entity, false) , "\n\n";
								break;
							case 'volume':
								if (isset($Entity->volume) && !is_null($Entity->volume))
									print $Entity->volume->Display();
								break;
							case 'ports':
							case 'profiles':
								if (isset($Entity->$infotype) && !is_null($Entity->$infotype))
									foreach ($Entity->$infotype as $item)
										print $item->Display();
								break;
							case 'properties':
								if (isset($Entity->proplist) && !is_null($Entity->proplist))
									print $Entity->proplist;
								break;
							}
				}
			break;
		case 'MV':
			if (count($ARGS) < 3) {
				display_help();
				exit(1);
				}
			//=== Collect data
			$move_to_entity_t = str_imatch(array('sink', 'source'), array_shift($ARGS), true); // [0]: Entity type to move to: sink|source
			$move_to_ref = array_shift($ARGS); // [1]: Entity to move to: reference
			$move_to = null; // Entity to move to.
			$move_entity_t = ''; // Entity type to move: input|output
			$move_entities = null; // $PA->$move_entity
			$move_refs = $ARGS; // [2,]: Reference to entities that should be moved
			$move = array(); // Entities to move.
			//=== Prepare
			switch ($move_to_entity_t){
				case 'sink':
					if ($move_to_ref != 'next')
						$move_to = $PA->sinks[$move_to_ref];
						else
						$move_to = next_entity($PA->sinks, 'is_default');
					$move_entity_t = 'input';
					$move_entities = $PA->inputs;
					break;
				case 'source':
					if ($move_to_ref != 'next')
						$move_to = $PA->sources[$move_to_ref];
						else
						$move_to = next_entity($PA->sources, 'is_default');
					$move_entity_t = 'output';
					$move_entities = $PA->outputs;
					break;
				}
			//=== Find entities
			$move_all = false;
			foreach ($move_refs as $ref)
				if (strcasecmp($ref, 'all') === 0){
					$move = (array)$move_entities;
					$move_all = TRUE;
					}
					else{
					try { $move[] = $move_entities[$ref]; }
						catch (EPulseAudio $e) { // Not found
							if ($e->nick == 'ent_404')
								fprintf(STDERR,  "E: %s\n", $e->message);
								else throw $e; // Throw further!
							}
					}
			//=== Move them
			$move_ids = array_map(function($Entity){return $Entity->id;}, $move); // Convert to the list of indexes
			print "Moving ".count($move_ids)." {$move_entity_t}s {".implode(',',$move_ids)."} to $move_to_entity_t \"{$move_to->name}\"...\n";
			foreach ($move_ids as $id)
				`pactl move-$move_to_entity_t-$move_entity_t "$id" "{$move_to->name}"`;
			//=== Set default if moved 'all'
			if ($move_all) {
				$ARGS = array($move_to_entity_t, $move_to->name, 'default');
				// proceed to 'SET sink|source <ref> default'
				}
				else break;
		case 'SET':
			if (count($ARGS) < 3 || ($ARGS[2] != 'default' && count($ARGS) < 4)) {
				display_help();
				exit(1);
				}
			//=== Collect data
			$entity_t = str_imatch(array('sink', 'source', 'card'), array_shift($ARGS), true); // [0]: The entity type to modify
			$entity_ref = array_shift($ARGS); // [1]: Entity reference
			$Entity = null; // The entity to modify
			$setprop = str_imatch(array('default', 'port', 'profile'), array_shift($ARGS), true); // [2]: Its property to modify
			$set_ref = ''; // [3]: The entity to reference into $setprop
			if ($setprop != 'default')
				$set_ref = array_shift($ARGS);

			//=== Prepare
			$Entity = find_entity($PA, "{$entity_t}s", $entity_ref);

			//=== Action!
			switch ($setprop){
				case 'default':
					`pacmd set-default-$entity_t "{$Entity->name}"`;
					print "$Entity: default $entity_t\n";
					break;
				case 'port':
					if ($set_ref != 'next')
						$Port = $Entity->ports[$set_ref];
						else
						$Port = next_entity($Entity->ports, 'is_active');
					`pactl set-$entity_t-port "{$Entity->id}" "{$Port->name}"`;
					print "$Entity: {$Port}\n";
					break;
				case 'profile':
					$Profile = $Entity->profiles[$set_ref];
					`pactl set-$entity_t-profile "{$Entity->id}" "{$Profile->name}"`;
					print "$Entity: {$Profile}\n";
					break;
				}
			break;
		case 'SUSPEND':
			if (count($ARGS) < 2) {
				display_help();
				exit(1);
				}
			//=== Collect data
			$entity_t = str_imatch(array('sink', 'source'), array_shift($ARGS), true); // [0]: The entity type to modify
			$entity_ref = array_shift($ARGS); // [1]: Entity reference
			$switch = count($ARGS)?  (  (int)(bool)array_shift($ARGS)  )  : 'toggle'; // [2]: switch action

			//=== Prepare
			$Entity = find_entity($PA, "{$entity_t}s", $entity_ref);

			//=== Action!
			if ($switch === 'toggle')
				$switch = ($Entity->state == 'SUSPENDED')? 0 : 1;
			`pactl suspend-$entity_t "{$Entity->name}" $switch`;
			print "$Entity: ".($switch?'suspend':'run')."\n";
			break;
		case 'VOLUME':
			if (count($ARGS) < 3) {
				display_help();
				exit(1);
				}
			//=== Collect data
			$entity_t = str_imatch(array('sink', 'input', 'source'), array_shift($ARGS), true); // [0]: Entity type to control
			$entity_ref = array_shift($ARGS); // [1]: Entity reference
			$action = str_imatch(array('mute', 'set'), array_shift($ARGS), true); // [2]: action: mute|set

			//=== Prepare
			$Entity = find_entity($PA, "{$entity_t}s", $entity_ref);

			//=== Action!
			if ($entity_t == 'input')
				$entity_t = 'sink-input'; // PulseAudio uses a different name
			switch ($action){
				case 'mute':
					$switch = count($ARGS)?  (  (int)(bool)array_shift($ARGS)  )  : 'toggle'; // [3]: switch action
					if ($switch === 'toggle')
						$switch = (int)(!$Entity->mute);
					`pactl set-$entity_t-mute "{$Entity->name}" $switch`;
					print "$Entity: mute=".($switch?'true':'false')."\n";
					break;
				case 'set':
					$volp = count($ARGS)?  (float)array_shift($ARGS)  : $Entity->volume->avg;  // [3]: volume percentage
					$vol = (int)(($volp/100) * 65535);
					`pactl set-$entity_t-volume "{$Entity->name}" $vol`;
					print "$Entity: volume=$volp%\n";
					break;
				}
			break;
		default:
			display_help();
			exit(1);
		}



	} catch (ECli $e) {
	fprintf(STDERR,  "E:%s: %s\n", $e->nick, $e->message);
	if (isset($e->context['candidates']))
		fprintf(STDERR,  "Candidates are: %s\n", implode(', ', $e->context['candidates']));
	}