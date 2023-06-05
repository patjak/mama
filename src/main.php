<?php

if ($argc > 1) {
	if (Settings::load() === false)
		fatal("Failed to open settings file: ".SETTINGS_FILE."\n");

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
	case "list-kernel":
		cmd_list_kernel($arg);
		break;
	case "job":
		cmd_job($argv);
		break;
	case "build-os":
		cmd_build_os($argv);
		break;
	case "run-os-build-script":
		cmd_run_os_build_script($argv);
		break;
	case "install-os":
		cmd_install_os($argv);
		break;
	case "copy-os":
		cmd_copy_os($argv);
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
	case "log":
		cmd_log($arg);
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
	case "reserve":
		cmd_reserve($arg);
		break;
	case "release":
		cmd_release($arg);
		break;
	case "release-forced":
		cmd_release_forced($arg);
		break;
	case "ipxe":
		cmd_ipxe($argv);
		break;
	default:
		out("Invalid command\n");
	}
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
	$pwr_devs = array();

	$i = 1;
	out("No\tName\t\tIP\t\tState\tPower\tVM\tJob");
	out("--------------------------------------------------------------------------------");

	$machs = Machine::get_all();

	foreach ($machs as $mach) {
		$pwr_dev = CtlDev::get_by_name($mach->pwr_dev);

		if (isset($pwr_devs[$mach->pwr_dev]))
			$pwr_devs[$mach->pwr_dev] = $pwr_dev->get_sensors_all_slots();

		if (!isset($pwr_devs[$mach->pwr_dev]) || $pwr_devs[$mach->pwr_dev] === NULL) {
			$power = "-";
		} else {
			$sensor = $pwr_devs[$mach->pwr_dev][$mach->pwr_slot - 1];
			$power = $sensor['power'];
		}

		$ip = $mach->get_ip();

		// Number
		out(Util::pad_str($i++, 8), TRUE);

		// Name
		out(Util::pad_str($mach->name, 16), TRUE);

		// IP 
		out(Util::pad_str($ip, 16), TRUE);

		// State
		out(Util::pad_str($mach->get_status(), 8), TRUE);

		// Power
		out(Util::pad_str($power, 8), TRUE);

		// Is VM?
		out(Util::pad_str($mach->is_vm() ? "Yes" : "", 4));
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
				error("Name already exists");
				$name = "";
			}
		}
	}

	$m = new Machine();

	$mac = "";
	while (!Util::is_valid_mac($mac))
		$mac = Util::get_line("MAC: ");

	// Print buildable architectures
	$archs = Arch::get_buildable();
	$i = 1;
	out("\nNo\tArch");
	out("--------------------------------");

	foreach ($archs as $arch) {
		out(Util::pad_str($i++, 8), TRUE);
		out($arch);
	}

	$arch = Util::ask_from_array($archs, "Arch");
	out("Arch selected: ".$arch."\n");

	$os = "";

	$only_vm = Util::ask_yesno("Is this only a virtual machine?");
	if ($only_vm == "y")
		$only_vm = 1;
	else
		$only_vm = 0;

	// Don't bother with power devices or relay devices if machine is only virtual
	if ($only_vm == 0) {
		// Select power device
		out("No\tPower device\tSlots");
		out("--------------------------------");
		$i = 1;
		foreach (CtlDev::$devs as $dev) {
			out(Util::pad_str($i++, 8), TRUE);
			out(Util::pad_str($dev->name, 16), TRUE);
			out(Util::pad_str($dev->slots, 8));
		}

		$pwr_dev = Util::ask_from_array(CtlDev::$devs, "Power device: ");
		out("Device selected: ".$pwr_dev->name);
		if ($pwr_dev->slots > 0)
			$pwr_slot = Util::ask_number($pwr_dev->slots, "Power device slot: ");
		else
			$pwr_slot = 0;

		// Select relay device
		out("No\tRelay device\tSlots");
		out("--------------------------------");
		$i = 1;
		foreach (CtlDev::$devs as $dev) {
			out(Util::pad_str($i++, 8), TRUE);
			out(Util::pad_str($dev->name, 16), TRUE);
			out(Util::pad_str($dev->slots, 8));
		}

		$rly_dev = Util::ask_from_array(CtlDev::$devs, "Relay device: ");
		out("Device selected: ".$rly_dev->name);
		if ($rly_dev->slots > 0)
			$rly_slot = Util::ask_number($rly_dev->slots, "Relay device slot: ");
		else
			$rly_slot = 0;

		$m->pwr_dev = $pwr_dev->name == "- None -" ? "" : $pwr_dev->name;
		$m->pwr_slot = $pwr_slot;
		$m->rly_dev = $rly_dev->name == "- None -" ? "" : $rly_dev->name;
		$m->rly_slot = $rly_slot;
	}

	$m->name = $name;
	$m->mac = $mac;
	$m->arch = $arch;
	$m->os = $os;
	$m->state = "offline";
	$m->kernel = "";
	$m->only_vm = $only_vm;

	Settings::add_machine($m);

	shell_exec("mkdir -p ".MAMA_PATH."/machines/".$name);

	Settings::save();

	out("New machine created");
}

