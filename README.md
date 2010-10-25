Patricks
========

Patricks is a PHP script that parses `pacmd` output to get the list of PulseAudio entities and allows you to control the running daemon.

Installation
------------

Make sure you have PHP >=5.3 installed.
Place the script's files somewhere and make a symlink into your $PATH.

Usage
-----

See 'patricks' output to get the list of available commands.

### Specifying entities
Each entity reference consists of two parts:

    <entity_t> <entity-ref>

entity_t — Is the name of the entity: sink, input, source, output, card, module, client, sample.
entity-ref — Is either an index or a Name

### Discovering entities

    $ patricks ls
    Module#23 <module-cli-protocol-unix>
    >Sink#0 <alsa_output.pci-0000_00_1b.0.analog-stereo>
     Source#0 <alsa_output.pci-0000_00_1b.0.analog-stereo.monitor>

Will list all the available entities. The index comes first, the name comes in angular brackets.

Some entities do not have a name: Clients, Inputs & Outputs: you have to specify them by their index.
In order to ease the pain of distinguishing them, the 'application.name' property is displayed next to them.

### Shortening

Most of the literals listed in the help can be shortened.
For instance,

    $ patricks ls sink 0 properties

can be given as

    $ patricks ls si 0 pr

unless the literal is ambiguous.

### The 'current entity'
Some entities can be selected in either way: e.g. a Sink can be the 'default-sink', a Sink's Port can be the 'active port'.
Such entities are prefixed with '>' in listings:

    $ patricks ls si 0 po
    >#0 analog-output: "Analog Output" prio=9900
     #1 analog-output-headphones: "Analog Headphones" prio=9000

PHP API
-------

Patricks comes with 'PulseAudio.API.php': an object-oriented API that allows you to get the fields & properties of all PulseAudio entities.
It works on top of `pactl list` and `pactl stat`, parses their output and provides access to all parsed entities.
