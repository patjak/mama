<?php

function LOCK() { Settings::lock(); }
function UNLOCK() { Settings::unlock(); }
function IS_LOCKED() { return Settings::is_lock_held(); }
function SLEEP_ON_LOCK($seconds)
{
	UNLOCK();

	if (IS_LOCKED())
		fatal("Sleeping while lock is held");

	sleep($seconds);

	LOCK();
}

class Settings {
	private static $lock = 0, $stream = FALSE;
	public static $settings;

	// Aquire exclusive lock on the settings file
	public static function lock()
	{
		if (self::$lock == 0) {
			if (self::$stream !== FALSE)
				fatal("Tried to aquire an already aquired lock");

			self::$stream = fopen(SETTINGS_FILE, "r+");
			if (self::$stream === FALSE)
				fatal("Failed to open mama.xml");

			if (flock(self::$stream, LOCK_EX) === FALSE)
				fatal("Failed to acquire read lock on mama.xml");
		}

		self::$lock++;
		if (DEBUG_LOCK)
			out("Aquiring lock: ".self::$lock);

		return TRUE;
	}

	// Release exclusive lock on the settings file
	public static function unlock()
	{
		if (self::$lock == 0)
			fatal("mama.xml is not locked");

		self::$lock--;
		if (DEBUG_LOCK)
			out("Releasing lock: ".self::$lock);

		if (self::$lock == 0) {
			fclose(self::$stream);
			self::$stream = FALSE;
		}
	}

	public static function is_lock_held()
	{
		return (self::$lock != 0);
	}

	// Implement our own file_get_contents() because we need locking
	public static function get_contents()
	{
		self::lock();

		// We must clear the stat cache or the filesize gets wrong
		clearstatcache(TRUE, SETTINGS_FILE);

		$filesize = filesize(SETTINGS_FILE);
		if (rewind(self::$stream) !== TRUE)
			fatal("Failed to rewind file");

		$str = fread(self::$stream, $filesize);
		self::unlock();

		if ($str === FALSE)
			fatal("Failed to read mama.xml");

		if (strlen($str) != $filesize)
			fatal("Size mismatch: ".strlen($str)." != ".filesize(SETTINGS_FILE));

		// Check that we truly got the entire file
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
		if ($str == "")
			fatal("FATAL: Trying to write an empty mama.xml");

		self::lock();
		if (ftruncate(self::$stream, 0) !== TRUE)
			fatal("Failed to truncate file");

		if (rewind(self::$stream) !== TRUE)
			fatal("Failed to rewind file");

		$len = fwrite(self::$stream, $str);
		self::unlock();

		if ($len === FALSE)
			fatal("Failed to write mama.xml");

		if ($len != strlen($str))
			fatal("Not all content got written to mama.xml");

		return $str;
	}

	public static function load()
	{
		if (!file_exists(SETTINGS_FILE)) {
			passthru("cp ".MAMA_PATH."/mama.xml-default ".SETTINGS_FILE, $code);
			if ($code != 0)
				fatal("Failed to install the mama.xml file");

			passthru("chgrp mama ".SETTINGS_FILE, $code);
			if ($code != 0)
				fatal("Failed to set permission on mama.xml file");

			passthru("chmod 660 ".SETTINGS_FILE, $code);
			if ($code != 0)
				fatal("Failed to set permission on mama.xml file");
		}

		if (!file_exists(SETTINGS_FILE))
			fatal("No mama.xml file found");

		$str = self::get_contents();

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

		if (self::put_contents($xml) === FALSE)
			fatal("Unable to save settings file: ".SETTINGS_FILE);
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
		self::lock();
		$i = Settings::get_machine_index($mach);
		Settings::delete_machine($mach);
		Settings::add_machine($mach);
		self::unlock();
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
		$m->addChild("job", $mach->job);
		$m->addChild("startcmd", $mach->startcmd);
		$m->addChild("stopcmd", $mach->stopcmd);

		Settings::save();
		self::unlock();
	}
}

?>
