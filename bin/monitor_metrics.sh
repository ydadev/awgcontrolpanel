#!/bin/bash

# Monitor and restart metrics collector if it's not running
# This script checks if collect_metrics.php is running and restarts it if needed
# Uses flock to prevent multiple instances (#42)

SCRIPT_PATH="/var/www/html/bin/collect_metrics.php"
LOG_FILE="/var/log/metrics_monitor.log"
PID_FILE="/var/run/collect_metrics.pid"
HEARTBEAT_FILE="/var/run/collect_metrics.heartbeat"
HEARTBEAT_MAX_AGE="${METRICS_HEARTBEAT_MAX_AGE:-120}"
# IMPORTANT: the monitor MUST use a DIFFERENT lock file than the collector.
# collect_metrics.php flock()s /var/run/collect_metrics.lock itself. If the monitor
# held that same lock, the collector it starts would inherit the monitor's locked fd
# and its own flock() would fail, so it exited immediately — the daemon never stayed
# alive and metrics were never collected.
MONITOR_LOCK_FILE="/var/run/metrics_monitor.lock"

log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

# Use flock to prevent multiple monitor instances (on the monitor's OWN lock file)
exec 200>"$MONITOR_LOCK_FILE"
if ! flock -n 200; then
    log_message "Another monitor instance is running, exiting"
    exit 0
fi

# Check if the process is running
is_running() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            # Check if it's actually our script
            if ps -p "$PID" -o args= | grep -q "collect_metrics.php"; then
                return 0
            fi
        fi
    fi
    # Also check if any collect_metrics.php is running (catches orphan processes)
    if pgrep -f "collect_metrics.php" > /dev/null 2>&1; then
        # Update PID file with actual PID
        pgrep -f "collect_metrics.php" | head -1 > "$PID_FILE"
        return 0
    fi
    return 1
}

# Start the metrics collector
start_collector() {
    log_message "Starting metrics collector..."
    # Detach from the monitor (setsid) and close the monitor's lock fd (200>&-) so the
    # collector neither holds the monitor lock nor inherits any unexpected flock.
    setsid /usr/local/bin/php "$SCRIPT_PATH" >> /var/log/metrics_collector.log 2>&1 200>&- </dev/null &
    echo $! > "$PID_FILE"
    log_message "Metrics collector started with PID: $(cat $PID_FILE)"
}

heartbeat_is_fresh() {
    [ -f "$HEARTBEAT_FILE" ] || return 1

    NOW=$(date +%s)
    UPDATED=$(stat -c %Y "$HEARTBEAT_FILE" 2>/dev/null || echo 0)
    AGE=$((NOW - UPDATED))
    [ "$AGE" -lt 0 ] && AGE=0
    [ "$AGE" -le "$HEARTBEAT_MAX_AGE" ]
}

stop_collector() {
    PID=""
    [ -f "$PID_FILE" ] && PID=$(cat "$PID_FILE" 2>/dev/null || true)
    if [ -n "$PID" ] && ps -p "$PID" > /dev/null 2>&1; then
        # The collector is started with setsid, so its PID is also the process-group ID.
        # Stop the whole group to avoid leaving a stuck ssh/sshpass child behind.
        /bin/kill -TERM -- "-$PID" 2>/dev/null || kill "$PID" 2>/dev/null || true
        i=0
        while [ "$i" -lt 5 ] && ps -p "$PID" > /dev/null 2>&1; do
            sleep 1
            i=$((i + 1))
        done
        if ps -p "$PID" > /dev/null 2>&1; then
            /bin/kill -KILL -- "-$PID" 2>/dev/null || kill -9 "$PID" 2>/dev/null || true
        fi
    fi
    rm -f "$PID_FILE" "$HEARTBEAT_FILE"
}

# Main logic
if is_running; then
    if heartbeat_is_fresh; then
        log_message "Metrics collector is healthy (PID: $(cat $PID_FILE))"
    else
        log_message "Metrics collector heartbeat is stale - restarting it"
        stop_collector
        start_collector
    fi
else
    log_message "Metrics collector is not running - starting it"
    start_collector
fi