function cmd_list_os($m = false)
{
	if ($m !== false) {
		$mach = select_machine($m);
		out("Listing installed OSes for machine: ".$mach->name);
		$path = MAMA_PATH."/machines/".$mach->name."/";
	} else {
		$path = MAMA_PATH."/os/";
		$mach = false;
	}

	out("No\tOS");
	out("--------------------------------------------------------------------------------");

	$oses = Util::get_directory_contents($path, 2);

	$i = 1;
	foreach ($oses as $os) {
		out(Util::pad_str($i++." ", 8), TRUE);
		out(Util::pad_str($os, 32));
	}
}

function cmd_list_kernel($m)
{
	$mach = select_machine($m);
	if ($mach === false)
		exit(1);

	out("Listing available kernels for machine: ".$mach->name);
	list_kernels($mach);
}

function cmd_job($args)
{
	if (!isset($args[3])) {
		error("Not enough arguments");
		return;
	}

	$cmd = $args[3];

	switch ($cmd) {
	case "prepare":
		if (!isset($args[5])) {
			error("Not enough arguments");
			return;
		}

		$job = new Job($args[2]);
		$job->execute_prepare_job($args[4], $args[5]);
		break;

	case "run":
		if (!isset($args[6])) {
			error("Not enough arguments");
			return;
		}

		$job = new Job($args[2]);
		$job->execute_run_job($args[4], $args[5], $args[6]);
		break;

	default:
		error("Invalid command");
	}

}

function cmd_build_os($args)
{
	if (!isset($args[3])) {
		error("Not enough arguments");
		return;
	}

	$arch = $args[2];
	$os = $args[3];
	if (isset($args[4]))
		$builder = $args[4];
	else
		$builder = "os-builder";

	if (!Arch::is_buildable($arch))
		fatal("Invalid architecture: ".$arch);

	if (!Os::is_buildable($arch, $os))
		fatal("Invalid os: ".$arch."/".$os);

	$mama_arch = trim(shell_exec("uname -p"));

	// Build locally if we are on the same architecture and no builder machine is specified
	if ($mama_arch == $arch && $builder == "os-builder")
		cmd_run_os_build_script($args);
	else
		passthru("sudo mama job os-builder run ".$builder." ".$arch." ".$os);
}

