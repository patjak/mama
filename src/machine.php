<?php

class Machine {
	public $name, $mac, $ip, $is_started, $arch, $os, $kernel, $pwr_dev, $pwr_slot,
	       $rly_dev, $rly_slot, $reservation, $resources, $boot_params, $vm_params, $only_vm, $job, $startcmd, $stopcmd;

	private $lock = 0, $lock_stream = FALSE;

	// Tunables for how to manage machines
	public static
		// How long to wait for a machine to boot before giving up (in seconds)
		$start_timeout = 3 * 60,

		// How long to wait for a machine to stop before giving up (in seconds)
		$stop_timeout = 30,

		// How long an ssh command is allowed to execute (in minutes)
		// Can be overridden with --no-ssh-timeout
		$ssh_cmd_timeout = 1 * 60,

		// How long power btn must be pressed to force power off
		$rly_force_off_delay = 8,

		// How long a machine must be powered off before being powered on again (in seconds)
		$power_cycle_delay = 30,

		// How long to wait for a resource to become available (in seconds)
		$resource_wait_timeout = 20 * 60 * 60;

	// Aquire exclusive lock on the machine directory
	public function lock()
	{
		if (DEBUG_LOCK) {
			$str = "PID ".getmypid().": ".$this->name.": Aquiring machine lock: ".($this->lock + 1)."\n";
			file_put_contents(MAMA_PATH."/mama-lock-debug", $str, FILE_APPEND | LOCK_EX);
		}

		if ($this->lock == 0) {
			if ($this->lock_stream !== FALSE)
				fatal("Tried to aquire an already aquired lock");

			$file = MAMA_PATH."/machines/".$this->name."/lock";

			$this->lock_stream = fopen($file, "we");
			if ($this->lock_stream === FALSE)
				fatal("Failed to open ".$file);

			if (flock($this->lock_stream, LOCK_EX) === FALSE)
				fatal("Failed to acquire read lock on ".$file);
		}

		$this->lock++;

		return TRUE;
	}

	// Release exclusive lock on the machine directory
	public function unlock()
	{
		if (DEBUG_LOCK) {
			$str = "PID ".getmypid().": ".$this->name.": Releasing machine lock: ".$this->lock."\n";
			file_put_contents(MAMA_PATH."/mama-lock-debug", $str, FILE_APPEND | LOCK_EX);
		}

		if ($this->lock == 0)
			fatal($this->name." is not locked");

		$this->lock--;

		if ($this->lock == 0) {
			fclose($this->lock_stream);
			$this->lock_stream = FALSE;
		}
	}

	public function is_locked()
	{
		return ($this->lock != 0);
	}

	public function sleep_on_lock($seconds)
	{
		$this->unlock();

		if ($this->is_locked())
			fatal("Sleeping while lock is held on ".$this->name);

		sleep($seconds);

		$this->lock();
	}

	private static function sort_machines($a, $b)
	{
		return $a->name > $b->name;
	}

	public static function get_all()
	{
		$machs = array();

		Settings::load();

		foreach (Settings::$settings->machine as $m) {
			$mach = new Machine();
			$mach->fill_from_xmlobj($m);

			$machs[] = $mach;
		}

		uasort($machs, "Machine::sort_machines");

		return $machs;
	}

	public static function get_by_name($name)
	{
		$machs = self::get_all();
		foreach ($machs as $mach) {
			if ($mach->name == $name)
				return $mach;
		}

		return FALSE;
	}

	public static function find_by_mac($mac)
	{
		for ($i = 0; $i < count(Settings::$settings->machine); $i++) {
			$m = Settings::$settings->machine[$i];

			if ($m->mac == $mac) {
				$mach = new Machine();
				$mach->fill_from_xmlobj($m);

				return $mach;
			}
		}

		return false;
	}

