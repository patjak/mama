<?php

class Machine {
	public $name, $mac, $ip, $is_started, $arch, $os, $kernel, $pwr_dev, $pwr_slot,
	       $rly_dev, $rly_slot, $reservation, $resources, $boot_params, $only_vm;

	// Tunables for how to manage machines
	public static
		// How long to wait for a machine to boot before giving up (in seconds)
		$start_timeout = 4 * 60,

		// How long to wait for a machine to stop before giving up (in seconds)
		$stop_timeout = 30,

		// How long an ssh command is allowed to execute (in minutes)
		$ssh_cmd_timeout = 4 * 60,

		// How long power btn must be pressed to force power off
		$rly_force_off_delay = 8,

		// How long a machine must be powered off before being powered on again (in seconds)
		$power_cycle_delay = 5,

		// How long to wait for a resource to become available (in seconds)
		$resource_wait_timeout = 20 * 60 * 60;

	public static function get_all()
	{
		$machs = array();

		Settings::load();

		foreach (Settings::$settings->machine as $m) {
			$mach = new Machine();
			$mach->fill_from_xmlobj($m);

			$machs[] = $mach;
		}

		return $machs;
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
		// Retry a couple of times for luck
		for ($i = 0; $i < 5; $i++) {
			$res = shell_exec("ip -4 neighbor | grep \"".$this->mac."\" | grep REACHABLE | cut -d\" \" -f1");
			$res = trim($res);

			$ip = Util::is_valid_ip($res);
			if ($ip !== FALSE)
				return $ip;
		}

		// If we fail to get IP from MAC we fallback to returning the stored IP
		return $this->get_ip();
	}

	// Reload the settings file and update this machine with any new data
	public function update_from_xml()
	{
		// Reload the settings file since the ip might have been updated
		Settings::load();

		for ($i = 0; $i < count(Settings::$settings->machine); $i++) {
			$m = Settings::$settings->machine[$i];

			if ($m->mac == $this->mac)
				$this->fill_from_xmlobj($m);
		}
	}

	public function get_ip()
	{
		$this->update_from_xml();

		return Util::is_valid_ip($this->ip);
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

		if ($ip === false)
			return "offline";

		exec("ping -w 3 -c 1 ".$ip, $tmp, $ret);
                if ($ret == 0) {
			if ($this->get_ssh_status() == "open")
        			return "online";
			else
				return "unreachable";
		}

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
		$this->only_vm = (string)$obj->only_vm;
	}

	public function print_info()
	{
		$ip = $this->get_ip();
		out("Name:\t\t".$this->name);
		out("MAC:\t\t".$this->mac);
		out("IP:\t\t".$ip);
		out("Is started:\t".($this->is_started == 1 ? "yes" : "no"));
		out("Is only VM:\t".($this->only_vm == 1 ? "yes" : "no"));
		out("OS:\t\t".$this->os);
		out("Kernel:\t\t".($this->kernel == "" ? "default" : $this->kernel));
		out("Status:\t\t".$this->get_status());
		out("Power device:\t".$this->pwr_dev.",".$this->pwr_slot);
		out("Relay device:\t".$this->rly_dev.",".$this->rly_slot);
		out("Reserved by:\t".$this->reservation);
		out("Resources:\t".$this->resources);
		out("Boot params:\t".$this->boot_params);
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
	}

