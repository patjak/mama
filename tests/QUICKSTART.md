# Race Condition Detection - Quick Start Guide

## What was the problem?

When mama starts a job, there's a small window of time between when the job is marked as "running" in the database and when it actually starts executing. If the mama process crashes in this window, the machine gets stuck with a phantom job that will never complete.

## What's the solution?

Mama now tracks:
- **PID**: The process ID executing the job
- **Timestamp**: When the job started

If another job tries to run and finds a "running" job whose process doesn't exist, it automatically clears the stale job and continues.

## How do I use it?

**No action needed!** The detection runs automatically.

### Viewing stale jobs

List machines to see stale jobs marked with `[STALE]`:
```bash
mama list
```

Get detailed info about a specific machine:
```bash
mama info <machine-name>
```

### Manually clearing a stuck machine

If needed:
```bash
mama clear <machine-name>
```

## How does it work?

### Before (Problem):
```
Process A: Lock → Set job → Save → Unlock → [CRASH!]
Process B: Lock → See job running → Wait forever → 😞
```

### After (Solution):
```
Process A: Lock → Set job + PID + timestamp → Save → Unlock → [CRASH!]
Process B: Lock → See job running → Check PID → Not found! → Clear job → Continue → 😊
```

## Examples

Run the test suite:
```bash
php tests/test_stale_job_detection.php
```

See an integration example:
```bash
php tests/example_race_condition_scenario.php
```

## Technical Details

For complete technical documentation, see [tests/README.md](README.md)

## Backwards Compatibility

✅ Works with existing mama.xml files
✅ No manual migration needed
✅ Old jobs (without PID) are handled gracefully

## Support

If you encounter a stale job:
1. Check `mama info <machine>` to see job details
2. The stale job should be cleared automatically on next job attempt
3. If needed, manually clear with `mama clear <machine>`
4. Report persistent issues to the mama maintainers