	public function get_ip_from_mac()
	{
		$ip = FALSE;

		// We never return an ip for stopped machine
		if (!$this->is_started())
			return FALSE;

		exec("ip -4 neighbor | grep \"".$this->mac."\"", $res, $code);
		foreach ($res as $row) {
			$row = explode(" ", $row);
			$ip = $row[0];
			$state = $row[5];
			if ($state == "REACHABLE")
				return Util::is_valid_ip($ip);
		}

		return FALSE;
	}

	public function get_ip()
	{
		$ip = $this->get_ip_from_mac();

		LOCK();

		if ($ip === FALSE) {
			// Use stored ip as last resort
			$this->load();
			if ($this->ip != "")
				$ip = $this->ip;
		} else {
			if ($this->ip != $ip) {
				debug("Changing IP ".$this->ip." -> ".$ip);
				$this->ip = $ip;
				$this->save();
			}
		}
		UNLOCK();

		return $ip;
	}

	public function set_ip($ip)
	{
		if (Util::is_valid_ip($ip)) {
			LOCK();
			$this->load();
			$this->ip = $ip;
			$this->save();
			UNLOCK();
		}
	}

	public function get_power()
	{
		return $this->get_power_attribute("output (bool)");
	}

	public function get_ssh_status()
	{
		$ip = $this->get_ip();

		if ($ip === FALSE)
			return "closed";

		// Check if SSH port is open
		exec("nc -w 5 -z ".$ip." 22", $tmp, $ret);
		if ($ret == 0)
			return "open";

		return "closed";
	}

	public function is_started()
	{
		if ($this->is_started == 1)
			return TRUE;

		return FALSE;
	}

	public function is_only_vm()
	{
		if ($this->only_vm == 1)
			return TRUE;

		return FALSE;
	}

	public function get_status()
	{
		$ip = $this->get_ip();

		if ($ip === false) {
			$this->set_ip("");
			return "offline";
		} else {
			$this->set_ip($ip);
		}

		exec("ping -w 3 -c 1 ".$ip, $tmp, $ret);
                if ($ret == 0) {
			if ($this->get_ssh_status() == "open")
        			return "online";
			else
				return "unreachable";
		}

		$this->set_ip("");
		return "offline";
	}

	public function fill_from_xmlobj($obj)
	{
		$this->name = (string)$obj->name;
		$this->mac = (string)$obj->mac;
		$this->ip = (string)$obj->ip;
		$this->is_started = (string)$obj->is_started;
		$this->arch = (string)$obj->arch;
		$this->os = (string)$obj->os;
		$this->kernel = (string)$obj->kernel;
		$this->pwr_dev = (string)$obj->pwr_dev;
		$this->pwr_slot = (string)$obj->pwr_slot;
		$this->rly_dev = (string)$obj->rly_dev;
		$this->rly_slot = (string)$obj->rly_slot;
		$this->reservation = (string)$obj->reservation;
		$this->resources = (string)$obj->resources;
		$this->boot_params = (string)$obj->boot_params;
		$this->vm_params = (string)$obj->vm_params;
		$this->only_vm = (string)$obj->only_vm;
		$this->job = (string)$obj->job;
		$this->startcmd = (string)$obj->startcmd;
		$this->stopcmd = (string)$obj->stopcmd;
	}

	public function save()
	{
		Settings::update_machine($this);
	}

	public function load()
	{
		$machs = Machine::get_all();

		foreach ($machs as $mach) {
			if ($this->mac == $mach->mac) {
				$this->fill_from_xmlobj($mach);
				return;
			}
		}

		fatal("Failed to load machine");
	}

	public function clear()
	{
		LOCK();
		$this->load();
		$this->ip = "";
		$this->is_started = "";
		$this->save();
		UNLOCK();

		$this->out("cleared machine state");
	}

	public function clear_job()
	{
		LOCK();
		$this->load();
		$this->job = "";
		$this->save();
		UNLOCK();

		$this->out("cleared job");
	}

