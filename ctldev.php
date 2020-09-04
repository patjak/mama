<?php

// Describes Power and Relay control devices
class CtlDev {
	public $name, $slots, $ident;

	public static $devs = array();

	function __construct($name, $slots, $ident)
	{
		$this->name = $name;
		$this->slots = $slots;
		$this->ident = $ident;
		self::$devs[] = $this;
	}

	public static function get_by_ident($ident)
	{
		foreach (self::$devs as $dev) {
			if ($dev->ident == $ident)
				return $dev;
		}

		return false;
	}

	public static function get_by_name($name)
	{
		foreach (self::$devs as $dev) {
			if ($dev->name == $name)
				return $dev;
		}

		return false;
	}

	public function set_power($slot, $power) { return; }
	public function push($slot, $sec) { return; }
	public function get_sensor($slot, $key) { return array(); }
	public function get_sensors($slot) { return; }
}

?>
