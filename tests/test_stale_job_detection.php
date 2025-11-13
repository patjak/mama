#!/usr/bin/env php
<?php

/**
 * Test script to demonstrate stale job detection
 * 
 * This script tests the race condition detection mechanism by:
 * 1. Creating a mock machine with a job that has a non-existent PID
 * 2. Verifying that the stale job is detected
 * 3. Creating a mock machine with a valid PID
 * 4. Verifying that the job is not considered stale
 */

// Mock the Machine class for testing
class MockMachine {
	public $name = "test-machine";
	public $job = "";
	public $job_pid = "";
	public $job_timestamp = "";

	// Check if a job appears to be stale (set but not actually running)
	public function is_job_stale()
	{
		// No job means it can't be stale
		if ($this->job == "")
			return FALSE;

		// If no PID is set, the job might be from an old version
		// Consider it potentially stale if it's been set for more than 5 minutes
		if ($this->job_pid == "") {
			if ($this->job_timestamp != "") {
				$age = time() - (int)$this->job_timestamp;
				// Job is stale if it's been set for more than 5 minutes without a PID
				if ($age > 300) {
					return TRUE;
				}
			}
			return FALSE;
		}

		// Check if the process is still running
		$pid = (int)$this->job_pid;
		if ($pid <= 0)
			return TRUE;

		// Check if the process exists
		exec("ps -p ".$pid." > /dev/null 2>&1", $output, $ret);
		if ($ret != 0) {
			// Process doesn't exist - job is stale
			return TRUE;
		}

		return FALSE;
	}
}

// Test 1: Job with non-existent PID should be detected as stale
echo "Test 1: Job with non-existent PID\n";
echo "==================================\n";
$machine = new MockMachine();
$machine->job = "test job";
$machine->job_pid = "99999"; // Non-existent PID
$machine->job_timestamp = time();

if ($machine->is_job_stale()) {
	echo "✓ PASS: Stale job detected (PID 99999 does not exist)\n";
} else {
	echo "✗ FAIL: Stale job not detected\n";
	exit(1);
}
echo "\n";

// Test 2: Job with current process PID should NOT be stale
echo "Test 2: Job with current process PID\n";
echo "=====================================\n";
$machine = new MockMachine();
$machine->job = "test job";
$machine->job_pid = getmypid(); // Current process PID
$machine->job_timestamp = time();

if (!$machine->is_job_stale()) {
	echo "✓ PASS: Job not considered stale (PID ".getmypid()." exists)\n";
} else {
	echo "✗ FAIL: Job incorrectly detected as stale\n";
	exit(1);
}
echo "\n";

// Test 3: Job with no PID and old timestamp should be stale
echo "Test 3: Job with no PID and old timestamp\n";
echo "==========================================\n";
$machine = new MockMachine();
$machine->job = "test job";
$machine->job_pid = "";
$machine->job_timestamp = time() - 400; // 400 seconds ago (> 5 minutes)

if ($machine->is_job_stale()) {
	echo "✓ PASS: Stale job detected (no PID, old timestamp)\n";
} else {
	echo "✗ FAIL: Stale job not detected\n";
	exit(1);
}
echo "\n";

// Test 4: Job with no PID and recent timestamp should NOT be stale
echo "Test 4: Job with no PID and recent timestamp\n";
echo "=============================================\n";
$machine = new MockMachine();
$machine->job = "test job";
$machine->job_pid = "";
$machine->job_timestamp = time() - 60; // 60 seconds ago (< 5 minutes)

if (!$machine->is_job_stale()) {
	echo "✓ PASS: Job not considered stale (no PID, recent timestamp)\n";
} else {
	echo "✗ FAIL: Job incorrectly detected as stale\n";
	exit(1);
}
echo "\n";

// Test 5: No job should not be stale
echo "Test 5: No job\n";
echo "==============\n";
$machine = new MockMachine();
$machine->job = "";

if (!$machine->is_job_stale()) {
	echo "✓ PASS: No job is not stale\n";
} else {
	echo "✗ FAIL: Empty job incorrectly detected as stale\n";
	exit(1);
}
echo "\n";

echo "All tests passed!\n";
exit(0);

?>