// This command builds a kiwi appliance from an existing os-description
// It's supposed to be executed from the os-builder job since it only builds
// appliances for the running host system architecture. Hence we run it as a
// job inside a VM for the architecture we need.
function cmd_run_os_build_script($args)
{
	if (!Util::is_root())
		out("You must be root to run this command");

	if (!isset($args[3])) {
		error("Not enough arguments");
		return;
	}

	$arch = $args[2];
	$os = $args[3];

	$archs = Util::get_directory_contents(MAMA_PATH."/os-descriptions/", 1);
	$oses = Util::get_directory_contents(MAMA_PATH."/os-descriptions/", 2);

	if (!in_array($arch, $archs))
		fatal("Invalid architecture: ".$arch);

	if (!in_array($arch."/".$os, $oses))
		fatal("Invalid os: ".$arch."/".$os);

	$target = MAMA_PATH."/os/".$arch."/".$os;
	$desc = MAMA_PATH."/os-descriptions/".$arch."/".$os;

	passthru("sudo rm -Rf /tmp/mama-kiwi");

	unset($code);
	passthru("sudo kiwi-ng --type=kis --debug system build --description ".$desc." --target-dir /tmp/mama-kiwi", $code);
	if ($code != 0)
		fatal("Failed to build os with kiwi");

	unset($code);
	passthru("sudo rm -Rf ".$target, $code);
	if ($code != 0)
		fatal("Failed to remove old version of os: ".$target);

	passthru("sudo mkdir -p ".$target);

	unset($code);
	passthru("sudo mv /tmp/mama-kiwi/* ".$target, $code);
	if ($code != 0)
		fatal("Failed to store new os build");

	unset($code);
	passthru("sudo cp ".$target."/*.initrd ".$target."/build/image-root/boot/initrd-mama", $code);
	if ($code != 0)
		fatal("Failed to create initrd default links for new os");

	unset($code);
	passthru("sudo chmod 644 ".$target."/build/image-root/boot/initrd-mama", $code);
	if ($code != 0)
		fatal("Failed to change permission on initrd");

	unset($code);
	passthru("sudo cp ".$target."/*.kernel ".$target."/build/image-root/boot/kernel-mama", $code);
	if ($code != 0)
		fatal("Failed to create kernel default links for new os");

	// Make sure the web server have permissions to serve the initrd
	unset($code);
	passthru("sudo chmod 644 ".$target."/*initrd*", $code);
	if ($code != 0)
		fatal("Failed to set permissions on initrd");

	// Copy the authorized_keys file so ssh commands can be executed by mama
	unset($code);
	passthru("sudo mkdir -p ".$target."/build/image-root/root/.ssh && sudo cp ".MAMA_PATH."/authorized_keys ".$target."/build/image-root/root/.ssh/", $code);
	if ($code != 0)
		fatal("Failed to copy authorized_keys");

}

function cmd_install_os($args)
{
	if (!Util::is_root())
		out("You must be root to run this command");

	if (!isset($args[4]))
		fatal("Not enough arguments");

	exec("cd ".MAMA_PATH, $out, $res);
	if ($res != 0)
		fatal("Failed to find mama directory");

	$machine = $args[2];
	$arch = $args[3];
	$os = $args[4];

	if (isset($args[5]))
		$name = $args[5];
	else
		$name = $args[4];

	$mach = select_machine($machine);
	if ($mach === FALSE)
		fatal("Invalid machine name");

	if (!Arch::is_installable($arch, $mach))
		fatal("Invalid architechture");

	if (!Os::is_installable($arch, $os, $mach))
		fatal("Invalid os");

	$src = MAMA_PATH."/os/".$arch."/".$os;
	$dst = MAMA_PATH."/machines/".$machine."/".$arch."/".$name;

	unset($res);
	passthru("sudo rm -Rf ".$dst, $res);
	if ($res != 0)
		fatal("Failed to remove previous installation of the OS");

	unset($res);
	passthru("sudo mkdir -p ".$dst, $res);
	if ($res != 0)
		fatal("Failed to create directory for new OS");

	unset($res);
	passthru("sudo cp -r --reflink=auto ".$src."/build/image-root/* ".$dst, $res);
	if ($res != 0)
		fatal("Failed to copy new OS to destination");

	out("OS install succeeded!");

	return FALSE;
}

