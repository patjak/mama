<?php

$opts = array(	"args:",
		"debug",
		"debug-lock",
		"packages:",
		"no-ssh-timeout");

$cmds = Options::parse($argv, $opts);

if (isset(Options::$options["debug"]))
	define("DEBUG", TRUE);
else
	define("DEBUG", FALSE);

if (isset(Options::$options["debug-lock"]))
	define("DEBUG_LOCK", TRUE);
else
	define("DEBUG_LOCK", FALSE);

if ($argc > 1) {
	if (Settings::load() === false)
		fatal("Failed to open settings file: ".SETTINGS_FILE."\n");

	parse_args($cmds);

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
	case "list-resources":
		cmd_list_resources($arg);
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
	case "tail":
		cmd_tail($arg);
		break;
	case "clear-log":
		cmd_clear_log($arg);
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
	case "clear":
		cmd_clear($arg);
		break;
	case "connect":
		cmd_connect($arg);
		break;
	case "set":
		cmd_set($argv);
		break;
	case "get":
		cmd_get($argv);
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
	case "wait":
		cmd_wait($arg);
		break;
	case "ipxe":
		cmd_ipxe($argv);
		break;
	case "bootinfo":
		cmd_bootinfo($argv);
		break;
	default:
		out("Invalid command\n");
	}
}

function cmd_list()
{
	$pwr_devs = array();

	$i = 1;
	$len = 0;
	$len += out(Util::pad_str("No", 4), TRUE);
	$len += out(Util::pad_str("Name", 16), TRUE);
	$len += out(Util::pad_str("IP", 16), TRUE);
	$len += out(Util::pad_str("MAC", 18), TRUE);
	$len += out(Util::pad_str("State", 8), TRUE);
	$len += out(Util::pad_str("On", 5), TRUE);
	$len += out(Util::pad_str("Pwr", 6), TRUE);
	$len += out(Util::pad_str("VM", 4), TRUE);
	out(Util::pad_str("Job", 32));
	$len += 8;
	for( $j = 0; $j < $len; $j++)
		out("-", TRUE);
	out("");

	$machs = Machine::get_all();

	foreach ($machs as $mach) {
		$pwr_dev = CtlDev::get_by_name($mach->pwr_dev);

		if ($pwr_dev !== FALSE && !isset($pwr_devs[$mach->pwr_dev]))
			$pwr_devs[$mach->pwr_dev] = $pwr_dev->get_sensors_all_slots();

		if (!isset($pwr_devs[$mach->pwr_dev]) || $pwr_devs[$mach->pwr_dev] === NULL) {
			$power = "-";
		} else {
			$sensor = $pwr_devs[$mach->pwr_dev][$mach->pwr_slot - 1];
			$power = $sensor['power'];
		}

		$ip = $mach->get_ip();

		// Number
		out(Util::pad_str($i++, 4), TRUE);

		// Name
		out(Util::pad_str($mach->name, 16), TRUE);

		// IP 
		out(Util::pad_str($ip, 16), TRUE);

		// MAC
		out(Util::pad_str($mach->mac, 18), TRUE);

		// State
		out(Util::pad_str($mach->get_status(), 8), TRUE);

		// Started
		$started = $mach->is_started == 1 ? "Yes" : "";
		out(Util::pad_str($started, 5), TRUE);

		// Power
		if (is_numeric($power)) {
			$power = round($power, 0);
			$power .= $power > 0 ? "W" : "";
		}
		out(Util::pad_str($power, 6), TRUE);

		// Is VM?
		out(Util::pad_str($mach->is_vm() ? "Yes" : "", 4), TRUE);

		// Currently running job
		out($mach->job);
	}
}

