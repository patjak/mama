<?php

class Job {
	public $name;

	function __construct($name)
	{
		$this->name = $name;
	}

	public function execute_prepare_job($arch, $os, $worker)
	{
		$job = file_get_contents(MAMA_PATH."/jobs/".$this->name."/prepare.job");
		if ($job === FALSE)
			return FALSE;

		$job = str_replace("\$ARCH", $arch, $job);
		$job = str_replace("\$OS", $os, $job);
		$job = str_replace("\$JOB", $this->name, $job);
		$job = str_replace("\$MAMA_HOST", MAMA_HOST, $job);
		$job = str_replace("\$WORKER", $worker, $job);

		$worker_mach = select_machine($worker);

		return $this->execute($job, FALSE, $worker_mach);
	}

	public function execute_run_job($mach, $arch, $os)
	{
		$mach = select_machine($mach);

		if ($mach === false) {
			error("Failed to find machine");
			return FALSE;
		}

		$job = file_get_contents(MAMA_PATH."/jobs/".$this->name."/run.job");
		if ($job === FALSE)
			return FALSE;

		$job = str_replace("\$ARCH", $arch, $job);
		$job = str_replace("\$OS", $os, $job);
		$job = str_replace("\$JOB", $this->name, $job);
		$job = str_replace("\$MACH", $mach->name, $job);
		$job = str_replace("\$MAMA_PATH", MAMA_PATH, $job);
		$job = str_replace("\$MAMA_HOST", MAMA_HOST, $job);

		return $this->execute($job, $mach);
	}

	private function parse_to_next($separator, &$str) {
		$str = explode($separator, $str);
		$ret = $str[0];
		unset($str[0]);
		$str = implode($separator, $str);

		return $ret;
	}

	private function parse_after_next($separator, &$str) {
		parse_to_next($separator, $str);

		return $str;
	}

	public function execute($job, $mach = FALSE, $worker = FALSE)
	{
		$context = "";
		$vm_mach = FALSE;
		$lines = explode(PHP_EOL, $job);

		foreach ($lines as $line) {
			$line = trim($line);
			if (!strncmp("[", $line, 1)) {
				$this->parse_to_next("[", $line);
				$line = $this->parse_to_next("]", $line);

				$old_context = $context;

				if ($line == "MAMA")
					$context = "MAMA";
				else if ($line == "DEVICE")
					$context = "DEVICE";
				else if ($line == "WORKER")
					$context = "WORKER";

				out("Switching to context: ".$context." ".($context == "VM" ? $vm_mach->name : ""));

				// When switching between WORKERs and DEVICEs we must also start and stop the machines
				if ($old_context != $context) {
					if ($old_context == "DEVICE") {
						if (!$mach->stop())
							return FALSE;
					}

					if ($old_context == "WORKER") {
						if (!$worker->stop())
							return FALSE;
					}

					if ($context == "DEVICE") {
						// Make sure the device is stopped before starting
						if (!$mach->stop())
							return FALSE;

						if ($mach->is_only_vm()) {
							if (!$mach->start_vm())
								return FALSE;
						} else {
							if (!$mach->start())
								return FALSE;
						}

					}

					if ($context == "WORKER") {
						if (!$worker->stop())
							return FALSE;

						if ($worker->is_only_vm()) {
							if (!$worker->start_vm())
								return FALSE;
						} else {
							if (!$worker->start())
								return FALSE;
						}
					}
				}

				continue;
			}

			if (!strncmp("#", $line, 1))
				continue;

			if ($line == "")
				continue;

			if ($context == "MAMA") {
				out("EXECUTING: ".$line);
				$res = NULL;
				$log_str = Log::$logfile !== FALSE ? " &>> ".Log::$logfile : "";
				passthru($line." ".$log_str, $res);

				if ($res != 0) {
					error("EXECUTION FAILED: ".$line);
					break;
				}
			}

			if ($context == "DEVICE")
				$res = $mach->ssh_cmd($line);
			else if ($context == "WORKER")
				$res = $worker->ssh_cmd($line);

			if ($res != 0) {
				if ($res == 124)
					error("EXECUTION TIMEOUT: ".$line);
				else
					error("EXECUTION FAILED: ".$line);
				break;
			}
		}

		out("Execution finished. Turning off machines");

		if ($mach !== FALSE)
			$mach->stop();
		if ($worker !== FALSE)
			$worker->stop();

		return TRUE;
	}
}

?>