function cmd_copy_os($args)
{
	if (!Util::is_root()) {
		error("You must be root to run this command");
		return;
	}

	if (!isset($args[5])) {
		error("Not enough arguments");
		return;
	}

	$src_mach = $args[2];
	$arch = $args[3];
	$os = $args[4];
	$dst_mach = $args[5];

	if ($src_mach == $dst_mach) {
		out("Source and destination machines are the same. Skipping copy.");
		return;
	}

	out("Copying os ".$arch."/".$os." from ".$src_mach." to ".$dst_mach);
	exec("rm -Rf ".MAMA_PATH."/machines/".$dst_mach."/".$arch."/".$os);
	exec("mkdir -p ".MAMA_PATH."/machines/".$dst_mach."/".$arch."/".$os);
	exec("cp -r --reflink=auto ".MAMA_PATH."/machines/".$src_mach."/".$arch."/".$os." ".
	     MAMA_PATH."/machines/".$dst_mach."/".$arch."/");
}

function select_os($mach)
{
	$path = MAMA_PATH."/machines/".$mach->name."/";
	$oses = Util::get_directory_contents($path, 2);

	out("No\tArch\t\tOS");
	out("--------------------------------------------------------------------------------");

	$i = 1;
	foreach ($oses as $os) {
		$os = explode("/", $os);
		$arch = $os[0];
		$os = $os[1];
		out(Util::pad_str($i++." ", 8), TRUE);
		out(Util::pad_str($arch, 16), TRUE);
		out(Util::pad_str($os, 32));
	}

	$os = Util::ask_from_array($oses, "OS:");

	return $os;
}

function list_kernels($mach)
{
	$kernels = $mach->get_kernels();

	out("No\tKernel\t\t\t\t\t\tLast modified");
	out("--------------------------------------------------------------------------------");

	$i = 1;
	$path = $mach->get_kernel_path();
	foreach ($kernels as $kernel => $date) {
		out(Util::pad_str($i++." ", 8), TRUE);
		out(Util::pad_str($kernel, 48), TRUE);
		out(Util::pad_str($date, 20));
	}
}

function select_kernel($mach)
{
	list_kernels($mach);

	$kernels = $mach->get_kernels();
	$names = array();

	foreach ($kernels as $name => $date) {
		$names[] = $name;
	}

	$kernel = Util::ask_from_array($names, "Kernel:");

	if ($kernel == "default")
		$kernel = "";

	return $kernel;
}

function select_machine($arg)
{
	Log::set_file(FALSE);

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
		error("Machine with name/mac ".$arg." not found");
		return false;
	}

	if ($arg === false) {
		cmd_list();
		$m = Util::ask_from_array($machs, "Machine: ");
	}

	$mach = new Machine();
	$mach->fill_from_xmlobj($m);

	Log::set_file($mach->name);

	return $mach;
}

function cmd_delete($arg)
{
	$mach = select_machine($arg);

	if ($mach !== false) {
		$name = $mach->name;
		if ($name == "") {
			error("Machine name is NULL");
			return;
		}
		Settings::delete_machine($mach);
		$path = MAMA_PATH."/machines/".$name;
		if (Util::is_root() === true)
			shell_exec("rm -Rf ".$path);
		else
			error("You're not root! You must manually delete installed OSes at ".$path);
	}

}

function cmd_info($arg)
{
	$mach = select_machine($arg);

	if ($mach !== false)
		$mach->print_info();
}

function cmd_log($arg)
{
	if (!is_string($arg))
		$arg = "mama-log";

	if (file_exists(MAMA_PATH."/log/".$arg))
		passthru("tail -f -n 200 ".MAMA_PATH."/log/".$arg);
	else
		error("No log for ".$arg." exists");
}

function cmd_start($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	out("Starting machine ".$mach->name." with OS ".$mach->os);
	$mach->start();
}

