#!/usr/bin/env php
<?php
/** PulseAudio PHP CLI interface
 * @author o_O Tync, ICQ# 1227-700, JID: ootync@gmail.com
 * @version 1.0, 10.2010
 * Enjoy! :)
 */

require("/media/0DAY/C0ding/webdev/www/SCRIPTS/My/LOCAL/PulseAudio/PulseAudio.API.php");
require("/media/0DAY/C0ding/webdev/www/SCRIPTS/My/LOCAL/PulseAudio/pa.cli.php");

$PA = new PulseAudio(`pactl list`, `pactl stat`);


/* ==========[ PREPARE ] ========== */
function display_help(){
	$entities = implode(', ', PulseAudio::$ENT_LIST);
	print <<<EOF
PulseAudio Tricks!
Usage:
	patricks ls [<Entity>]							— Display a short list of entities of the specified type.
	patricks ls <Entity> <index|name> {volume|ports|profiles|properties}		— Display complex Entity properties.
	patricks show [<Entity> [index|name]]						— Display a detailed list of entities, or one entity.
	patricks mv {sink|source} <index|name> {all|<id> [...]}			— Move some/all Sink-Input/Source-Output to another Sink/Input.
	patricks set {sink|source} <index|name> default					— Set the default Sink/Source.
	patricks set {sink|source} <index|name> port <index|name>		— Change the port of a Sink/Source.
	patricks set card <index|name> profile <index|name>				— Change the profile of a card.
	patricks suspend {sink|source} <index|name>						— Suspend a Sink/Source.
	patricks volume {sink|input|source} <index|name> mute [{0|1}]	— Set the mute switch, or toggle.
	patricks volume {sink|input|source} <index|name> set 100%		— Set the volume of a Sink/Source or a Sink-Input.
Entities: $entities
Feature: all the literals can be shortened! 'sinks' => 'si', 'volume' => 'vol'
Examples:
	patricks ls sinks
	patricks ls si 'alsa_card.pci-0000_00_1b.0' vol
	patricks show sink 0
	patricks mv sink 0 all
	patricks mv sink 'alsa_card.pci-0000_00_1b.0' 1 19 235
	patricks set sink 0 def
	patricks set source 0 port 0
	patricks set card 0 prof 0
	patricks suspend sink 0
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

try { $CMD = strtoupper(str_imatch(array('ls', 'show', 'mv', 'set'), $CMD, true)); }
	catch (ECli $e) { $CMD = '*UNKNOWN*'; }

try {
	switch ($CMD){
		case 'SHOW':
		case 'LS':
			$entns = PulseAudio::$ENT_LIST;
			// [0]: entity type
			if (isset($ARGS[0]))
				$entns = array(  str_imatch(PulseAudio::$ENT_LIST, $ARGS[0], true)  );
			// Iterate
			foreach ($entns as $entn){
				$Entities = $PA->$entn;
				// [1]: entity reference
				if (isset($ARGS[1])){
					if (!isset($Entities[$ARGS[1]]))
						exit(100);
					$Entities = array(  $Entities[$ARGS[1]]  );
					}
				// Print
				foreach ($Entities as $Entity)
					if (isset($ARGS[2])){ // [2]: complex property display
							$infotype = str_imatch(array('volume','ports','profiles','properties'), $ARGS[2], true );
							switch ($infotype){
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
						elseif ($CMD == 'LS'){
						if ($Entity instanceof __PAent_Sink || $Entity instanceof __PAent_Source)
							printf("%s%s\n", $Entity->is_default?'>':' ',$Entity);
							else
							echo "$Entity\n";
						}
						else // Detailed info
						echo PulseAudio::DisplayEntity($Entity, false) , "\n\n";
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
					$move_to = $PA->sinks[$move_to_ref];
					$move_entity_t = 'input';
					$move_entities = $PA->inputs;
					break;
				case 'source':
					$move_to = $PA->sources[$move_to_ref];
					$move_entity_t = 'output';
					$move_entities = $PA->outputs;
					break;
				}
			//=== Find entities
			foreach ($move_refs as $ref)
				if (strcasecmp($ref, 'all') === 0)
					$move = (array)$move_entities;
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
			print "Moving {".implode(',',$move_ids)."} {$move_entity_t}s to $move_to_entity_t \"{$move_to->name}\"...\n";
			foreach ($move_ids as $id)
				`pactl move-$move_to_entity_t-$move_entity_t "$id" "{$move_to->name}"`;
			break;
		case 'SET':
			if (count($ARGS) < 3 || ($ARGS[2] != 'default' && count($ARGS) < 4)) {
				display_help();
				exit(1);
				}
			//=== Collect data
			$entity_t = str_imatch(array('sink', 'source', 'card'), array_shift($ARGS), true); // [0]: The entity type to modify
			$entity_ref = array_shift($ARGS); // [1]: Entity reference
			$Entity = null; // The entity to modify
			$setprop = str_imatch(array('default', 'port', 'profile'), array_shift($ARGS), true); // [2]: Its proprerty to modify
			$set_ref = ''; // [3]: The entity to reference into $setprop
			if ($setprop != 'default')
				$set_ref = array_shift($ARGS);

			//=== Prepare
			$entname = "{$entity_t}s";
			$entities = $PA->$entname;
			$Entity = $entities[$entity_ref];

			//=== Action!
			switch ($setprop){
				case 'default':
					`pacmd set-default-$entity_t "{$Entity->name}"`;
					print "$Entity\n";
					break;
				case 'port':
					$Port = $Entity->ports[$set_ref];
					`pacmd set-$entity_t-port "{$Entity->id}" "{$Port->name}"`;
					print "{$Port}\n";
					break;
				case 'profile':
					$Profile = $Entity->profiles[$set_ref];
					`pacmd set-$entity_t-profile "{$Entity->id}" "{$Profile->name}"`;
					print "{$Profile}\n";
					break;
				}
			break;
		// TODO: volume, mute, suspend
		default:
			display_help();
			exit(1);
		}



	} catch (ECli $e) {
	fprintf(STDERR,  "E:%s: %s\n", $e->nick, $e->message);
	if (isset($e->context['candidates']))
		fprintf(STDERR,  "Candidates are: %s\n", implode(', ', $e->context['candidates']));
	}