<?php
define("MAMA_PATH", "/mama");
define("SETTINGS_FILE", "/mama/mama.xml");

// Power control devices
new CtlDev("None/Empty", 0, "");
new MpowerCtlDev("mpower1", 6, "192.168.2.2");
new MpowerCtlDev("mpower2", 6, "192.168.2.3");
new MpowerCtlDev("mpower3", 6, "192.168.2.4");

// Relay control devices
new UsbrlyCtlDev("usbrly1", 8, "/dev/ttyACM0");
new UsbrlyCtlDev("usbrly2", 8, "/dev/ttyACM1");


echo "\nMachine Manager v0.1\n\n";

if ($argc > 1) {
	if (Settings::load() === false) {
		echo "Failed to open settings file: ".SETTINGS_FILE."\n";
		exit(1);
	}
	parse_args($argv);
	exit(0);
}

print_usage($argv);
exit(0);

function parse_args($argv)
{
	if (isset($argv[2]))
		$arg = $argv[2];
	else
		$arg = false;

	switch ($argv[1]) {
	case "list":
		cmd_list();
		break;
	case "list-os":
		cmd_list_os($arg);
		break;
	case "new":
		cmd_new();
		break;
	case "delete":
		cmd_delete($arg);
		break;
	case "info":
		cmd_info($arg);
		break;
	case "start":
		cmd_start($arg);
		break;
	case "start-vm":
		cmd_start_vm($arg);
		break;
	case "stop":
		cmd_stop($arg);
		break;
	case "connect":
		cmd_connect($arg);
		break;
	case "set":
		cmd_set($argv);
		break;
	default:
		echo "Invalid command\n\n";
	}
}

// Returns an array of OS objects by parsing the directory structure of build OSes
function get_os_list($mach = false)
{
	if ($mach === false)
		$path = Settings::get_path()."/os/";
	else
		$path = Settings::get_path()."/machines/".$mach->name."/";

	$res = shell_exec("find ".$path." -maxdepth 2 2> /dev/null");
	$rows = explode(PHP_EOL, $res);
	array_shift($rows);

	$oses = array();

	foreach ($rows as $row) {
		$row = substr($row, strlen($path));
		$os = $row;

		$row = explode("/", $row);

		if (count($row) == 1)
			continue;

		$oses[] = $os;
	}

	return $oses;
}

// Returns an array of available archs from kiwi-descriptions
function get_arch_list()
{
	$archs = array();
	$oses = get_os_list();

	foreach ($oses as $os) {
		$arch = explode("/", $os);
		$arch = $arch[0];
		if (!in_array($arch, $archs))
			$archs[] = $arch;
	}

	return $archs;
}

function get_machine_list()
{
	$machines = array();

	for ($i = 0; $i < count(Settings::$settings->machine); $i++) {
		$m1 = Settings::$settings->machine[$i];
		$m2 = new Machine();
		$m2->name = $m1->name;
		$m2->mac = $m1->mac;
		$m2->os = $m1->os;

		$machines[] = Settings::$settings->machine[$i];
	}

	return $machines;
}

function cmd_list()
{
	$i = 1;
	echo "No\tName\t\tIP\t\tState\tJob\n";
	echo "--------------------------------------------------------------------------------\n";
	foreach (Settings::$settings->machine as $m) {
		$mach = new Machine();
		$mach->fill_from_xmlobj($m);
		$ip = $mach->get_ip_from_mac();

		// Number
		echo Util::pad_str($i++, 8);

		// Name
		echo Util::pad_str($mach->name, 16);

		// IP 
		echo Util::pad_str($ip, 16);

		// State
		echo Util::pad_str($mach->get_status(), 8);

		echo "\n";
	}
}

