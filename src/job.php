<?php

class Job {
	public $name, $type, $arch, $os, $mach;

	function __construct($name)
	{
		$this->name = $name;
	}

	// Is the job currently active?
	private function is_active_job($job, $mach = FALSE)
	{
		$machs = Machine::get_all();

		foreach ($machs as $m) {
			if ($m->job == "")
				continue;

			$j1 = explode(" ", $m->job);
			$j2 = explode(" ", $job);

			// Check format of job description
			if (count($j1) != 3 || count($j2) != 3)
				continue;

			$match = $j1[1] == $j2[1] && $j1[2] == $j2[2];

			if (!$match)
				continue;

			if ($mach === FALSE)
				return TRUE;
			else if ($mach->name == $m->name)
				return TRUE;
		}

		return FALSE;
	}

	private function wait_for_active_job($job, $mach = FALSE)
	{
		if (!IS_LOCKED())
			$this->fatal("Lock must be held in wait_for_active_job()");

		if ($this->is_active_job($job, $mach))
			$this->out("Waiting for running job: ".$job);

		while ($this->is_active_job($job, $mach))
			SLEEP_ON_LOCK(10);
	}

	private function preprocess($job, $arch, $os, $worker, $mach)
	{
		$job = str_replace("\$ARCH", $arch, $job);
		$job = str_replace("\$OS", $os, $job);
		$job = str_replace("\$JOB", $this->name, $job);
		$job = str_replace("\$MAMA_HOST", MAMA_HOST, $job);
		$job = str_replace("\$MAMA_PATH", MAMA_PATH, $job);
		$job = str_replace("\$MAMA_HOST", MAMA_HOST, $job);

		if ($worker !== FALSE)
			$job = str_replace("\$WORKER", $worker, $job);

		if ($mach !== FALSE)
			$job = str_replace("\$MACH", $mach->name, $job);

		$args = isset(Options::$options["args"]) ? explode(" ", Options::$options["args"]) : array();
		for ($i = 1; $i <= count($args); $i++)
			$job = str_replace("\$ARG".$i, $args[$i - 1], $job);

		$args_str = isset(Options::$options["args"]) ? Options::$options["args"] : "";
		$job = str_replace("\$ARGS", $args_str, $job);

		return $job;
	}

	public function execute_prepare_job()
	{
		$this->out("preparing job: ".$this->name." ".$this->arch." ".$this->os);
		$arch = $this->arch;
		$os = $this->os;
		$worker = $this->mach;

		$job = file_get_contents(MAMA_PATH."/jobs/".$this->name."/prepare.job");
		if ($job === FALSE)
			$this->fatal("Prepare job not found");

		LOCK();

		$job_str = "prepare ".$this->name." ".$arch."/".$os;

		// Wait for all machines running this job to finish
		$this->wait_for_active_job($job_str);

		// Wait for the worker to finish any running jobs
		$worker_mach = select_machine($worker);
		if (!$worker_mach->is_idle())
			$this->out("Waiting for job to finish: ".$worker_mach->job);

		while (!$worker_mach->is_idle()) {
			UNLOCK();
			sleep(10);
			LOCK();
			$worker_mach->load();
			// Check for stale jobs while waiting
			$worker_mach->detect_and_clear_stale_job();
		}

		$prev_job = $worker_mach->job; // Save old job name in case of nested jobs
		$prev_job_pid = $worker_mach->job_pid;
		$worker_mach->job = $job_str;
		$worker_mach->job_pid = getmypid();
		$worker_mach->save();

		UNLOCK();

		$job = $this->preprocess($job, $arch, $os, $worker, FALSE);

		$ret = $this->execute($job, FALSE, $worker_mach);
		$worker_mach->stop();

		LOCK();

		$worker_mach->load();
		$worker_mach->job = $prev_job;
		$worker_mach->job_pid = $prev_job_pid;
		$worker_mach->save();

		UNLOCK();

		if ($ret == FALSE)
			$this->fatal("Prepare job FAILED");
		return $ret;
	}