function cmd_new()
{
	$name = "";
	while ($name == "") {
		$name = Util::get_line("Name: ");

		$machs = Machine::get_all();
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

	if (!Util::is_root())
		out("You must be root to run this command");

	$path = MAMA_PATH."/machines/".$name;
	passthru("sudo mkdir -p ".$path);

	// Create a file used for machine specific locking
	passthru("sudo touch ".$path."/lock");
	passthru("sudo chmod 666 ".$path."/lock");

	Settings::add_machine($m);

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
		out($os);
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

function cmd_list_resources()
{
	$machs = Machine::get_all();

	$resources = array();

	foreach ($machs as $mach) {
		$m_res = explode(" ", $mach->resources);
		foreach($m_res as $res)
			if (isset($resources[$res]))
				$resources[$res] .= " ".$mach->name;
			else
				$resources[$res] = $mach->name;

			if ($mach->is_started == 1)
				$resources[$res] .="*";
	}

	ksort($resources);

	foreach ($resources as $res => $machs) {
		if ($res == "")
			continue;

		out($res.":\t".$machs);
	}
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

		$worker = isset($args[6]) ? $args[6] : "jobs";

		$job = new Job($args[2]);
		$job->execute_prepare_job($args[4], $args[5], $worker);
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

	// Any additional packages will be passed to the build jobs as args
	$packages = isset(Options::$options["packages"]) ? Options::$options["packages"] : "";

	$arch = $args[2];
	$os = $args[3];
	if (isset($args[4]))
		$builder = $args[4];
	else
		$builder = "os-builder";

	$builder_mach = select_machine($builder);
	if ($builder_mach === FALSE)
		fatal("Invalid builder machine name");

	if (!Arch::is_buildable($arch))
		fatal("Invalid architecture: ".$arch);

	if (!Os::is_buildable($arch, $os))
		fatal("Invalid os: ".$arch."/".$os);

	$mama_arch = trim(shell_exec("uname -p"));

	$need_sudo = Util::is_root() ? "" : "sudo";

	// Build locally if we are on the same architecture and no builder machine is specified
	if ($mama_arch == $arch && $builder_mach->is_only_vm())
		cmd_run_os_build_script($args);
	else
		passthru($need_sudo." mama job os-builder run ".$builder." ".$arch." ".$os." --args=\"".$packages."\"");
}

// This command builds a kiwi appliance from an existing os-description
// It's supposed to be executed from the os-builder job since it only builds
// appliances for the running host system architecture. Hence we run it as a
// job inside a VM or on a worker for the architecture we need.
function cmd_run_os_build_script($args)
{
	$packages = isset(Options::$options["packages"]) ? explode(" ", Options::$options["packages"]) : array();

	$packages_str = "";
	foreach ($packages as $package)
		$packages_str .= " --add-package=".$package;

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

	$need_sudo = Util::is_root() ? "" : "sudo";

	$tmp_dir = "/dev/shm/mama-kiwi";

	passthru($need_sudo." rm -Rf ".$tmp_dir);

	unset($code);
	passthru($need_sudo." kiwi-ng --type=kis --debug system build --description ".$desc." --target-dir ".$tmp_dir." ".$packages_str, $code);
	if ($code != 0)
		fatal("Failed to build os with kiwi");

	unset($code);
	passthru($need_sudo." rm -Rf ".$target, $code);
	if ($code != 0)
		fatal("Failed to remove old version of os: ".$target);

	passthru($need_sudo." mkdir -p ".$target);

	unset($code);
	passthru($need_sudo." mv ".$tmp_dir."/* ".$target, $code);
	if ($code != 0)
		fatal("Failed to store new os build");

	unset($code);
	passthru($need_sudo." cp ".$target."/*.initrd ".$target."/build/image-root/boot/initrd-mama", $code);
	if ($code != 0)
		fatal("Failed to create initrd default links for new os");

	unset($code);
	passthru($need_sudo." chmod 644 ".$target."/build/image-root/boot/initrd-mama", $code);
	if ($code != 0)
		fatal("Failed to change permission on initrd");

	unset($code);
	passthru($need_sudo." cp ".$target."/*.kernel ".$target."/build/image-root/boot/kernel-mama", $code);
	if ($code != 0)
		fatal("Failed to create kernel default links for new os");

	// Make sure the web server have permissions to serve the initrd
	unset($code);
	passthru($need_sudo." chmod 644 ".$target."/*initrd*", $code);
	if ($code != 0)
		fatal("Failed to set permissions on initrd");

	// Copy the authorized_keys file so ssh commands can be executed by mama
	unset($code);
	passthru($need_sudo." mkdir -p ".$target."/build/image-root/root/.ssh && ".$need_sudo." cp ".MAMA_PATH."/authorized_keys ".$target."/build/image-root/root/.ssh/", $code);
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

	$need_sudo = Util::is_root() ? "" : "sudo";

	unset($res);
	passthru($need_sudo." rm -Rf ".$dst, $res);
	if ($res != 0)
		fatal("Failed to remove previous installation of the OS");

	unset($res);
	passthru($need_sudo." mkdir -p ".$dst, $res);
	if ($res != 0)
		fatal("Failed to create directory for new OS");

	unset($res);
	passthru($need_sudo." cp -r --reflink=auto ".$src."/build/image-root/* ".$dst, $res);
	if ($res != 0)
		fatal("Failed to copy new OS to destination");

	out("OS install succeeded!");

	return FALSE;
}

function cmd_copy_os($args)
{
	if (!Util::is_root())
		error("You must be root to run this command");

	$need_sudo = Util::is_root() ? "" : "sudo";

	if (!isset($args[5])) {
		error("Not enough arguments");
		return;
	}

	$src_mach = Machine::get_by_name($args[2]);
	if ($src_mach === FALSE)
		fatal("Couldn't find machine with name ".$args[2]);

	$arch = $args[3];
	$os = $args[4];

	$dst_mach = Machine::get_by_name($args[5]);
	if ($dst_mach === FALSE)
		fatal("Couldn't find machine with name ".$args[5]);

	if ($src_mach->name == $dst_mach->name) {
		out("Source and destination machines are the same. Skipping copy.");
		return;
	}

	$src_mach->lock();
	$dst_mach->lock();
	out("Copying os ".$arch."/".$os." from ".$src_mach->name." to ".$dst_mach->name);
	exec($need_sudo." rm -Rf ".MAMA_PATH."/machines/".$dst_mach->name."/".$arch."/".$os);
	exec($need_sudo." mkdir -p ".MAMA_PATH."/machines/".$dst_mach->name."/".$arch."/".$os);
	exec($need_sudo." cp -r --reflink=auto ".MAMA_PATH."/machines/".$src_mach->name."/".$arch."/".$os." ".
	     MAMA_PATH."/machines/".$dst_mach->name."/".$arch."/");
	$dst_mach->unlock();
	$src_mach->unlock();
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
	out(Util::pad_str($i++." ", 8), TRUE);
	out(Util::pad_str("Latest", 48));
}

function select_kernel($mach)
{
	list_kernels($mach);

	$kernels = $mach->get_kernels();
	$names = array();

	$latest = "";
	$latest_date = "";
	foreach ($kernels as $name => $date) {
		if ($date >= $latest_date) {
			$latest_date = $date;
			$latest = $name;
		}
		$names[] = $name;
	}
	$names[] = "Latest";

	$kernel = Util::ask_from_array($names, "Kernel:");

	if ($kernel == "default")
		$kernel = "";

	if (strtolower($kernel) == "Latest")
		$kernel = $latest;

	return $kernel;
}

function select_machine($arg)
{
	Log::set_file(FALSE);

	$machs = Machine::get_all();

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

	// Don't log this command. It clutters up the log.
	Log::set_file(FALSE);

	if ($mach !== false)
		$mach->print_info();
}

function cmd_log($arg)
{
	if (!is_string($arg))
		$arg = "mama-log";

	if (file_exists(MAMA_PATH."/log/".$arg))
		passthru("cat ".MAMA_PATH."/log/".$arg);
	else
		error("No log for ".$arg." exists");
}

function cmd_tail($arg)
{
	if (!is_string($arg))
		$arg = "mama-log";

	if (file_exists(MAMA_PATH."/log/".$arg))
		passthru("tail -f ".MAMA_PATH."/log/".$arg);
	else
		error("No log for ".$arg." exists");
}

function cmd_clear_log($arg)
{
	if (!is_string($arg))
		$arg = "mama-log";

	if (file_exists(MAMA_PATH."/log/".$arg))
		passthru("rm ".MAMA_PATH."/log/".$arg);
	else
		error("No log for ".$arg." exists");
}

function cmd_start($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	$mach->out("Starting machine with OS ".$mach->os);
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

function cmd_clear($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	$mach->stop();
	$mach->clear();
	$mach->clear_job();
}

function cmd_connect($arg)
{
	$mach = select_machine($arg);

	if ($mach === false)
		return;

	passthru("ssh -o \"UserKnownHostsFile=/dev/null\" -o \"StrictHostKeyChecking=no\" root@".$mach->get_ip());
}

function cmd_reserve($arg)
{
	$mach = select_machine($arg);
	if ($mach === false)
		return;

	if ($mach->reservation != "")
		fatal("Machine is already reserved by: ".$mach->reservation);

	$user = trim(shell_exec("whoami"));
	$mach->reserve($user);
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

	$mach->reserve("");
}

function cmd_release_forced($arg)
{
	$mach = select_machine($arg);
	if ($mach === false) {
		return;
	}

	$mach->reserve = "";
}

function cmd_wait($arg)
{
	$mach = select_machine($arg);
	if ($mach === FALSE)
		return;

	$mach->wait();
}

function bootinfo(&$kernel, &$initrd, &$append, $mac, $server_ip)
{
	$mach = Machine::find_by_mac($mac);
	if ($mach === false)
		fatal("Machine is not configured in Mama!");

	$path = "http://".$server_ip."/mama/machines/$mach->name/".$mach->os;
	$root = "root=nfs:".$server_ip.":".MAMA_PATH."/machines/".$mach->name."/".$mach->os.",rw";

	$console = "";
	if ($mach->is_vm())
		$console .= "console=tty0 console=ttyS0";

	$net = "rd.neednet=1 systemd.hostname=".$mach->name." ip=dhcp";

	if ($mach->kernel == "") {
		$kernel_filename = "kernel-mama";
		$initrd_filename = "initrd-mama";
	} else {
		$kernel_filename = "vmlinuz-".$mach->kernel;
		$initrd_filename = "initrd-".$mach->kernel;
	}

	$params = $root." ".$console." ".$net." ".$mach->boot_params;

	// IMPORTANT: For UEFI to boot properly initrd=<filename> must be specified
	$kernel = $path."/boot/".$kernel_filename;
	$initrd = $path."/boot/".$initrd_filename;
	$append = $params." initrd=".$initrd_filename;
}

// Generate the ipxe commands needed to boot a machine
function cmd_ipxe($argv)
{
	out("#!ipxe");

	if (!isset($argv[2]))
		fatal("No MAC specified");

	if (!isset($argv[3]))
		fatal("No server IP specified");

	$mac = $argv[2];
	$server_ip = $argv[3];

	$kernel = "";
	$initrd = "";
	$append = "";

	bootinfo($kernel, $initrd, $append, $mac, $server_ip);

	out("initrd ".$initrd." ||");
	out("kernel ".$kernel." ".$append." ||");
	out("boot || shell");
}

function cmd_bootinfo($argv)
{
	$kernel = "";
	$initrd = "";
	$append = "";

	if (!isset($argv[2]))
		fatal("No MAC specified");

	if (!isset($argv[3]))
		fatal("No server IP specified");

	$mac = $argv[2];
	$server_ip = $argv[3];

	bootinfo($kernel, $initrd, $append, $mac, $server_ip);

	out($initrd);
	out($kernel);
	out($append);
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
		$attr = Util::get_line("{power | relay | os | kernel | resources | params }: ");
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

function cmd_get($argv)
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
		$attr = Util::get_line("{power | relay | os | params | resources | kernel | reservation | ip | mac}: ");
	else
		$attr = $argv[3];

	out($mach->get($attr));

}

function print_usage($argv)
{ ?>
Commands:
list						- list configured machines
list-os	[machine]				- list available OSes
list-kernel [machine]				- list available kernels
list-jobs					- list available jobs
list-resources					- list all resources and what is using them
log [machine]					- print log file
job <job> prepare <arch> <os> [worker]		- Execute prepare part of job for arch and os
  [--args=] - Additional arguments to pass along
  [--no-ssh-timeout] - Allows ssh commands to run indefinitely

job <job> run <machine>	<arch> <os>		- Execute job on specified machine
  [--args=] - Additional arguments to pass along
  [--no-ssh-timeout] - Allows ssh commands to run indefinitely

build-os <arch> <os> [worker]			- build a deployable os locally or on specified machine
  [--packages=...] - Additional packages to install

install-os <machine> <arch> <os> [name]		- install os to a machine
copy-os <src-mach> <arch> <os> <dst-mach>	- copy an os from one machine to another
new						- add a new machine
delete [machine]				- delete existing machine
info [machine]					- show machine detailed info
log [machine]					- show machine log
clear-log [machine]				- clear the log for machine
start [machine]					- boot machine
start-vm [machine]				- boot virtual instance of machine
stop [machine]					- triggers power button
clear [machine]					- stop machine and clear stale data (ip, job, etc)
connect [machine]				- connect to machine with ssh
set <machine>	power <value>			- control wall power
		relay <seconds>			- connect the relay for x seconds
		os <os>				- set os for machine
		kernel <kernel>			- set kernel for machine
		resources <resources>		- comma separated list of resources to set
		params <boot parameters>	- Set additional kernel command line parameters
		vmparams <VM parameters>	- Add additional QEMU command line parameters
		startcmd <command>		- Run a command instead of the normal start routine
		stopcmd <command>		- Run a command after normal halt/poweroff of machine
get <machine> [power | relay | os | params |	- Get machine attribute
               resources | kernel | reservation
               ip | mac | vmparams ]
reserve <machine>				- Reserve the machine for the current user
release <machine>				- Release any reservation you have on the machine
release-forced <machine>			- Release the machine even if you didn't reserve it
wait <machine>					- Wait for a machine to become available for use

<?php
}
?>