	public function print_info()
	{
		Log::pause();

		$ip = $this->get_ip();
		out("Name:\t\t".$this->name);
		out("MAC:\t\t".$this->mac);
		out("IP:\t\t".$ip);
		out("Is started:\t".($this->is_started() ? "yes" : "no"));
		out("Is only VM:\t".($this->only_vm == 1 ? "yes" : "no"));
		out("OS:\t\t".$this->os);
		out("Kernel:\t\t".($this->kernel == "" ? "default" : $this->kernel));
		out("Status:\t\t".$this->get_status());
		out("Power device:\t".$this->pwr_dev.",".$this->pwr_slot);
		if ($this->rly_dev != "")
			out("Relay device:\t".$this->rly_dev.",".$this->rly_slot);
		out("Reserved by:\t".$this->reservation);
		out("Resources:\t".$this->resources." (nested: ".Machine::get_nested_resources($this->name).")");
		if ($this->startcmd != "")
			out("Start command:\t".$this->startcmd);
		if ($this->stopcmd != "")
			out("Stop command:\t".$this->stopcmd);
		out("Boot params:\t".$this->boot_params);
		if ($this->vm_params != "")
			out("VM params:\t".$this->vm_params);
		if ($this->job != "")
			out("Running job:\t".$this->job);
		out("Power sensors:");

		// Find power obj
		$dev = CtlDev::get_by_name($this->pwr_dev);
		if ($dev === false) {
			out("Couldn't find power device");
			return;
		}

		$data = $dev->get_sensors($this->pwr_slot);
		if ($data === NULL) {
			out("No sensors found");
			return;
		}

		foreach ($data as $key => $val) {
			out("  ", TRUE);
			out(Util::pad_str($key, 16), TRUE);
			out(": ".$val);
		}

		Log::resume();
	}

	public function set($attr, $val)
	{
		switch ($attr) {
		case "power":
			$dev = CtlDev::get_by_name($this->pwr_dev);
			if ($dev !== FALSE)
				$dev->set_power($this->pwr_slot, $val);
			break;
		case "relay":
			$dev = CtlDev::get_by_name($this->rly_dev);
			if ($dev !== FALSE)
				$dev->push($this->rly_slot, $val);
			break;
		case "os":
			$arch = explode("/", $val)[0];
			$os = explode("/", $val)[1];
			if (Os::is_runnable($arch, $os, $this)) {
				LOCK();
				$this->load();
				$this->out("Setting OS ".$val);
				$this->os = $val;
				$this->kernel = "";
				$this->save();
				UNLOCK();
			} else {
				fatal("Invalid OS: ".$val);
			}
			break;
		case "kernel":
			$kernels = $this->get_kernels();

			if (strtolower($val) == "latest") {
				$val = "";
				$latest_date = "";
				foreach ($kernels as $name => $date) {
					if ($date >= $latest_date) {
						$latest_date = $date;
						$val = $name;
					}
				}
			}

			if (strtolower($val) == "default")
				$val = "";

			if (in_array($val, array_keys($kernels)) || $val == "") {
				// Make sure the initrd is readable so that the webserver can serve it
				if (!Util::is_root())
					out("You must be root to run this command");

				$initrd = MAMA_PATH."/machines/".$this->name."/".$this->os."/boot/initrd-".$val;
				if (file_exists($initrd))
					passthru("sudo chmod 644 ".$initrd);

				LOCK();
				$this->load();
				$this->kernel = $val;
				$this->save();
				UNLOCK();
			} else {
				$this->error("Kernel ".$val." doesn't exist. Aborting.");
			}
			break;
		case "resources":
			out("Current resources: ".$this->resources);
			if ($val == "")
				$val = Util::get_line("Enter new resources (space separated): ");

			LOCK();
			$this->load();
			$this->resources = $val;
			$this->save();
			UNLOCK();
			break;
		case "params":
			out("Current boot parameters: ".$this->boot_params);
			if ($val == "")
				$val = Util::get_line("Enter new boot parameters: ");

			LOCK();
			$this->load();
			$this->boot_params = $val;
			$this->save();
			UNLOCK();
			break;
		case "vmparams":
			out("Current VM parameters: ".$this->vm_params);
			if ($val == "")
				$val = Util::get_line("Enter new VM parameters: ");

			LOCK();
			$this->load();
			$this->vm_params = $val;
			$this->save();
			UNLOCK();
			break;
		case "startcmd":
			out("Current start command: ".$this->startcmd);
			if ($val == "")
				$val = Util::get_line("Enter new start command: ");

			LOCK();
			$this->load();
			$this->startcmd = $val;
			$this->save();
			UNLOCK();
			break;
		case "stopcmd":
			out("Current stop command: ".$this->stopcmd);
			if ($val == "")
				$val = Util::get_line("Enter new stop command: ");

			LOCK();
			$this->load();
			$this->stopcmd = $val;
			$this->save();
			UNLOCK();
			break;
		}
	}