	public function execute_run_job()
	{
		$this->out("running job: ".$this->name." ".$this->arch." ".$this->os);
		$mach = $this->mach;
		$arch = $this->arch;
		$os = $this->os;
		$job = file_get_contents(MAMA_PATH."/jobs/".$this->name."/run.job");
		if ($job === FALSE)
			return FALSE;

		LOCK();
		$mach = select_machine($mach);

		if ($mach === FALSE) {
			$this->error("Failed to find machine");
			UNLOCK();
			return FALSE;
		}

		if (!$mach->is_idle())
			$this->out("Waiting for job to finish: ".$mach->job);

		while (!$mach->is_idle()) {
			SLEEP_ON_LOCK(10);
			$mach->load();
			// Check for stale jobs while waiting
			$mach->detect_and_clear_stale_job();
		}

		$prev_job = $mach->job;
		$prev_job_pid = $mach->job_pid;
		$mach->job = "run ".$this->name." ".$arch."/".$os;
		$mach->job_pid = getmypid();
		$mach->save();
		UNLOCK();

		$job = $this->preprocess($job, $arch, $os, FALSE, $mach);

		$ret = $this->execute($job, $mach);

		LOCK();
		$mach->stop();
		$mach->load();
		$mach->job = $prev_job;
		$mach->job_pid = $prev_job_pid;
		$mach->save();
		UNLOCK();

		return $ret;
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
		$no_retry = isset(Options::$options["no-retry"]) ? TRUE : FALSE;

		$SSH_RET = 0; // Stores the return code from the last executed command
		$error = FALSE;
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

				$this->debug("Switching to context: ".$context." ".($context == "VM" ? $vm_mach->name : ""));

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

					if ($context == "DEVICE")
						$m = $mach;
					else if ($context == "WORKER")
						$m = $worker;

					if ($context == "DEVICE" || $context == "WORKER") {
						// Make sure the device is stopped before starting
						if (!$m->stop())
							return FALSE;

						if ($m->is_only_vm()) {
							if (!$m->start_vm())
								return FALSE;
						} else {
							// Retry starting 3 times
							$num_retries = 3;

							if ($no_retry) {
								$num_retries = 1;
							}
							for ($i = 0; $i < $num_retries; $i++) {
								if ($m->start())
									break;
								$m->debug("Retrying to start machine: ".$i);
								sleep(2);
							}
							if ($i == $num_retries) {
								$m->error("Failed to start machine");
								return FALSE;
							}
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
				$this->debug("(mama) ".$line);
				$res = NULL;
				$log_str = Log::$logfile !== FALSE ? " &>> ".Log::$logfile : "";
				passthru($line." ".$log_str, $res);

				if ($res != 0) {
					$this->error("EXECUTION FAILED: ".$line);
					$error = TRUE;
					break;
				}
			}

			// Replace any $RET with the last return code
			$line = str_replace("\$RET", $SSH_RET, $line);

			if ($context == "DEVICE") {
				$res = $mach->ssh_cmd($line);
				$mach_for_log = $mach;
			} else if ($context == "WORKER") {
				$res = $worker->ssh_cmd($line);
				$mach_for_log = $worker;
			}

			$SSH_RET = $res; // Store the return code for insertion into next command

			if ($res != 0) {
				if ($res == 124)
					$mach_for_log->error("EXECUTION TIMEOUT: ".$line);
				else
					$mach_for_log->error("EXECUTION FAILED: ".$line);
				$error = TRUE;
				break;
			}
		}

		$this->out("Job finished");

		if ($mach !== FALSE)
			$mach->stop();
		if ($worker !== FALSE)
			$worker->stop();

		return ($error === FALSE);
	}

	function out($msg, $no_eol = FALSE, $timestamp = TRUE)
	{
		$mach = select_machine($this->mach);
		if ($mach === FALSE)
			out($msg, $no_eol, $timestamp);
		else
			$mach->out($msg, $no_eol, $timestamp);
	}

	function debug($msg, $no_eol = FALSE, $timestamp = TRUE)
	{
		$mach = select_machine($this->mach);
		if ($mach === FALSE)
			debug($msg, $no_eol, $timestamp);
		else
			$mach->debug($msg, $no_eol, $timestamp);
	}

	function error($msg, $no_eol = FALSE, $timestamp = TRUE)
	{
		$mach = select_machine($this->mach);
		if ($mach === FALSE)
			error($msg, $no_eol, $timestamp);
		else
			$mach->error($msg, $no_eol, $timestamp);
	}

	function fatal($msg, $errno = 1)
	{
		$mach = select_machine($this->mach);
		if ($mach === FALSE)
			fatal($msg, $errno);
		else
			$mach->fatal($msg, $errno);
	}
}

?>
