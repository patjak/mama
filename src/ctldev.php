<?php

// Describes Power and Relay control devices
class CtlDev {
	public $name, $slots, $private_data;

	public static $devs = array();

	function __construct($name, $slots, $private_data)
	{
		$this->name = $name;
		$this->slots = $slots;
		$this->private_data = $private_data;
		self::$devs[] = $this;
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
	public function get_sensors_all_slots() { return; }
}

?>
