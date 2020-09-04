<?php

class Machine {
	public $name, $mac, $arch, $os, $kernel, $pwr_dev, $pwr_slot, $rly_dev, $rly_slot;

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
		$res = shell_exec("ip -4 neighbor | grep \"".$this->mac."\" | cut -d\" \" -f1");
		$res = trim($res);

		return Util::is_valid_ip($res);
	}

	public function get_ssh_status()
	{
		$ip = $this->get_ip_from_mac();

		// Check if SSH port is open
		exec("nc -w 5 -z ".$ip." 22", $tmp, $ret);
		if ($ret == 0)
			return "open";

		return "closed";
	}

	public function get_status()
	{
		$ip = $this->get_ip_from_mac();

		if ($ip === false)
			return "offline";

		exec("ping -w 1 -c 1 ".$ip, $tmp, $ret);
                if ($ret == 0) {
        			return "online";
		}

		return "offline";
	}

	public function fill_from_xmlobj($obj)
	{
		$this->name = (string)$obj->name;
		$this->mac = (string)$obj->mac;
		$this->arch = (string)$obj->arch;
		$this->os = (string)$obj->os;
		$this->kernel = (string)$obj->kernel;
		$this->state = (string)$obj->state;
		$this->pwr_dev = (string)$obj->pwr_dev;
		$this->pwr_slot = (string)$obj->pwr_slot;
		$this->rly_dev = (string)$obj->rly_dev;
		$this->rly_slot = (string)$obj->rly_slot;
	}

	public function print_info()
	{
		$ip = $this->get_ip_from_mac();
		echo "Name:\t\t".$this->name."\n";
		echo "MAC:\t\t".$this->mac."\n";
		echo "IP\t\t".$ip."\n";
		echo "OS:\t\t".$this->os."\n";
		echo "Kernel:\t".$this->kernel."\n";
		echo "Status:\t\t".$this->get_status()."\n";
		echo "Power device:\t".$this->pwr_dev.",".$this->pwr_slot."\n";
		echo "Relay device:\t".$this->rly_dev.",".$this->rly_slot."\n";
		echo "Power sensors:\n";

		// Find power obj
		$dev = CtlDev::get_by_name($this->pwr_dev);
		if ($dev === false) {
			echo "Couldn't find power device\n";
			return;
		}

		$data = $dev->get_sensors($this->pwr_slot);
		foreach ($data as $key => $val) {
			echo "  ";
			echo Util::pad_str($key, 16);
			echo ": ".$val."\n";
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
			$dev->push($this->rly_slot, $val);
			break;
		case "os":
			$this->os = $val;
			Settings::update_machine($this);
			break;
		}
	}

	public function start()
	{
		if ($this->get_status() == "online")
			return;

		$this->set("relay", 1);
	}

	public function stop()
	{
		if ($this->get_status() == "offline")
			return;

		if ($this->get_ssh_status() == "open") {
			shell_exec("ssh -o \"UserKnownHostsFile=/dev/null\" ".
				"-o \"StrictHostKeyChecking=no\" root@".$this->get_ip_from_mac().
				" -t poweroff");
			return;
		}

		if ($this->get_status() == "online")
			$this->set("relay", 1);
	}
}

?>