	public function get($attr)
	{
		switch ($attr) {
		case "params":
			return $this->boot_params;
		case "vmparams":
			return $this->vm_params;
		case "os":
			return $this->os;
		case "kernel":
			return $this->kernel;
		case "power":
			return $this->pwr_dev;
		case "relay":
			return $this->rly_dev;
		case "resources":
			return $this->resources;
		case "reservation":
			return $this->reservation;
		case "ip":
			return $this->get_ip();
		case "mac":
			return $this->mac;
		case "startcmd":
			return $this->startcmd;
		case "stopcmd":
			return $this->stopcmd;
		}

		$this->error("Invalid attribute");
	}

	public function get_os_arch()
	{
		if ($this->os == "")
			return "";

		return explode("/", $this->os)[0];
	}

	public function get_power_attribute($attr)
	{
		// Find power obj
		$dev = CtlDev::get_by_name($this->pwr_dev);
		if ($dev === false) {
			$this->error("Couldn't find power device");
			return FALSE;
		}

		$data = $dev->get_sensors($this->pwr_slot);
		if ($data === NULL) {
			$this->error("No sensors found");
			return FALSE;
		}

		foreach ($data as $key => $val) {
			if ($key == $attr) {
				debug("Getting: ".$attr." = ".$val);
				return $val;
			}
		}

		return FALSE;
	}

	public function wait_for_status($status, $seconds, $dont_print = FALSE)
	{
		if (!$dont_print)
			$this->out("Waiting for status: ".$status."... ");

		$time = time();

		while ($this->get_status() != $status) {
			$time_passed = time() - $time;
			sleep(1);

			if ($time_passed >= $seconds) {
				out("timed out after ".$seconds."s", FALSE, FALSE);
				return FALSE;
			}
		}

		$time_passed = time() - $time;
		if (!$dont_print)
			$this->out("Status reached after ".$time_passed." s");

		return TRUE;
	}

	// Get all resources for machine with name and if one resource
	// is another machine then get it's resources as well.
	public static function get_nested_resources($mach_name)
	{
		$mach = Machine::get_by_name($mach_name);
		if ($mach === FALSE)
			return "";

		$str = "";
		$resources = explode(" ", $mach->resources);
		foreach ($resources as $resource) {
			$resource = trim($resource);
			if ($resource == "")
				continue;
			if ($str != "")
				$str .= " ";

			$str .= $resource;

			$nested = Machine::get_nested_resources($resource);
			if ($nested != "")
				$str .= " ".$nested;
		}

		return $str;
	}

