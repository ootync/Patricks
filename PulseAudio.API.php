<?php
/** PulseAudio PHP API
 * @author o_O Tync, ICQ# 1227-700, JID: ootync@gmail.com
 * @version 1.0, 10.2010
 * Enjoy! :)
 */
abstract class ExceptionTemplate extends Exception {
	protected $_list = array(
			);

	public $file;
	public $line;
	public $message;
	public $nick;
	public $context;

	function __construct($nickname, array $context = array()){
		$this->nick = $nickname;
		$this->context = $context;
		// Find data by nickname
		if (!isset($this->_list[$nickname]))
			trigger_error(sprintf('%s::__construct(%s): exception nickname undefined', get_class($this), $nickname), E_USER_WARNING);
		$E = $this->_list[$nickname];
		// Templating
		$msg = preg_replace_callback('~\{([^{]+)\}~u', function($m)use($context){ return $context[$m[1]]; }, $E[1]);
		// Construct
		parent::__construct($msg, $E[0]);
		}

	function __toString(){
		return sprintf("Exception %s::%s (@%s:%d):\n\t%s\n%s\n",
					get_class($this), $this->nick, basename($this->file), $this->line,
					$this->message,
					var_export($this->context,1)
					);
		}
	}
class EPulseAudio extends ExceptionTemplate {
	protected $_list = array(
			'ent_404'		=> array(1, 'The specified {ent} "{ref}" was not found!'),
			'ent_name_404'	=> array(2, 'Found an unknown entity: "{ent}"'),
			'ent_field_404'	=> array(3, 'Found an unknown {ent} field: "{field}"'),
			);
	}


/** PulseAudio info storage & actions interface
 */
class PulseAudio {
	/** List of entities stored in here
	 */
	static public $ENT_LIST = array('modules', 'sinks', 'sources', 'cards', 'clients', 'inputs', 'outputs', 'samples');

	/** Cross-References: entity => field => referenced_entity
	 * @var string[][]
	 */
	static protected $_XREFS = array(
		'modules' => array(
			),
		'sinks' => array(
			'owner_module' => 'modules',
			'monitor_source' => 'sources',
			),
		'sources' => array(
			'owner_module' => 'modules',
			'monitor_of_sink' => 'sinks',
			),
		'cards' => array(
			'owner_module' => 'modules',
			),
		'clients' => array(
			'owner_module' => 'modules',
			),
		'inputs' => array(
			'owner_module' => 'modules',
			'client' => 'clients',
			'sink' => 'sinks',
			),
		'outputs' => array(
			'owner_module' => 'modules',
			'client' => 'clients',
			'source' => 'sources',
			),
		'samples' => array(
			),
		);

	/** Parse the data
	 * @param string	$pactl_list	`pactl list` output
	 * @param string	$pactl_stat	`pactl stat` output
	 * @throws EPulseAudio(ent_name_404,ent_field_404)
	 */
	function __construct($pactl_list, $pactl_stat){
		//=== Initialize Entities lists
		foreach (self::$ENT_LIST as $ent_name)
			$this->$ent_name = new __PAentities( ucfirst(substr($ent_name, 0, -1)) );
		//=== Parse the data
		foreach (explode("\n\n", $pactl_list) as $ent_str){
			$ent_str = explode("\n",$ent_str);
			//=== Parse the header line
			list($ent_type,$ent_index) = explode(' #', trim(array_shift($ent_str)));
			$ent_className = "__PAent_".strtr($ent_type, ' ', '_'); // Entity class name
			$ent_fieldName = strtolower(array_pop(explode(' ', $ent_type))).'s'; // $this->field name
			$entlist = &$this->$ent_fieldName; // reference to the field
			//=== Create a class instance
			if (!class_exists($ent_className, false))
				throw new EPulseAudio('ent_name_404', array('ent' => $ent_type));
			$entlist[] = new $ent_className($this, $ent_type, $ent_index, $ent_str); // Create an entity. It will parse itself
			}
		//=== Stats
		foreach (explode("\n", $pactl_stat) as $line)
			if (strlen($line = trim($line))){
				list($name,$value) = explode(':', $line, 2);
				$value = trim($value);
				switch ($name){
					case 'Default Sink':
						$this->sinks[$value]->is_default = true;
						break;
					case 'Default Source':
						$this->sources[$value]->is_default = true;
						break;
					}
				}
		//=== Cross-References
		foreach (self::$_XREFS as $reffing => $_refsmap) // for all names that cen refer to other entities
			foreach ($this->$reffing as $reffing_entity) // for all actual entities that can refer to other entities
				foreach ($_refsmap as $prop => $reffed) // for all properties that can refer
					if (!is_null($reffing_entity->$prop)){ // it actually refers
						$reffing_val = $reffing_entity->$prop; // The referring index|name
						$reffed_entities = $this->$reffed; // An array of entities. One of them is referenced
						$reffed_entity = $reffed_entities[$reffing_val]; // Lookup the referenced entity
						$reffing_entity->$prop = $reffed_entity; // Make the reference
						}
		}

