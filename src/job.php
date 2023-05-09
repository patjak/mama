<?php

class Job {
	public $name;

	function __construct($name)
	{
		$this->name = $name;
	}

	public function execute_prepare_job($arch, $os)
	{
		$job = file_get_contents(MAMA_PATH."/jobs/".$this->name."/prepare.job");
		if ($job === FALSE)
			return FALSE;

		$job = str_replace("\$ARCH", $arch, $job);
		$job = str_replace("\$OS", $os, $job);
		$job = str_replace("\$JOB", $this->name, $job);

		return $this->execute($job);
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

	public function execute($job, $mach = FALSE)
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
				$old_vm_mach = $vm_mach;

				if ($line == "MAMA") {
					$context = "MAMA";
				} else if ($line == "DEVICE") {
					$context = "DEVICE";
				} else if ($this->parse_to_next(" ", $line) == "VM") {
					$context = "VM";

					if ($line != "") {
						$vm_mach = select_machine($line);
						if ($vm_mach === FALSE) {
							error("Failed to find machine: ".$vm_name);
							return FALSE;
						}
					} else {
						$vm_mach = $mach;
					}
				}

				out("Switching to context: ".$context." ".($context == "VM" ? $vm_mach->name : ""));

				// When switching between VMs and DEVICEs we must also start and stop the machines
				if ($old_context != $context) {
					if ($old_context == "DEVICE") {
						if (!$mach->stop())
							return FALSE;
					}

					if ($old_context == "VM") {
						if (!$vm_mach->stop())
							return FALSE;
					}

					if ($context == "VM") {
						if ($vm_mach == FALSE) {
							out("No machine specified. A VM name must be provided in a prepare job");
							return FALSE;
						}

						// Make sure the VM is stopped before starting
						if (!$vm_mach->stop())
							return FALSE;

						if (!$vm_mach->start_vm())
							return FALSE;
					}

					if ($context == "DEVICE") {
						// Make sure the device is stopped before starting
						if (!$mach->stop())
							return FALSE;

						if (!$mach->start())
							return FALSE;
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

			if ($context == "VM") {
				$res = $vm_mach->ssh_cmd($line);
				if ($res != 0) {
					if ($res == 124)
						error("EXECUTION TIMEOUT: ".$line);
					else
						error("EXECUTION FAILED: ".$line);
					break;
				}
			}

			if ($context == "DEVICE") {
				$res = $mach->ssh_cmd($line);
				if ($res != 0) {
					if ($res == 124)
						error("EXECUTION TIMEOUT: ".$line);
					else
						error("EXECUTION FAILED: ".$line);
					break;
				}
			}
		}

		out("Execution finished. Turning off machines");

		if ($mach !== FALSE)
			$mach->stop();
		if ($vm_mach !== FALSE)
			$vm_mach->stop();

		return TRUE;
	}
}

?>