function cmd_new()
{
	$name = "";
	while ($name == "") {
		$name = Util::get_line("Name: ");

		$machs = get_machine_list();
		foreach ($machs as $m) {
			if ($m->name == $name) {
				echo "Name already exists\n";
				$name = "";
			}
		}
	}

	$m = new Machine();

	$mac = "";
	while (!Util::is_valid_mac($mac))
		$mac = Util::get_line("MAC: ");

	// Print available architecures
	$archs = get_arch_list();
	$i = 1;
	echo "\nNo\tArch\n";
	echo "--------------------------------\n";

	foreach ($archs as $arch) {
		echo Util::pad_str($i++, 8);
		echo $arch."\n";
	}

	$arch = Util::ask_from_array($archs, "Arch");
	echo "Arch selected: ".$arch."\n\n";

	// Print available OSes for selected arch
	cmd_list_os();
	$oses = get_os_list();
	$os = Util::ask_from_array($oses, "OS");
	echo "OS selected: ".$os."\n\n";

	// Select power device
	echo "No\tPower device\tSlots\n";
	echo "--------------------------------\n";
	$i = 1;
	foreach (CtlDev::$devs as $dev) {
		echo Util::pad_str($i++, 8);
		echo Util::pad_str($dev->name, 16);
		echo Util::pad_str($dev->slots, 8);
		echo "\n";
	}

	$pwr_dev = Util::ask_from_array(CtlDev::$devs, "Power device: ");
	echo "Device selected: ".$pwr_dev->name."\n";
	if ($pwr_dev->slots > 0)
		$pwr_slot = Util::ask_number($pwr_dev->slots, "Power device slot: ");
	else
		$pwr_slot = 0;

	echo "No\tRelay device\tSlots\n";
	echo "--------------------------------\n";
	$i = 1;
	foreach (CtlDev::$devs as $dev) {
		echo Util::pad_str($i++, 8);
		echo Util::pad_str($dev->name, 16);
		echo Util::pad_str($dev->slots, 8);
		echo "\n";
	}

	$rly_dev = Util::ask_from_array(CtlDev::$devs, "Relay device: ");
	echo "Device selected: ".$rly_dev->name."\n";
	if ($rly_dev->slots > 0)
		$rly_slot = Util::ask_number($rly_dev->slots, "Relay device slot: ");
	else
		$rly_slot = 0;

	$m->name = $name;
	$m->mac = $mac;
	$m->arch = $arch;
	$m->os = $os;
	$m->state = "offline";
	$m->pwr_dev = $pwr_dev->name;
	$m->pwr_slot = $pwr_slot;
	$m->rly_dev = $rly_dev->name;
	$m->rly_slot = $rly_slot;

	Settings::add_machine($m);

	shell_exec("mkdir -p ".MAMA_PATH."/machines/".$name);

	Settings::save();
}

function cmd_list_os($m = false)
{
	if ($m !== false) {
		$mach = select_machine($m);
		echo "Listing installed OSes for machine: ".$mach->name."\n";
	} else {
		$mach = false;
	}

	echo "No\tOS\n";
	echo "--------------------------------------------------------------------------------\n";

	$oses = get_os_list($mach);

	$i = 1;
	foreach ($oses as $os) {
		echo Util::pad_str($i++." ", 8);
		echo Util::pad_str($os, 32)."\n";
	}
}

function select_os($mach)
{
	$oses = get_os_list($mach);

	echo "No\tOS\n";
	echo "--------------------------------------------------------------------------------\n";

	$i = 1;
	foreach ($oses as $os) {
		echo Util::pad_str($i++." ", 8);
		echo Util::pad_str($os, 32)."\n";
	}

	$os = Util::ask_from_array($oses, "OS:");

	return $os;
}

function select_machine($arg)
{
	$machs = get_machine_list();

	if ($arg !== false) {
		foreach ($machs as $mach) {
			if ($arg == $mach->name || $arg == $mach->mac) {
				$m = $mach;
				break;
			}
		}
	}

	if (!isset($m) && $arg !== false) {
		echo "Machine with name/mac ".$arg." not found\n";
		return false;
	}

	if ($arg === false) {
		cmd_list();
		$m = Util::ask_from_array($machs, "Machine: ");
	}

	$mach = new Machine();
	$mach->fill_from_xmlobj($m);

	return $mach;
}

