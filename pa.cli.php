<?php

class ECli extends ExceptionTemplate {
	protected $_list = array(
			'ent_name0'		=> array(1, 'The provided string "{needle}" matches no entity'),
			'ent_nameN'		=> array(2, 'The provided string "{needle}" matches multiple entities'),
			'ent_nothis'	=> array(3, 'There\'s no active entity :('),
			);
	}

/** Match a PulseAudio entity type: one of self::$ENT_LIST
 * @param string	$name	Entity type string
 * @param bool		$prefix	Enable prefix-matching
 * @return string|null The full name. It's a property of PulseAudio
 */
function str_imatch($haystack, $needle, $partial = true){
	$needleL = strlen($needle);
	$candidates = array();
	// Find
	foreach ($haystack as $entname){
		$entnameL = strlen($entname);
		if ($needleL > $entnameL)
			continue;
		if (!$partial && $needleL != $entnameL)
			continue;
		$cmpL = min($needleL, $entnameL);
		if (strncasecmp($needle, $entname, $cmpL) === 0)
			$candidates[] = $entname;
		}
	// Return
	if (count($candidates) == 0){
		$candidates = $haystack;
		throw new ECli('ent_name0', compact('needle', 'candidates'));
		}
	if (count($candidates) > 1)
		throw new ECli('ent_nameN', compact('needle', 'candidates'));
	return array_shift($candidates);
	}

/** Find an entity within PulseAudio
 * @param PulseAudio	$PA
 * @param string		$entity_ts	Entity type: 'sinks'. $PA's property, actually
 * @param int|string	Entity reference || 'this' to try to find the 'is_active' one
 */
function find_entity(PulseAudio $PA, $entity_ts, $entity_ref){
	$entities = $PA->$entity_ts;
	if ($entity_ref == "this") {
		foreach ($entities as $Entity)
			if ($Entity->is_default)
				return $Entity;
		throw new ECli('ent_nothis');
		}
	return $entities[$entity_ref];
	}

/** Find the 'next' entity (based on the 'is_default' property)
 * @param __PAentities	$entities	Entities to search
 * @param string		$propname	Property name to check for =TRUE
 * @return PulseAudioEntity
 */
function next_entity(__PAentities $entities, $propname = 'is_default'){
	$First = null;
	$Current = null;
	$Next = null;
	foreach ($entities as $Entity){
		if (is_null($First))
			$First = $Entity;
		if (is_null($Current)){
			if ($Entity->$propname)
				$Current = $Entity;
			} elseif (is_null($Next))
			$Next = $Entity;
		}
	return $Next? $Next : $First;
	}