	/** Modules
	 * @var __PAentities|__PAent_Module[]
	 * @readonly
	 */
	public $modules;

	/** Sinks
	 * @var __PAentities|__PAent_Sink[]
	 * @readonly
	 */
	public $sinks;

	/** Sources
	 * @var __PAentities|__PAent_Source[]
	 * @readonly
	 */
	public $sources;

	/** Cards
	 * @var __PAentities|__PAent_Card[]
	 * @readonly
	 */
	public $cards;

	/** Clients
	 * @var __PAentities|__PAent_Client[]
	 * @readonly
	 */
	public $clients;

	/** Sink Inputs
	 * @var __PAentities|__PAent_Sink_Input[]
	 * @readonly
	 */
	public $inputs;

	/** Source Outputs
	 * @var __PAentities|__PAent_Source_Output[]
	 * @readonly
	 */
	public $outputs;

	/** Samples
	 * @var __PAentities|__PAent_Sample[]
	 * @readonly
	 */
	public $samples;

	/** Display an entity in plaintext
	 * @return string
	 */
	static function DisplayEntity(PulseAudioEntity $entity, $show_proplist = true){
		$ret = "$entity\n";
		foreach (get_object_vars($entity) as $field => $value){
			$title = ($show_proplist?"\t":'').ucwords(strtr($field, '_', ' '));
			if ($value instanceof __PA_proplist_t)
				$ret .= $show_proplist? ("$title:\n".rtrim(str_replace("\n", "\n\t\t", (string)$value))."\n") : ("$title: ".count($value).' items');
				elseif (is_array($value) || $value instanceof __PAentities)
				$ret .= "$title: ".implode(', ', (array)$value)."\n";
				elseif (is_bool($value))
				$ret .= "$title: ".($value? 'yes' : 'no')."\n";
				elseif (is_null($value))
				$ret .= "$title: <null>\n";
				else
				$ret .= "$title: $value\n";
			}
		return $ret;
		}
	}

/** PulseAudio Entity with common functions
 */
abstract class PulseAudioEntity {
	/** Parent PulseAudio
	 * @var PulseAudio
	 */
	protected $_PA;

	/** Name of the entity type
	 * @var string
	 */
	protected $_Name;

	/** Parse entity data
	 * @param PulseAudio	$PA			An instance this entity belongs to
	 * @param string		$ent_name	Name of the entity, human-readable
	 * @param int			$index		Entity index
	 * @param string[]		$strinfo	A paragraph from `pactl list`
	 * @throws EPulseAudio(ent_field_404)
	 */
	final function __construct(PulseAudio $PA, $ent_name, $index, $info){
		$this->_PA = $PA;
		$this->_Name = $ent_name;
		$this->id = $index;
		$field = null;
		$val = null;
		$info[] = "\tNULL: NULL"; // finish
		foreach ($info as $line){
			switch (strspn($line, "\t ")){ // FSM works depending on the number or Tabs & Spaces
				case 1: // New field
					// FLush the previous field
					if (!is_null($field) && !$this->_parseField($field, trim($val)))
						throw new EPulseAudio('ent_field_404', array('ent' => $this->_Name, 'field' => $field));
					// Get the new field
					$line = explode(":", $line, 2);
					$field = trim($line[0]);
					$val = $line[1];
					break;
				case 2: // Previous field continuation
				case 9: // Fuck, 'Volume:' is indented with spaces, not tabs :(
					$val .= "\n$line"; // append to the previous
					break;
				}
			}
		}