	public function wait_for_resources($timeout = FALSE)
	{
		if ($timeout === FALSE)
			$timeout = self::$resource_wait_timeout;

		if (trim($this->resources) == "")
			return TRUE;

		$resources = Machine::get_nested_resources($this->name);
		$resources = explode(" ", $resources);

		$sleep = FALSE;
		$machs = self::get_all();
		$res_wait = array(); // Used for printing the resouces we're waiting for to user
		foreach ($machs as $mach) {
			if (!$mach->is_started())
				continue;

			if ($mach->name == $this->name)
				continue;

			foreach ($resources as $res) {
				$mach_res = explode(" ", $mach->resources);
				if (in_array($res, $mach_res)) {
					$sleep = TRUE;
					$res_wait[] = $res;
				}
			}
		}

		if ($sleep) {
			if ($timeout == self::$resource_wait_timeout) {
				$this->out("Waiting for resources: ", TRUE);
				foreach ($res_wait as $res)
					out($res." ", TRUE, FALSE);
				out("", FALSE, FALSE);
			}

			SLEEP_ON_LOCK(10);

			$timeout -= 10;
			if ($timeout <= 0) {
				$this->error("Timed out waiting for resources");
				return FALSE;
			}
			return $this->wait_for_resources($timeout);
		}

		return TRUE;
	}

	public function start()
	{
		$status = $this->get_status();
		if ($status == "online") {
			$this->error("Machine is already started");
			return FALSE;
		}

		if ($this->os == "") {
			$this->error("No OS has been configured!");
			return FALSE;
		}

		$arch = explode("/", $this->os)[0];
		$os = explode("/", $this->os)[1];
		if (!OS::is_runnable($arch, $os, $this)) {
			$this->error("OS is not runnable: ".$this->os);
			return FALSE;
		}

		if ($status  == "unreachable") {
			$this->stop();
			$this->out("Waiting for power cycle delay: ".self::$power_cycle_delay);
			sleep(self::$power_cycle_delay);
			return $this->start();
		}

		if ($this->is_only_vm())
			return $this->start_vm();

		LOCK();
		if ($this->wait_for_resources() === FALSE) {
			UNLOCK();
			return FALSE;
		}

		$this->load();
		$this->is_started = 1;
		$this->save();
		UNLOCK();

		if ($this->startcmd == "" && $status == "offline") {
			if ($this->pwr_dev != "") {
				if ($this->get_power() != 0) {
					$this->set("power", 0);
					$this->out("Power was already on. Waiting: ".self::$power_cycle_delay." seconds");
					sleep(self::$power_cycle_delay);
				}
				$this->set("power", 1);
			} else if ($this->rly_dev == "") {
				$this->out("No control device available to turn on the machine");
			}

			if ($this->rly_dev != "")
				$this->set("relay", 1);
		} else if ($this->startcmd != "" && $status == "offline") {
			$startcmd = $this->startcmd;
			$startcmd = str_replace("\$OS_ARCH", $this->get_os_arch(), $startcmd);
			$startcmd = str_replace("\$MAC", $this->mac, $startcmd);
			$this->out("Start command: ".$startcmd);

			// The command runs in the background so that we can start waiting for the machine
			passthru($startcmd." > /dev/null &");
		}

		$ret = $this->wait_for_status("online", self::$start_timeout);

		if ($this->get_status() != "online")
			$this->stop();

		return $ret;
	}

	// Is the machine running as a VM?
	public function is_vm()
	{
		if ($this->is_only_vm())
			return TRUE;

		unset($out);
		exec("ps aux | grep qemu | grep ".$this->mac, $out, $res);
		if (count($out) > 1)
			return TRUE;
		else
			return FALSE;
	}

	public function kill_vm()
	{
		if (!$this->is_vm())
			return FALSE;

		unset($out);
		exec("ps ax | grep qemu | grep -v SCREEN | grep ".$this->mac, $out, $res);
		if (!isset($out[0]))
			return;

		$out = trim($out[0]);
		$pid = explode(" ", $out)[0];

		$this->out("Killing VM with pid ".$pid);
		exec("sudo kill ".$pid);
	}

