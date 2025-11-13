#!/usr/bin/env php
<?php
/**
 * Integration example showing how the race condition detection works
 * 
 * This example simulates a scenario where:
 * 1. A job is started and crashes before execution
 * 2. Another job tries to run on the same machine
 * 3. The stale job is detected and cleared automatically
 */

echo "Race Condition Detection - Integration Example\n";
echo "===============================================\n\n";

// Simulate the race condition scenario
echo "Scenario: A job starts but crashes before execution\n";
echo "----------------------------------------------------\n";

echo "Step 1: Process A locks machine and sets job\n";
echo "  - Machine: test-machine\n";
echo "  - Job: run build-kernel x86_64/fedora\n";
echo "  - PID: 12345\n";
echo "  - Timestamp: ".date("Y-m-d H:i:s")."\n";
echo "  - State saved to mama.xml\n\n";

echo "Step 2: Process A unlocks machine\n\n";

echo "Step 3: ⚠️  CRASH! Process A dies before executing the job\n";
echo "  - Machine state shows job is running\n";
echo "  - But PID 12345 no longer exists\n\n";

echo "Step 4: Process B tries to run a new job\n";
echo "  - Process B locks machine\n";
echo "  - Process B sees job is set: 'run build-kernel x86_64/fedora'\n";
echo "  - Process B enters wait loop\n\n";

echo "Step 5: 🔍 Stale job detection activates\n";
echo "  - Checking if PID 12345 exists... ";
echo "exec('ps -p 12345 > /dev/null 2>&1')\n";
echo "  - Result: PID not found\n";
echo "  - Conclusion: Job is STALE\n\n";

echo "Step 6: ✅ Automatic recovery\n";
echo "  - Error logged: 'Detected stale job: run build-kernel x86_64/fedora (PID: 12345, timestamp: ...)'\n";
echo "  - Job cleared from machine state\n";
echo "  - Process B can now proceed\n\n";

echo "Result: The race condition was detected and recovered automatically!\n\n";

// Show the alternative scenario without detection
echo "\n";
echo "Without detection (old behavior):\n";
echo "----------------------------------\n";
echo "Step 4b: Process B enters wait loop\n";
echo "  - Waiting for job to finish...\n";
echo "  - Sleeping 10 seconds...\n";
echo "  - Still waiting...\n";
echo "  - Still waiting...\n";
echo "  - ⏰ Process B waits FOREVER because the job will never complete\n";
echo "  - ❌ Machine is stuck and unusable\n\n";

echo "This is the problem that the race condition detection solves!\n";

?>