	/** Entity index.
	 * @var string
	 */
	public $id;

	/** Entity name
	 * @var string
	 */
	public $name;

	/** Properties list, if any
	 * @var __PA_proplist_t|null
	 */
	public $proplist;

	/** Parse a field of an entity and store it to a local property
	 * @param string	$name	Name of the field to process
	 * @param string	$value	The value
	 * @return bool Whether the field is known to the engine
	 */
	protected function _parseField($name, $value){
		// This function implements parsing the common fields
		switch ($name){
			case 'Name':
				$this->name = $value;
				break;
			case 'Properties':
				$this->proplist = new __PA_proplist_t(explode("\n", $value));
				break;
			case 'Flags':
				$this->flags = explode(' ', $value);
				break;
			case 'Sample Specification':
				$this->sample_spec = new __PA_samplespec_t($value);
				break;
			case 'Channel Map':
				$this->channel_map = explode(',', $value);
				break;
			case 'Mute':
				$this->mute = ($value == 'yes');
				break;
			case 'Volume':
				$this->volume = new __PA_cvolume_t($value);
				if (property_exists($this, 'mute'))
					$this->volume->is_muted = $this->mute;
				break;
			default:
				// Try to find a property by name, with 'TRUE' value
				$prop = strtolower(strtr($name, " \t", '_'));
				if (property_exists($this, $prop ) && $this->$prop === TRUE){
					switch ($value){
						case '(null)':
						case 'n/a':
							$this->$prop = null;
							break;
						case 'yes':
						case 'no':
							$this->$prop = ($value=='yes');
							break;
						default:
							$this->$prop = $value;
							break;
						}
					return true;
					}
				return false;
			}
		return true;
		}

	final function __toString(){
		if (is_null($this->name))
			return "{$this->_Name}#{$this->id}".(isset($this->proplist['application.name'])? ' "'.$this->proplist['application.name'].'"': '');
		return "{$this->_Name}#{$this->id} <{$this->name}>";
		}
	}

/** PulseAudio entity: Module
 */
class __PAent_Module extends PulseAudioEntity {
	/** Argument string of the module
	 * @var string
	 */
	public $argument = TRUE; // =TRUE: simple copy. @see parent::_parseField()

	/** Usage counter or `null` when not used
	 * @var int|null
	 */
	public $n_used = TRUE;

