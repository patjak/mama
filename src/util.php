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
		out($prompt, TRUE);
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

	public static function ask_yesno($str)
	{
		$yesno = " ";
		while ($yesno != "y" && $yesno != "n" && $yesno != "") {
			$yesno = Util::get_line($str." (y/N): ");
			$yesno = trim(strtolower($yesno));
		}

		return $yesno;
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

	public static function get_directory_contents($path, $depth)
	{
		$path .= "/";
		$res = shell_exec("find ".$path." -maxdepth ".$depth." 2> /dev/null");
		$rows = explode(PHP_EOL, $res);
		array_shift($rows);

		$oses = array();

		foreach ($rows as $row) {
			$row = substr($row, strlen($path));
			$os = $row;

			$row = explode("/", $row);

			if (count($row) != $depth)
				continue;

			$oses[] = $os;
		}

		return $oses;
	}

	// Returns the remains of the string after first occurance of token
	public static function parse_after($token, $str) {
		if (strpos($str, $token) === FALSE)
			return FALSE;

		$remains = explode($token, $str);
		if (count($remains) > 1) {
			array_shift($remains);
			return implode($token, $remains);
		}

		return "";
	}

	// Returns string leading up to first occurance of token
	public static function parse_before($token, $str) {
		if (strpos($str, $token) === FALSE)
			return FALSE;

		$before = explode($token, $str);

		if (count($before) > 1)
			return $before[0];
		else
			return "";
	}

	// Here are some colors what work on my terminal
	static $colors = array(31, 32, 33, 34, 35, 36, 37, 90, 91, 92, 93, 94, 95, 96);

	// Returns a string in a pseudo random color
	public static function string_to_rand_color($str) {
		srand($str);
		$color = self::$colors[rand(0, count(self::$colors) - 1)];
		return "\e[".$color."m".$str."\e[0m";
	}

	public static function string_to_color($str, $color) {
		$color = $color % (count(self::$colors) - 1);
		$color = self::$colors[$color];

		return "\e[".$color."m".$str."\e[0m";
	}
};

?>
