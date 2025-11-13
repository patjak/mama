<?php

$i = 0;
define("LOG_LVL_FATAL", $i++);
define("LOG_LVL_ERROR", $i++);
define("LOG_LVL_DEFAULT", $i++);
define("LOG_LVL_DEBUG", $i++);
define("LOG_LVL_LOG", $i++); // Only write to log


class Log {
	static $level = LOG_LVL_DEFAULT;
	static $stdout = FALSE;
	static $stderr = FALSE;
	static $logfile = FALSE;
	static $pause = FALSE;

	public function init()
	{
		if (self::$stdout === FALSE)
			self::$stdout = fopen("php://stdout", "rw");

		if (self::$stderr === FALSE)
			self::$stderr = fopen("php://stderr", "rw");

		if (self::$stdout === FALSE || self::$stderr === FALSE)
			echo "Failed to open stdout and stderr\n";
	}

	// Temporarily pause logging
	public static function pause()
	{
		self::$pause = TRUE;
	}

	// Resume normal logging
	public static function resume()
	{
		self::$pause = FALSE;
	}

	public static function set_level($level)
	{
		self::$level = $level;
	}

	public static function print_msg($msg, $level, $timestamp = TRUE)
	{
		date_default_timezone_set('Europe/Stockholm');

		if ($level == LOG_LVL_ERROR)
			fwrite(STDERR, $msg);
		else if ($level != LOG_LVL_LOG)
			fwrite(STDOUT, $msg);

		if (self::$logfile !== FALSE && self::$pause !== TRUE) {
			if ($timestamp)
				$date = "[".date("Y-m-d H:i:s")."]";
			else
				$date = "";

			unset($code);
			if (!file_exists(self::$logfile)) {
				passthru("touch ".self::$logfile." && chgrp mama ".self::$logfile." && chmod 660 ".self::$logfile, $code);
				if ($code != 0)
					fatal("Failed to create log file: ".self::$logfile);
			}

			$stream = fopen(self::$logfile, "a");
			if ($stream === FALSE) {
				error("Failed to open log: ".self::$logfile);
				return;
			}

			if (flock($stream, LOCK_EX))
				fwrite($stream, $date."\t".$msg);
			else
				error("Failed to write to log: ".self::$logfile);
			fclose($stream);
		}
	}

	public static function set_file($filename)
	{
		if ($filename === FALSE) {
			self::$logfile = FALSE;
			return;
		}

		self::$logfile = MAMA_PATH."/log/".$filename;
	}
}

function out($msg, $no_eol = FALSE, $timestamp = TRUE)
{
	static $last_no_eol = FALSE;

	if ($no_eol == FALSE)
		$msg .= "\n";

	// Only add PID if we're starting a new line
	if (DEBUG_PID === TRUE && !$last_no_eol)
		$msg = "\r".Util::string_to_rand_color(getmypid())." ".$msg;

	Log::print_msg($msg, LOG_LVL_DEFAULT, $timestamp);

	$last_no_eol = $no_eol;

	return strlen($msg);
}

function error($msg, $no_eol = FALSE, $timestamp = TRUE)
{
	if ($no_eol == FALSE)
		$msg .= "\n";

	Log::print_msg($msg, LOG_LVL_ERROR, $timestamp);

	return strlen($msg);
}

function fatal($msg, $errno = 1)
{
	Log::print_msg("FATAL ERROR: ".$msg."\n", LOG_LVL_ERROR);
	debug_print_backtrace();

	exit($errno);
}

function debug($msg, $no_eol = FALSE, $timestamp = FALSE)
{
	if ($no_eol == FALSE)
		$msg .= "\n";

	if (DEBUG)
		Log::print_msg($msg, LOG_LVL_DEBUG, $timestamp);

	return strlen($msg);
}

?>
