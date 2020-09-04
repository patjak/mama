<?php

class Util {
	public static function pad_str($str, $len)
	{
		$align = "";

		for ($i = 0; $i < $len; $i++)
			$align .= " ";

		return (substr($str.$align, 0, $len - 1)." ");
	}

	public static function get_line($prompt)
	{
		echo $prompt;
		return stream_get_line(STDIN, 1024, PHP_EOL);
	}

	public static function ask_from_array($array, $str, $return_num = false)
	{
		$entry = "";
		while ($entry == "") {
			$no = (int) Util::get_line($str." (1-".count($array)."): ");
			if (isset($array[$no - 1]))
				$entry = $array[$no - 1];
		}

		if ($return_num == true)
			return $no;
		else
			return $entry;
	}

	public static function ask_number($max, $str)
	{
		$no = 0;
		while ($no == 0 || $no > $max) {
			$no = (int) Util::get_line($str." (1-".$max."): ");
		}

		return $no;
	}

	public static function is_valid_mac($mac)
	{
		return (preg_match('/([a-fA-F0-9]{2}[:|\-]?){6}/', $mac) == 1);
	}

	public static function is_valid_ip($ip)
	{
		return filter_var($ip, FILTER_VALIDATE_IP);
	}

	public static function is_root()
	{
		$res = shell_exec("whoami");
		if (strncmp($res, "root", 4))
			return false;

		return true;
	}
};

?>
