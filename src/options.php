<?php

class Options {
	static	$options = array();

	// Since the argument parsing in PHP is garbage we must write our own
	// We save everything that doesn't get parsed and return it (commands, etc)

	public static function parse($args, $opts) {
		$num = count($args);
		for ($i = 1; $i < $num; $i++) {
			$arg = $args[$i];

			if (strpos($arg, "--") !== 0)
				continue;

			foreach ($opts as $opt) {
				if ($opt[-1] == ":") {
					// Parse options that have a parameter

					$opt = substr($opt, 0, -1);
					$val = Util::parse_after("--".$opt, $arg);

					if ($val === FALSE)
						continue;

					if ($val == "")  {
						self::$options[$opt] = $args[$i + 1];
						unset($args[$i]);
						unset($args[$i + 1]);
						$i++;
						break;
					}
					if ($val[0] == "=") {
						$val = substr($val, 1);
						self::$options[$opt] = $val;
						unset($args[$i]);
						break;
					}
				} else {
					// Parse options without parameters

					if (strcmp($arg, "--".$opt))
						continue;

					self::$options[$opt] = TRUE;
					unset($args[$i]);
				}
			}
		}

		// Re-index and return what is left
		return array_values($args);
	}

	/**
	 * Read an option provided by the user
	 *
	 * @param $opt	Name of the option to retrieve
	 * @param $required The option must be found or a fatal error occurs
	 */
	public static function get($opt, $required = TRUE) {

		// Check the command line
		if (isset(self::$options[$opt])) {
			$val = self::$options[$opt];
			debug("Option from command line: ".$opt." = ".$val);
			return $val;
		}

		if ($required)
			fatal("Couldn't get required option: ".$opt);

		return FALSE;
	}
};

?>