	public function start_vm()
	{
		if (!Util::is_root() === true)
			$this->out("You must be root to start a VM");

		LOCK(); // Protect agains other VMs looking for resources at the same time

		unset($out);
		exec("ps aux | grep qemu | grep ".$this->mac, $out, $res);
		if (count($out) > 1) {
			$this->out("Failed to start VM. Already running.");
			UNLOCK();
			return FALSE;
		}

		// Find free tap device
		for ($tap_id = 0; $tap_id <= 10; $tap_id++) {
			$ret = shell_exec("ip link | grep \"tap".$tap_id.": \" | wc -l");
			$ret = (int)$ret;
			if ($ret == 0)
				break;
		}

		if ($tap_id > 10) {
			$this->out("No available tap interface found");
			UNLOCK();
			return FALSE;
		}

		LOCK();
		$this->load();
		$this->is_started = 1;
		$this->save();
		UNLOCK();

		$this->out("Starting virtual machine with OS ".$this->os." and tap".$tap_id);
		$num_cores = (int)shell_exec("nproc");
		$cores_str = "-smp ".$num_cores;
		$sys_str = "-m ".(1024 * 8);
		$net_str = "-netdev tap,id=net0,ifname=tap".
			   $tap_id.",script=no,downscript=no -device virtio-net,netdev=net0,mac=".$this->mac;
		$os_arch = explode("/", $this->os)[0];

		$mama_arch = trim(shell_exec("uname -p"));
		$kvm_str = "";

		$arch_str = "";

		if ($this->kernel == "") {
			$kernel_filename = "kernel-mama";
			$initrd_filename = "initrd-mama";
		} else {
			$kernel_filename = "vmlinuz-".$this->kernel;
			$initrd_filename = "initrd-".$this->kernel;
		}
		$kernel = $this->get_kernel_path().$kernel_filename;
		$initrd = $this->get_kernel_path().$initrd_filename;

		switch ($os_arch) {
		case "i686":
			$arch = "i386";
			$arch = "x86_64";
			break;
		case "aarch64":
			$arch = "aarch64";
			$sys_str .= " -machine virt -cpu cortex-a57 -bios /usr/share/qemu/qemu-uefi-aarch64.bin";
			$cores_str = "-smp 8";
			break;
		case "ppc64le":
			$arch = "ppc64";
			$sys_str .= " -M pseries";

			break;
		default:
			$arch = "x86_64";
		}

		$net_str .= " -kernel ".$kernel." -initrd ".$initrd." -append \"".$this->boot_params." ip=dhcp rd.neednet=1 systemd.hostname=".$this->name." root=nfs:".MAMA_HOST.":".MAMA_PATH."/machines/".$this->name."/".$this->os.",rw\"";

		if ($arch == $mama_arch)
			$kvm_str = "-enable-kvm";

		$cmd = "sudo screen -d -m qemu-system-".$arch." ".$sys_str." ".$kvm_str." ".$cores_str." ".$net_str." -nographic -serial file:/dev/null ".$this->vm_params;
		$this->out($cmd);
		passthru($cmd);

		sleep(3); // Hold the lock so that qemu have a chance to grab it's resources (tap)
		UNLOCK(); // All resources are grabbed so we can let other VMs start

		$ret = $this->wait_for_status("online", self::$start_timeout);
		if (!$ret) {
			$this->kill_vm();
			$this->clear();
		}

		return $ret;
	}

	public function stop($force = FALSE)
	{
		$this->out("Stopping machine");

		LOCK();
		$this->load();
		if (!$this->is_started() && $force != TRUE) {
			UNLOCK();
			return TRUE;
		}
		UNLOCK();

		$status = $this->get_status();

		if ($status == "online") {
			// When the machine is controlled by a power device it is better to halt
			// the system and cut the power. That way all the capacitors gets drained
			// and the system immediately starts again when power is resumed.
			// For any system without power device we prefer the poweroff command
			// since that also terminates VMs automatically.
			if ($this->pwr_dev == "")
				$this->ssh_cmd("poweroff");
			else
				$this->ssh_cmd("halt");
		}

		if ($status == "unreachable" && $this->stopcmd == "") {
			if ($this->rly_dev != "") {
				$this->set("relay", 8);
			} else if ($this->pwr_dev != "") {
				$this->set("power", 0);
			} else {
				$this->out("No control device available to turn off the machine");
				$this->clear();

				return FALSE;
			}
		}

		if (!$this->wait_for_status("offline", self::$stop_timeout)) {
			if ($this->rly_dev != "")
				$this->set("relay", self::$rly_force_off_delay);
			if ($this->is_vm())
				$this->kill_vm();
		}

		// Always power off the machine
		if ($this->stopcmd == "") {
			if ($this->is_only_vm())
				$this->kill_vm();
			else
				$this->set("power", 0);
		}

		if ($this->stopcmd != "") {
			$this->out("Stop command: ".$this->stopcmd);
			passthru($this->stopcmd." > /dev/null &");
		}

		$this->clear();

		return TRUE;
	}