function cmd_delete($arg)
{
	$mach = select_machine($arg);

	if ($mach !== false) {
		$name = $mach->name;
		Settings::delete_machine($mach);
		$path = MAMA_PATH."/machines/".$name;
		if (Util::is_root() === true)
			shell_exec("rm -Rf ".$path);
		else
			echo "You're not root! You must manually delete installed OSes at ".$path."\n";
	}

}

function cmd_info($arg)
{
	$mach = select_machine($arg);

	if ($mach !== false)
		$mach->print_info();
}

function cmd_start($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	echo "Starting machine ".$mach->name." with OS ".$mach->os."\n";
	$mach->start();
}

function cmd_start_vm($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	// Find free tap device
	for ($tap_id = 0; $tap_id <= 10; $tap_id++) {
		$ret = shell_exec("ps aux | grep qemu | grep tap".$tap_id." | wc -l");
		$ret = (int)$ret;
		if ($ret == 1)
			break;
	}

	if ($tap_id > 10) {
		echo "No available tap interface found\n";
		return;
	}

	echo "Starting virtual machine for ".$mach->name." with OS ".$mach->os." and tap".$tap_id."\n";
	$num_cores = (int)shell_exec("nproc");
	$sys_str = "-enable-kvm -smp ".$num_cores." -m ".(1024 * 8);
	$net_str = "-boot n -option-rom /usr/share/qemu/pxe-virtio.rom -netdev tap,id=net0,ifname=tap".
		   $tap_id.",script=no,downscript=no -device e1000,netdev=net0,mac=".$mach->mac;
	switch ($mach->arch) {
	case "i686":
		$arch = "i386";
		break;
	default:
		$arch = "x86_64";
	}
	passthru("qemu-system-".$arch." ".$sys_str." ".$net_str." -nographic -serial file:/tmp/mama-virt-out-".$mach->name);
}

function cmd_stop($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	$mach->stop();
}

function cmd_connect($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	passthru("ssh -o \"UserKnownHostsFile=/dev/null\" -o \"StrictHostKeyChecking=no\" root@".$mach->get_ip_from_mac());
}

function cmd_set($argv)
{
	if (isset($argv[2]))
		$arg_mach = $argv[2];
	else
		$arg_mach = false;
	$mach = select_machine($arg_mach);

	/* Machine not found */
	if ($mach === false)
		return;

	if (!isset($argv[3]))
		$attr = Util::get_line("{power | relay | os}: ");
	else
		$attr = $argv[3];

	if (!isset($argv[4]))
		$val = false;
	else
		$val = $argv[4];

	switch ($attr) {
	case "power":
		if ($val === false)
			$val = Util::get_line("Power (0-1): ");
		break;
	case "relay":
		if ($val === false)
			$val = Util::get_line("Time (secs): ");
		break;
	case "os":
		if ($val === false)
			$val = select_os($mach);
		break;
	}

	$mach->set($attr, $val);
}

function print_usage($argv)
{ ?>
Commands:
list					- list configured machines
list-os	[machine]			- list available OSes
list-kernel [machine]			- list kernels for current OS
list-jobs				- list available jobs
log [machine]				- print log file
new					- add a new machine
delete [machine]			- delete existing machine
info [machine]				- show machine detailed info
start [machine]				- boot machine
start-vm [machine]			- boot virtual instance of machine
stop [machine]				- triggers power button
connect [machine]			- connect to machine with ssh
set <machine> power <value>		- control wall power
set <machine> relay <seconds>		- connect the relay for x seconds
set <machine> os <os>			- set os for machine
set <machine> kernel <kernel>		- set kernel for machine

<?php
}
?>
