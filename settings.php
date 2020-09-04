<?php

class Settings {
	public static $settings;

	public static function load()
	{
		if (!file_exists(SETTINGS_FILE))
			return false;

		self::$settings = simplexml_load_file(SETTINGS_FILE);

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

		if (file_put_contents(SETTINGS_FILE, $xml) === false) {
			echo "Unable to save settings file: ".SETTINGS_FILE."\n";
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

	public static function delete_machine($mach)
	{
		$i = Settings::get_machine_index($mach);
		unset(Settings::$settings->machine[$i]);
		Settings::save();
	}

	public static function update_machine($mach)
	{
		$i = Settings::get_machine_index($mach);
		Settings::delete_machine($mach);
		Settings::add_machine($mach);
	}

	public static function add_machine($mach)
	{
		$m = Settings::$settings->addChild("machine");
		$m->addChild("name", $mach->name);
		$m->addChild("mac", $mach->mac);
		$m->addChild("arch", $mach->arch);
		$m->addChild("os", $mach->os);
		$m->addChild("kernel", $mach->kernel);
		$m->addChild("state", $mach->state);
		$m->addChild("pwr_dev", $mach->pwr_dev);
		$m->addChild("pwr_slot", $mach->pwr_slot);
		$m->addChild("rly_dev", $mach->rly_dev);
		$m->addChild("rly_slot", $mach->rly_slot);
		Settings::save();
	}

	public static function get_path()
	{
		return Settings::$settings->path[0];
	}
}

?>