	public function set($attr, $val)
	{
		switch ($attr) {
		case "power":
			$dev = CtlDev::get_by_name($this->pwr_dev);
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
				out("Setting OS ".$val." to machine ".$this->name);
				$this->os = $val;
				Settings::update_machine($this);
			} else {
				fatal("Invalid OS: ".$val);
			}
			break;
		case "kernel":
			$kernels = $this->get_kernels();

			if (strtolower($val) == "default")
				$val = "";

			if (in_array($val, array_keys($kernels)) || $val == "") {
				$this->kernel = $val;
				Settings::update_machine($this);
			} else {
				out("Kernel ".$val." doesn't exist. Aborting.");
			}
			break;
		case "resources":
			out("Current resources: ".$this->resources);
			if ($val == "")
				$val = Util::get_line("Enter new resources (space separated): ");

			$this->resources = $val;
			Settings::update_machine($this);
			break;
		case "params":
			out("Current boot parameters: ".$this->boot_params);
			if ($val == "")
				$val = Util::get_line("Enter new boot parameters: ");

			$this->boot_params = $val;
			Settings::update_machine($this);
			break;
		}
	}

	public function get($attr)
	{
		// Find power obj
		$dev = CtlDev::get_by_name($this->pwr_dev);
		if ($dev === false) {
			out("Couldn't find power device");
			return FALSE;
		}

		$data = $dev->get_sensors($this->pwr_slot);
		if ($data === NULL) {
			out("No sensors found");
			return FALSE;
		}

		foreach ($data as $key => $val) {
			if ($key == $attr) {
				out("Getting: ".$attr." = ".$val);
				return $val;
			}
		}

		return FALSE;
	}

	public function wait_for_status($status, $seconds, $dont_print = FALSE)
	{
		if (!$dont_print)
			out("Waiting for status: ".$status);

		$time = time();

		while ($this->get_status() != $status) {
			$time_passed = time() - $time;
			sleep(1);

			if ($time_passed >= $seconds) {
				out("Timed out after ".$seconds."s waiting for status ".$status);
				return FALSE;
			}
		}

		$time_passed = time() - $time;
		if (!$dont_print)
			out("Status reached after ".$time_passed." seconds");

		return TRUE;
	}

	public function wait_for_resources($timeout = FALSE)
	{
		if ($timeout === FALSE)
			$timeout = self::$resource_wait_timeout;

		if (trim($this->resources) == "")
			return TRUE;

		$resources = explode(" ", $this->resources);

		$sleep = FALSE;
		$machs = self::get_all();
		$res_wait = array(); // Used for printing the resouces we're waiting for to user
		foreach ($machs as $mach) {
			if ($mach->is_started != 1)
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
				out("Waiting for resources: ", TRUE);
				foreach ($res_wait as $res)
					out($res, TRUE, FALSE);
				out("", FALSE, FALSE);
			}
			sleep(10);
			$timeout -= 10;
			if ($timeout <= 0) {
				error("Timed out waiting for resources");
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
			error("Machine is already started");
			return FALSE;
		}

		$arch = explode("/", $this->os)[0];
		$os = explode("/", $this->os)[1];
		if (!OS::is_runnable($arch, $os, $this)) {
			error("Os is not runnable: ".$this->os);
			return FALSE;
		}

		if ($status  == "unreachable") {
			$this->stop();
			return $this->start();
		}

		if ($this->is_only_vm())
			return $this->start_vm();

		if ($this->wait_for_resources() === FALSE)
			return FALSE;

		$this->is_started = 1;
		Settings::update_machine($this);

		if ($status == "offline") {
			if ($this->rly_dev != "None/Empty" || $this->rly_dev != "") {
				$this->set("power", 1);
				$this->set("relay", 1);
			} else {
				if ($this->pwr_dev != "None/Empty" || $this->pwr_dev != "") {
					if ($this->get("power") != 0) {
						$this->set("power", 0);
						sleep(self::$power_cycle_delay);
					}
					$this->set("power", 1);
				} else {
					out("No control device available to turn on the machine");
					return FALSE;
				}
			}
		}


		$ret = $this->wait_for_status("online", self::$start_timeout);
		if ($this->get_status() == "offline")
			$this->stop();

		return $ret;
	}

	// Is the machine running as a VM?
	public function is_vm()
	{
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
		$pid = explode(" ", $out[0])[0];

		out("Killing VM ".$this->name." with pid ".$pid);
		exec("kill ".$pid);
	}

	public function start_vm()
	{
		if (!Util::is_root() === true) {
			out("You must be root to start a VM");
			return FALSE;
		}

		unset($out);
		exec("ps aux | grep qemu | grep ".$this->mac, $out, $res);
		if (count($out) > 1) {
			out("Failed to start VM. Already running.");
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
			out("No available tap interface found");
			return FALSE;
		}

		$this->is_started = 1;
		Settings::update_machine($this);

		out("Starting virtual machine for ".$this->name." with OS ".$this->os." and tap".$tap_id);
		$num_cores = (int)shell_exec("nproc");
		$cores_str = "-smp ".$num_cores;
		$sys_str = "-m ".(1024 * 8);
		$net_str = "-boot n -netdev tap,id=net0,ifname=tap".
			   $tap_id.",script=no,downscript=no -device virtio-net,netdev=net0,mac=".$this->mac;
		$os_arch = explode("/", $this->os)[0];

		$mama_arch = trim(shell_exec("uname -p"));
		$kvm_str = "";

		$arch_str = "";
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
		default:
			$arch = "x86_64";
		}

		if ($arch == $mama_arch)
			$kvm_str = "-enable-kvm";

		$cmd = "screen -d -m qemu-system-".$arch." ".$sys_str." ".$kvm_str." ".$cores_str." ".$net_str." -nographic -serial file:".Log::$logfile;
		debug($cmd);
		passthru($cmd);

		// echo "screen -d -m qemu-system-".$arch." ".$sys_str." ".$net_str." -nographic -serial file:/tmp/mama-virt-out-".$this->name."\n";
		// passthru("qemu-system-".$arch." ".$sys_str." ".$net_str." -nographic -serial mon:stdio");


		$ret = $this->wait_for_status("online", self::$start_timeout);
		if (!$ret)
			$this->kill_vm();

		return $ret;
	}

	public function stop()
	{
		out("Stopping machine ".$this->name);

		$status = $this->get_status();

		if ($status == "online") {
			out("Executing poweroff on machine");
			$this->ssh_cmd("poweroff");
		}

		if ($status == "unreachable") {
			if ($this->rly_dev != "None/Empty") {
				$this->set("relay", 8);
			} else if ($this->pwr_dev != "None/Empty") {
				$this->set("power", 0);
			} else {
				out("No control device available to turn off the machine");
				$this->ip = "";
				$this->is_started = "";
				Settings::update_machine($this);

				return FALSE;
			}
		}

		if (!$this->wait_for_status("offline", self::$stop_timeout)) {
			if ($this->rly_dev != "None/Empty")
				$this->set("relay", self::$rly_force_off_delay);
			if ($this->is_vm())
				$this->kill_vm();
		}

		// Always power off the machine
		$this->set("power", 0);

		// Clear the ip and is_started field in the xml
		$this->ip = "";
		$this->is_started = "";
		Settings::update_machine($this);

		return TRUE;
	}

	public function ssh_cmd($cmd)
	{
		// Sometimes we don't get the correct status immediately so wait a little bit for it
		if ($this->wait_for_status("online", 30, TRUE)) {
			out("ssh cmd: ".$cmd);
			$log_str = Log::$logfile !== FALSE ? " &>> ".Log::$logfile : "";
			passthru("timeout --foreground ".self::$ssh_cmd_timeout."m ssh -q -o \"UserKnownHostsFile=/dev/null\" ".
				"-o \"ConnectTimeout=10\" ".
				"-o \"StrictHostKeyChecking=no\" root@".$this->get_ip().
				" -t \"".$cmd."\" ".$log_str, $res);

			return $res;
		} else {
			out("Unable to execute ssh cmd since machine is not online.");
			return FALSE;
		}
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
}

?>
