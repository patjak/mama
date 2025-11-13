# Race Condition Detection for Job Execution

## Overview

This implementation adds detection for a race condition that can occur when starting jobs in mama. The race condition happens when a process sets a job on a machine but crashes or is killed before the job actually starts executing, leaving the machine in a "stuck" state where future jobs wait forever.

## The Problem

In the original code, job execution follows this sequence:

1. Lock the machine state
2. Wait for any existing jobs to finish
3. Set the new job name in machine state
4. Save machine state to disk
5. **Unlock** ← Critical point
6. Execute the actual job
7. Lock again and clean up

**Race Condition:** If the process dies or is killed between steps 5 and 6, the machine's job field is set but no job is actually running. Future jobs will wait forever for this "phantom" job to complete.

## The Solution

We now track additional information about job execution:

- **job_pid**: The process ID of the job executor
- **job_timestamp**: Unix timestamp when the job was started

### Stale Job Detection

A job is considered "stale" if:

1. A PID is set AND the process no longer exists (checked via `ps -p <pid>`)
2. No PID is set AND the job has been set for more than 5 minutes (for backwards compatibility)

### Automatic Recovery

When waiting for a job to complete, mama now:

1. Checks if the current job is stale
2. If stale, logs an error and clears the job automatically
3. Continues with the new job

## Usage

### Viewing Job Status

The `mama list` command now shows `[STALE]` marker for stale jobs:

```
No  Name            IP              MAC               State    On   Pwr   VM  Job
---------------------------------------------------------------------------------
1   test-machine    192.168.1.10    aa:bb:cc:dd:ee:ff online  Yes  50W       run test-job x86_64/fedora [STALE]
```

The `mama info <machine>` command shows detailed job information:

```
Running job:    run test-job x86_64/fedora
Job PID:        12345
Job started:    2025-11-13 15:30:45
Job status:     STALE (process not running)
```

### Manual Cleanup

If needed, you can manually clear a stale job:

```bash
mama clear <machine>
```

## Testing

Run the test suite to verify the detection logic:

```bash
php tests/test_stale_job_detection.php
```

The test covers:
- Detection of jobs with non-existent PIDs
- Non-detection of jobs with valid PIDs
- Detection of old jobs without PIDs
- Non-detection of recent jobs without PIDs
- Handling of machines with no jobs

## Implementation Details

### Modified Files

1. **src/machine.php**
   - Added `job_pid` and `job_timestamp` fields
   - Added `is_job_stale()` method
   - Added `detect_and_clear_stale_job()` method
   - Updated `clear_job()` to clear new fields
   - Updated `print_info()` to show job details

2. **src/job.php**
   - Updated `execute_run_job()` to set PID and timestamp
   - Updated `execute_prepare_job()` to set PID and timestamp
   - Added stale job detection in wait loops

3. **src/settings.php**
   - Added `job_pid` and `job_timestamp` to XML serialization

4. **src/main.php**
   - Updated `cmd_list()` to show stale job markers

### Backwards Compatibility

The implementation is backwards compatible:
- Old mama.xml files without job_pid/job_timestamp will work
- Jobs without PIDs are only considered stale after 5 minutes
- Empty string values are handled correctly

## Future Improvements

Potential enhancements:
- Configurable timeout for stale job detection
- Automatic retry of stale jobs
- Email notifications for stale job detection
- Metrics/logging for stale job frequency