	function _parseField($name, $value){
		switch ($name){
			case 'Usage counter':
				$this->n_used = ($value == 'n/a')? null : $value;
				break;
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}

/** PulseAudio entity: Sink
 */
class __PAent_Sink extends PulseAudioEntity {
	/** Whether this Sink is the default one
	 * @var bool
	 */
	public $is_default = false;

	/** Description
	 * @var string
	 */
	public $description = TRUE;

	/** State
	 * @var string
	 */
	public $state = TRUE;

	/** Driver name
	 * @var string
	 */
	public $driver = TRUE;

	/** The owning module
	 * @var __PAent_Module|null
	 */
	public $owner_module = TRUE;

	/** Sampling specification
	 * @var __PA_samplespec_t
	 */
	public $sample_spec;

	/** Channel map
	 * @var string[]
	 */
	public $channel_map = array();

	/** Mute switch
	 * @var bool
	 */
	public $mute = TRUE;

	/** Volume
	 * @var __PA_cvolume_t
	 */
	public $volume;

	/** Some kind of "base" volume that refers to unamplified/unattenuated volume in the context of the output device
	 * @var float
	 */
	public $base_volume;

	/** Length of queued audio in the output buffer.
	 * @var float
	 */
	public $latency;

	/** The latency this device has been configured to.
	 * @var float
	 */
	public $configured_latency;

	/** Flags.
	 * @var string[]
	 */
	public $flags;

	/** Array of available ports
	 * @var __PAentities|__PA_sink_port_info_t[]
	 */
	public $ports;

	/** The active port
	 * @var __PA_sink_port_info_t|null
	 */
	public $active_port;

	/** The monitor source connected to this sink
	 * @var __PAent_Source|null
	 */
	public $monitor_source = TRUE;

	function _parseField($name, $value){
		if (!$this->ports)
			$this->ports = new __PAentities('Sink Port');
		switch ($name){
			case 'Base Volume':
				$this->base_volume = (float)$value; // this will cut off the '%' sign
				break;
			case 'Latency':
				if (!preg_match('~^(\S+) usec, configured (\S+) usec$~', $value, $m))
					trigger_error("Latency: RegEx failed: '$value'", E_USER_WARNING);
					else
					list(, $this->latency, $this->configured_latency) = $m;
				break;
			case 'Ports':
				foreach (explode("\n", $value) as $port)
					if (strlen($port = trim($port))){
						$port = new __PA_sink_port_info_t($port);
						$port->id = count($this->ports);
						$this->ports[] = $port;
						}
				break;
			case 'Active Port':
				if (!isset($this->ports[$value]))
					trigger_error("Source: 'Active Port' value not found in 'Ports'", E_USER_WARNING);
					else {
					$this->active_port = $this->ports[$value];
					$this->active_port->is_active = true;
					}
				break;
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}

/** PulseAudio entity: Source
 */
class __PAent_Source extends __PAent_Sink { // NOTE: they have very much in common.
	/** Whether this source is the default one
	 * @var bool
	 */
	public $is_default = false;

	/** Array of available ports
	 * @var __PAentities|__PA_source_port_info_t[]|null
	 */
	public $ports;

	/** The active port
	 * @var __PA_source_port_info_t|null
	 */
	public $active_port;

	/** If this is a monitor, then — the owning sink
	 * @var __PAent_Sink|null
	 */
	public $monitor_of_sink = TRUE;

	function _parseField($name, $value){
		if (!$this->ports)
			$this->ports = new __PAentities('Source Port');
		if (isset($this->monitor_source)) unset($this->monitor_source); // Inherited, but not used
		switch ($name){
			case 'Ports':
				$this->ports = new __PAentities('Source Port');
				foreach (explode("\n", $value) as $port)
					if (strlen($port = trim($port))){
						$port = new __PA_source_port_info_t($port);
						$port->id = count($this->ports);
						$this->ports[] = $port;
						}
				break;
			case 'Active Port':
				if (!isset($this->ports[$value]))
					trigger_error("Source: 'Active Port' value not found in 'Ports'", E_USER_WARNING);
					else {
					$this->active_port = $this->ports[$value];
					$this->active_port->is_active = true;
					}
				break;
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}

/** PulseAudio entity: Card
 */
class __PAent_Card extends PulseAudioEntity {
	/** Driver name
	 * @var string
	 */
	public $driver = TRUE;

	/** The owning module
	 * @var __PAent_Module|null
	 */
	public $owner_module = TRUE;

	/** Array of available profiles
	 * @var __PAentities|__PA_card_profile_info_t[]|null
	 */
	public $profiles;

	/** The active profile
	 * @var __PA_card_profile_info_t|null
	 */
	public $active_profile;

	function _parseField($name, $value){
		if (!$this->profiles)
			$this->profiles = new __PAentities('Card Profile');
		switch ($name){
			case 'Profiles':
				foreach (explode("\n", $value) as $profile){
					$profile = new __PA_card_profile_info_t(trim($profile));
					$profile->id = count($this->profiles);
					$this->profiles[] = $profile;
					}
				break;
			case 'Active Profile':
				if (!isset($this->profiles[$value]))
					trigger_error("Source: 'Active Profile' value not found in 'Ports'", E_USER_WARNING);
					else {
					$this->active_profile = $this->profiles[$value];
					$this->active_profile->is_active = true;
					}
				break;
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}

/** PulseAudio entity: Client
 */
class __PAent_Client extends PulseAudioEntity {
	/** Driver name
	 * @var string
	 */
	public $driver = TRUE;

	/** The owning module
	 * @var __PAent_Module|null
	 */
	public $owner_module = TRUE;

	function _parseField($name, $value){
		switch ($name){
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}

/** PulseAudio entity: Sink Input
 */
class __PAent_Sink_Input extends PulseAudioEntity {
	/** Driver name
	 * @var string
	 */
	public $driver = TRUE;

	/** The owning module
	 * @var __PAent_Module|null
	 */
	public $owner_module = TRUE;

	/** The owner client
	 * @var __PAent_Client|null
	 */
	public $client = TRUE;

	/** The connected sink
	 * @var __PAent_Sink|null
	 */
	public $sink = TRUE;

	/** Sampling specification
	 * @var __PA_samplespec_t
	 */
	public $sample_spec;

	/** Channel map
	 * @var string[]
	 */
	public $channel_map = array();

	/** Mute switch
	 * @var bool
	 */
	public $mute = TRUE;

	/** Volume
	 * @var __PA_cvolume_t
	 */
	public $volume;

	/** Latency due to buffering in sink input, usec
	 * @var float
	 */
	public $buffer_latency;

	/** Latency of the sink device, usec
	 * @var float
	 */
	public $sink_latency;

	/** The resampling method used by this sink input
	 * @var string
	 */
	public $resample_method = TRUE;

	function _parseField($name, $value){
		switch ($name){
			case 'Buffer Latency':
				$this->buffer_latency = (float)$value; // will cut off the ' usec' substring
				break;
			case 'Sink Latency':
				$this->sink_latency = (float)$value; // will cut off the ' usec' substring
				break;
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}

/** PulseAudio entity: Source Output
 */
class __PAent_Source_Output extends __PAent_Sink_Input { // NOTE: they have very much in common.
	/** The connected sink
	 * @var __PAent_Source|null
	 */
	public $source = TRUE;

	/** Latency of the sink device, usec
	 * @var float
	 */
	public $source_latency;

	function _parseField($name, $value){
		if (isset($this->source)) unset($this->sink,$this->mute,$this->volume,$this->sink_latency); // Inherited, but not used
		switch ($name){
			case 'Buffer Latency':
				$this->buffer_latency = (float)$value; // will cut off the ' usec' substring
				break;
			case 'Source Latency':
				$this->source_latency = (float)$value; // will cut off the ' usec' substring
				break;
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}

class __PAent_Sample extends PulseAudioEntity {
	/** Volume
	 * @var __PA_cvolume_t
	 */
	public $volume;

	/** Sampling specification
	 * @var __PA_samplespec_t
	 */
	public $sample_spec;

	/** Channel map
	 * @var string[]
	 */
	public $channel_map = array();

	/** Duration of this entry, ms
	 * @var int
	 */
	public $duration;

	/** Length of this sample in bytes
	 * @var int
	 */
	public $bytes = TRUE;

	/** Whether it's a lazy cache entry
	 * @var bool
	 */
	public $lazy = TRUE;

	/** In case this is a lazy cache entry, the filename for the sound file to be loaded on demand
	 * @var string|null
	 */
	public $filename = TRUE;

	function _parseField($name, $value){
		switch ($name){
			case 'Duration':
				$this->duration = (float)$value;
				break;
			default:
				return parent::_parseField($name, $value);
			}
		return true;
		}
	}












/** PulseAudio struct: properties list
 */
class __PA_proplist_t extends ArrayObject {
	/** Parse the data
	 * @param string	$proplist	Properties list segment from `pactl list`
	 */
	function __construct($info){
		foreach ($info as $line)
			if (strlen($line = trim($line))){
				list($n,$v) = explode('=', $line, 2);
				$this[  trim($n)  ] = trim($v, "\r\n\t \"");
				}
		}

	function __toString(){
		$ret = '';
		foreach ($this as $property => $value)
			$ret .= "$property: $value\n";
		return $ret;
		}
	}

/** PulseAudio struct: sample specification
 */
class __PA_samplespec_t {
	/** The sample format
	 * @var string
	 */
	public $format;

	/** The sample rate, Hz
	 * @var string
	 */
	public $rate;

	/** Audio channels
	 * @var int
	 */
	public $channels;

	function __construct($info){
		if (!preg_match('~^(\S+)\s+(\d+)ch\s+(\d+)Hz$~', $info, $m))
			trigger_error("SampleSpec: RegEx failed: '$info'", E_USER_WARNING);
			else
			list(, $this->format, $this->rate, $this->channels) = $m;
		}

	function __toString(){
		return "{$this->format} {$this->channels}ch {$this->rate}Hz";
		}
	}

/** PulseAudio struct: Volume specification
 */
class __PA_cvolume_t {
	/** Per-Channel Volume, percent
	 * @var int[]
	 */
	public $perc;

	/** The average volume, percent
	 * @var float
	 */
	public $avg;

	/** Whether the volume is muted
	 * @var bool|null
	 */
	public $is_muted = null;

	/** Balance
	 * @var float
	 */
	public $balance;

	/** Per-Channel Volume, in dB.
	 * Only available when DECIBEL_VOLUME flag is set
	 * @var float[]|null
	 */
	public $db;

	function __construct($info){
		$M = array(); // matches
		foreach (array('perc' => '\d+:\s*(\S+)%', 'db' => '\d+:\s*(\S+) dB', 'balance' => 'balance\s*(\S)') as $prop => $regex)
			if (!preg_match_all('~'.$regex.'~um', $info, $M[$prop], PREG_PATTERN_ORDER) && $prop != 'db')
				trigger_error("Volume: RegEx '$prop' failed: '$info'", E_USER_WARNING);
		foreach ($M as $prop => $m)
			if ($prop == 'balance')
				$this->balance = (float)$m[1][0];
				else{
				$arr = &$this->$prop;
				$arr = array();
				foreach ($m[1] as $chan => $val)
					$arr[$chan] = (float)$val;
				}
		$this->avg = array_sum($this->perc) / count($this->perc);
		}

	function __toString(){
		return $this->avg.'%';
		}

	function Display(){
		$ret = '';
		$ret .= "Average: {$this->avg}%\n";
		$ret .= "Muted: ";
			if (is_null($this->is_muted))
				$ret .= "<unknown>";
				else
				$ret .= $this->is_muted? 'yes' : 'no';
			$ret .= "\n";
		$ret .= "Volume %: ";
			foreach ($this->perc as $v)
				$ret .= "$v%, ";
			$ret .= "\n";
		$ret .= "Volume dB: ";
			if (!is_null($this->db))
				foreach ($this->perc as $v)
					$ret .= "$v dB, ";
			$ret .= "\n";
		$ret .= "Balance: {$this->balance}\n";
		return $ret;
		}
	}

/** PulseAudio struct: Sink port info
 */
class __PA_sink_port_info_t {
	/** Index of this port
	 * @var int
	 */
	public $id;

	/** Name of this port
	 * @var string
	 */
	public $name;

	/** Description of this port
	 * @var string
	 */
	public $description;

	/** The higher this value is the more useful this port is as a default.
	 * @var int
	 */
	public $priority;

	/** Whether this port is the 'Active Port'
	 * @var bool
	 */
	public $is_active = false;

	function __construct($info){
		if (!preg_match('~^(.+): (.+) \(priority\. (\d+)\)$~', $info, $m))
			trigger_error("SampleSpec: RegEx failed: '$info'", E_USER_WARNING);
			else {
			list(, $this->name, $this->description, $this->priority) = $m;
			$this->priority = (int)$this->priority;
			}
		}

	function __toString(){
		return $this->name;
		}

	function Display(){
		return sprintf("%s#%d %s: \"%s\" prio=%d\n",
					$this->is_active?'>':' ', $this->id,
					$this->name, $this->description,
					$this->priority
					);
		}
	}

/** PulseAudio struct: Source port info
 */
class __PA_source_port_info_t extends __PA_sink_port_info_t { // NOTE: they have very much in common.
	}

/** PulseAudio struct: Card profile info
 */
class __PA_card_profile_info_t {
	/** Index of this profile
	 * @var int
	 */
	public $id;

	/** Name of this profile
	 * @var string
	 */
	public $name;

	/** Description of this profile
	 * @var string
	 */
	public $description;

	/** The higher this value is the more useful this profile is as a default
	 * @var int
	 */
	public $priority;

	/** Number of sinks this profile would create
	 * @var int
	 */
	public $n_sinks;

	/** Number of sources this profile would create
	 * @var int
	 */
	public $n_sources;

	/** Whether this profile is the 'Active Profile'
	 * @var bool
	 */
	public $is_active = false;

	function __construct($info){
		if (!preg_match('~^(.+): ([^:]+) \(sinks: (\d+), sources: (\d+), priority\. (\d+)\)$~', $info, $m))
			trigger_error("CardProfile: RegEx failed: '$info'", E_USER_WARNING);
			else {
			list(, $this->name, $this->description, $this->n_sinks, $this->n_sources, $this->priority) = $m;
			$this->priority = (int)$this->priority;
			$this->n_sinks = (int)$this->n_sinks;
			$this->n_sources = (int)$this->n_sources;
			}
		}

	function __toString(){
		return $this->name;
		}

	function Display(){
		return sprintf("%s#%d %s: \"%s\" prio=%d n_sinks=%d n_sources=%d\n",
					$this->is_active?'>':' ', $this->id,
					$this->name, $this->description,
					$this->priority, $this->n_sinks, $this->n_sources
					);
		}
	}

/** An array of entities, capable of searching with 'Name' and Index
 */
class __PAentities extends ArrayObject {
	private $_entname;

	/** Initialize
	 * @param $entity_name	Name of entities stored here
	 */
	function __construct($entity_name){
		$this->_entname = $entity_name;
		}

	/** Array( entity->id => [j] in $this
	 * @var int[]
	 */
	private $_byId = array();

	/** Array( entity->name => [j] in $this
	 * @var int[]
	 */
	private $_byName = array();

	/* === Get & Set entities by reference: Index | Name === */
	private function &__searchBy($ref){
		if (is_numeric($ref))
			return $this->_byId;
		return $this->_byName;
		}
	private function __find($ref, &$ids = null){
		$ids = $this->__searchBy($ref);
		if (!isset($ids[$ref]))
			throw new EPulseAudio('ent_404', array('ent' => $this->_entname, 'ref' => $ref));
		return $ids[$ref];
		}

	function offsetExists($i){
		$ids = $this->__searchBy($i);
		return isset($ids[$i]) && parent::offsetExists($ids[$i]);
		}
	function offsetGet($i){
		return parent::offsetGet($this->__find($i));
		}
	function offsetUnset($i){
		$j = $this->__find($i);
		parent::offsetUnset($j);
		unset(
				$this->_byId[array_search($j,$this->_byId)],
				$this->_byName[array_search($j,$this->_byName)]
				);
		}
	function offsetSet($i, $v){
		if (is_null($i))
			$j = count($this); // append
			else {
			if (isset($ids[$i])) // Replace
				$j = $ids[$i];
				else // Set
				$j = count($this);
			}
		$this->_byId[ $v->id ] = $j;
		$this->_byName[ $v->name ] = $j;
		parent::offsetSet($j, $v);
		}
	}