	// Wait for machine to be stopped and have no queued jobs
	public function wait()
	{
		LOCK();
		$this->load();
		if ($this->is_started() || $this->job != "")
			$this->out("Waiting for machine to become idle ( started: ".($this->is_started() ? "yes " : "no ").($this->job != "" ? "job: ".$this->job : "")." )");

		while ($this->is_started() || $this->job != "") {
			SLEEP_ON_LOCK(10);
			$this->load();
		}
		UNLOCK();
	}

	public function ssh_cmd($cmd)
	{
		$timeout_str = isset(Options::$options["no-ssh-timeout"]) ?
			"" : "timeout --foreground ".self::$ssh_cmd_timeout."m";

		// Sometimes we don't get the correct status immediately so wait a little bit for it
		if ($this->wait_for_status("online", 30, TRUE)) {
			out("(".$this->name.") ssh: ".$cmd);
			$log_str = Log::$logfile !== FALSE ? " &>> ".Log::$logfile : "";


			// Escape sensitive characters
			$cmd = str_replace("$", "\\$", $cmd);

			passthru($timeout_str." ssh -q -o \"UserKnownHostsFile=/dev/null\" ".
				"-o \"ConnectTimeout=10\" ".
				"-o \"StrictHostKeyChecking=no\" root@".$this->get_ip().
				" -t \"".$cmd."\" ".$log_str, $res);

			return $res;
		} else {
			out("Unable to execute ssh cmd since machine is not online.");
			return FALSE;
		}
	}

	function reserve($user)
	{
		LOCK();
		$this->load();
		$this->reservation = $user;
		$this->save();
		UNLOCK();
	}

	// Returns an array of kernels available for the currently selected OS on the machine
	function get_kernels()
	{
		$path = $this->get_kernel_path();

		$res = shell_exec("find ".$path." -maxdepth 1 2> /dev/null | grep initrd-");
		$rows = explode(PHP_EOL, $res);

		$kernels = array();
		$kernels["default"] = "";

		foreach ($rows as $row) {
			if ($row == "")
				continue;
			$row = substr($row, strlen($path));
			$kernel = substr($row, strlen("initrd-"));

			if ($kernel == "mama")
				continue;

			$res = NULL;
			exec("stat -c %Y ".$path."initrd-".$kernel, $res);
			$epoch = $res[0];
			$dt = new DateTime("@$epoch");
			$date = $dt->format('Y-m-d H:i:s');

			$kernels[$kernel] = $date;
		}
		natsort($kernels);

		return $kernels;
	}

	function get_kernel_path()
	{
		return MAMA_PATH."/machines/".$this->name."/".$this->os."/boot/";
	}

	function out($msg, $no_eol = FALSE, $timestamp = TRUE)
	{
		out("(".$this->name.") ".$msg, $no_eol, $timestamp);
	}

	function error($msg, $no_eol = FALSE, $timestamp = TRUE)
	{
		error("(".$this->name.") ".$msg, $no_eol, $timestamp);
	}

	function fatal($msg, $errno = 1)
	{
		fatal("(".$this->name.") ".$msg, $errno);
	}
}

?>
