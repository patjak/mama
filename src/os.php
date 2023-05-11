<?php

class Os {
	static function is_buildable($arch, $os)
	{
		$path = MAMA_PATH."/os-descriptions/";
		$oses = Util::get_directory_contents($path, 2);

		return in_array($arch."/".$os, $oses);
	}

	static function is_installable($arch, $os)
	{
		$path = MAMA_PATH."/os/";
		$oses = Util::get_directory_contents($path, 2);

		return in_array($arch."/".$os, $oses);
	}

	static function is_runnable($arch, $os, $mach)
	{
		$path = MAMA_PATH."/machines/".$mach->name."/";
		$oses = Util::get_directory_contents($path, 2);

		return in_array($arch."/".$os, $oses);
	}
}

class Arch {
	static function is_buildable($arch)
	{
		$path = MAMA_PATH."/os-descriptions/";
		$archs = Util::get_directory_contents($path, 1);

		return in_array($arch, $archs);
	}

	static function is_installable($arch)
	{
		$path = MAMA_PATH."/os/";
		$archs = Util::get_directory_contents($path, 1);

		return in_array($arch, $archs);
	}

	static function is_runnable($arch, $mach)
	{
		$path = MAMA_PATH."/machines/".$mach->name."/";
		$archs = Util::get_directory_contents($path, 1);

		return in_array($arch, $archs);
	}

	// Returns an array of available archs from kiwi-descriptions
	static function get_buildable()
	{
		$archs = array();
		$path = MAMA_PATH."/os-descriptions/";
		$oses = Util::get_directory_contents($path, 2);

		foreach ($oses as $os) {
			$arch = explode("/", $os);
			$arch = $arch[0];
			if (!in_array($arch, $archs))
				$archs[] = $arch;
		}

		return $archs;
	}
}

?>
