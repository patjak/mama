<?php

class Settings {
	private static $is_locked = FALSE, $stream;
	public static $settings;

	// Aquire exclusive lock on the settings file
	public static function lock()
	{
		if (self::$is_locked)
			fatal("mama.xml is already locked");

		debug("Aquiring lock");
		self::$stream = fopen(SETTINGS_FILE, "r");

		if (flock(self::$stream, LOCK_EX) === FALSE) {
			error("Failed to acquire read lock on mama.xml");
			fclose(self::$stream);

			return FALSE;
		}

		self::$is_locked = TRUE;

		return TRUE;
	}

	// Release exclusive lock on the settings file
	public static function unlock()
	{
		if (!self::$is_locked)
			fatal("mama.xml is not locked");

		debug("Releasing lock");
		fclose(self::$stream);

		self::$is_locked = FALSE;
	}

	// Implement our own file_get_contents() because we need locking
	public static function get_contents()
	{
		$stream = fopen(SETTINGS_FILE, "r");
		if ($stream === FALSE) {
			error("Failed to open mama.xml");
			return;
		}

		// We must clear the stat cache or the filesize gets wrong
		clearstatcache(TRUE, SETTINGS_FILE);

		// Sometimes the file fails to read and returns empty
		// Retry a couple of times
		for ($i = 0; $i < 5; $i++) {
			$str = fread($stream, filesize(SETTINGS_FILE));

			if ($str === FALSE)
				error("Failed to read mama.xml. Retrying: ".$i);
			else
				break;

			sleep(1);
		}

		if (strlen($str) != filesize(SETTINGS_FILE)) {
			error("Size mismatch: ".strlen($str)." != ".filesize(SETTINGS_FILE));
			fatal("Failed to read the entire file");
		}

		fclose($stream);

		if (strpos($str, "</settings>") === FALSE) {
			error("Invalid mama.xml");
			var_dump($str);
			exit(1);
		}

		return $str;
	}

	public static function put_contents($str)
	{
		// Protect from accidentaly wiping the file
		if ($str == "") {
			error("FATAL: Trying to write an empty mama.xml");
			return FALSE;
		}

		$stream = fopen(SETTINGS_FILE, "w");
		if ($stream === FALSE) {
			error("Failed to open mama.xml");
			return FALSE;
		}

		$len = fwrite($stream, $str);
		if ($len === FALSE) {
			error("Failed to write mama.xml");
			fclose($stream);
			return FALSE;
		}

		if ($len != strlen($str)) {
			error("Not all content got written to mama.xml");
			return FALSE;
		}

		fclose($stream);

		return $str;

	}

	public static function load()
	{
		if (!file_exists(SETTINGS_FILE))
			return FALSE;

		$str = self::get_contents();

		if (strlen($str) == 0 || $str === FALSE)
			return FALSE;

		self::$settings = simplexml_load_string($str);

		return self::$settings;
	}

	// To get nice indentation we convert to DOM before saving
	private static function make_xml_great_again($xml)
	{
		$dom = new DOMDocument("1.0");
		$dom->preserveWhiteSpace = false;
		$dom->formatOutput = true;

		$dom->loadXML($xml);

		return($dom->saveXML());
	}

	public static function save()
	{
		$xml = Settings::make_xml_great_again(self::$settings->asXML());

		if (self::put_contents($xml) === FALSE) {
			error("Unable to save settings file: ".SETTINGS_FILE);
			exit(1);
		}
	}

	// Returns the index of the machine in the xml
	private static function get_machine_index($mach)
	{
		$i = 0;
		foreach (Settings::$settings->machine as $m) {
			if ($m->name == $mach->name) {
				return $i;
			}
			$i++;
		}

		return false;
	}

	// Returns the index of the resource in the xml
	private static function get_resource_index($res)
	{
		$i = 0;
		foreach (Settings::$settings->resource as $r) {
			if ($r->name == $res->name) {
				return $i;
			}
			$i++;
		}

		return false;
	}

	public static function delete_machine($mach)
	{
		self::lock();
		Settings::load();

		$i = Settings::get_machine_index($mach);
		unset(Settings::$settings->machine[$i]);

		Settings::save();
		self::unlock();
	}

	public static function update_machine($mach)
	{
		$i = Settings::get_machine_index($mach);
		Settings::delete_machine($mach);
		Settings::add_machine($mach);
	}

	public static function add_machine($mach)
	{
		self::lock();
		Settings::load();

		$m = Settings::$settings->addChild("machine");
		$m->addChild("name", $mach->name);
		$m->addChild("mac", $mach->mac);
		$m->addChild("ip", $mach->ip);
		$m->addChild("is_started", $mach->is_started);
		$m->addChild("only_vm", $mach->only_vm);
		$m->addChild("arch", $mach->arch);
		$m->addChild("os", $mach->os);
		$m->addChild("kernel", $mach->kernel);
		$m->addChild("pwr_dev", $mach->pwr_dev);
		$m->addChild("pwr_slot", $mach->pwr_slot);
		$m->addChild("rly_dev", $mach->rly_dev);
		$m->addChild("rly_slot", $mach->rly_slot);
		$m->addChild("reservation", $mach->reservation);
		$m->addChild("resources", $mach->resources);
		$m->addChild("boot_params", $mach->boot_params);

		Settings::save();
		self::unlock();
	}
}

?>