function cmd_start_vm($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	$mach->start_vm();
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

function cmd_reserve($arg)
{
	$mach = select_machine($arg);
	if ($mach === false)
		return;

	if ($mach->reservation != "")
		fatal("Machine is already reserved by: ".$mach->reservation);

	$user = trim(shell_exec("whoami"));
	$mach->reservation = $user;
	Settings::update_machine($mach);
}

function cmd_release($arg)
{
	$mach = select_machine($arg);
	if ($mach === false)
		return;

	if ($mach->reservation == "")
		fatal("Machine is not reserved");

	$user = trim(shell_exec("whoami"));
	if ($mach->reservation != $user) {
		fatal("Failed to release machine. You do not hold the reservation. ".
		      "Use the command release-forced to force release the machine.");
	}
	$mach->reservation = "";
	Settings::update_machine($mach);
}

function cmd_release_forced($arg)
{
	$mach = select_machine($arg);
	if ($mach === false)
		return;

	$mach->reservation = "";
	Settings::update_machine($mach);
}

// Generate the ipxe commands needed to boot a machine
function cmd_ipxe($argv)
{
	out("#!ipxe");

	if (!isset($argv[2]))
		fatal("No MAC specified");

	if (!isset($argv[3]))
		fatal("No client IP specified");

	if (!isset($argv[4]))
		fatal("No server IP specified");

	$mac = $argv[2];
	$client_ip = $argv[3];
	$server_ip = $argv[4];

	$mach = Machine::find_by_mac($mac);
	if ($mach === false)
		fatal("echo FATAL ERROR: Machine is not configured in Mama!");

	// Store the ip for this session
	if (Util::is_valid_ip($client_ip)) {
		$mach->ip = $client_ip;
		Settings::update_machine($mach);
	}

	$path = "http://".$server_ip."/mama/machines/$mach->name/".$mach->os;
	$root = "root=nfs:".$server_ip.":".MAMA_PATH."/machines/".$mach->name."/".$mach->os.",rw";

	$console = "";
	if ($mach->is_vm())
		$console .= "console=tty0 console=ttyS0";

	$net = "rd.neednet=1 ifname=bootnet:".$mac." bootdev=bootnet ".
		"ip=".$client_ip."::".$server_ip.":255.255.255.0:".$mach->name.":bootnet:off ".
		"systemd.hostname=".$mach->name;

	if ($mach->kernel == "") {
		$kernel_filename = "kernel-mama";
		$initrd_filename = "initrd-mama";
	} else {
		$kernel_filename = "vmlinuz-".$mach->kernel;
		$initrd_filename = "initrd-".$mach->kernel;
	}

	$params = $root." ".$console." ".$net." ".$mach->boot_params;

	// IMPORTANT: For UEFI to boot properly initrd=<filename> must be specified
	$kernel = $path."/boot/".$kernel_filename." ".$params." initrd=".$initrd_filename;
	$initrd = $path."/boot/".$initrd_filename;

	out("initrd ".$initrd." ||");
	out("kernel ".$kernel." ||");
	out("boot || shell");
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
	case "kernel":
		if ($val === false)
			$val = select_kernel($mach);
		break;
	case "resources":
		
	}

	$mach->set($attr, $val);
}

function print_usage($argv)
{ ?>
Commands:
list						- list configured machines
list-os	[machine]				- list available OSes
list-kernel [machine]				- list available kernels
list-jobs					- list available jobs
log [machine]					- print log file
job <job> prepare <arch> <os>			- Execute prepare part of job for arch and os
job <job> run <machine>	<arch> <os>		- Execute job on specified machine
build-os <arch> <os> [machine]			- build a deployable os locally or on specified machine
install-os <machine> <arch> <os> [name]		- install os to a machine
copy-os <src-mach> <arch> <os> <dst-mach>	- copy an os from one machine to another
new						- add a new machine
delete [machine]				- delete existing machine
info [machine]					- show machine detailed info
log [machine]					- show machine log
start [machine]					- boot machine
start-vm [machine]				- boot virtual instance of machine
stop [machine]					- triggers power button
connect [machine]				- connect to machine with ssh
set <machine> power <value>			- control wall power
set <machine> relay <seconds>			- connect the relay for x seconds
set <machine> os <os>				- set os for machine
set <machine> kernel <kernel>			- set kernel for machine
set <machine> resources <resources>		- comma separated list of resources to set
set <machine> params <boot parameters>		- Set additional kernel command line parameters
reserve <machine>				- Reserve the machine for the current user
release <machine>				- Release any reservation you have on the machine
release-forced <machine>			- Release the machine even if you didn't reserve it

<?php
}
?>
