#!/bin/bash

set -euo pipefail

ENGINE_DIR="$(cd "$(dirname "$0")/.." && pwd)"
PORT=12346
PHP_BIN="${PHP_BIN:-php}"
SERVICE_LOG="${ENGINE_DIR}/log/service.log"
FALLBACK_SERVICE_LOG="/tmp/stobe_service.log"
MANAGER_SCRIPT="${ENGINE_DIR}/service/manager.php"
LOCK_FILE="/tmp/stobe_background_processor.lock"
OWNER_FILE="/tmp/stobe_background_processor.owner"
LIVE_DB_NAME="${STOBE_LIVE_DB_NAME:-stobe}"
MANAGER_TIMEOUT_SECONDS="${STOBE_BACKGROUND_MANAGER_TIMEOUT_SECONDS:-600}"

umask 0002

case "${MANAGER_TIMEOUT_SECONDS}" in
    ''|*[!0-9]*) MANAGER_TIMEOUT_SECONDS=600 ;;
esac
if [ "${MANAGER_TIMEOUT_SECONDS}" -lt 60 ]; then
    MANAGER_TIMEOUT_SECONDS=60
fi

# Only the live database may own the processor slot (lock + port). A loop started
# from a test suite (STOBE_DB_NAME=stobe_test) would hold it and stall live forever.
case "${STOBE_NO_BACKGROUND_PROCESSOR:-}" in
    ''|0|false|no) ;;
    *) echo "Stobe background processor disabled (STOBE_NO_BACKGROUND_PROCESSOR)."; exit 3 ;;
esac
if [ -n "${STOBE_DB_NAME:-}" ] && [ "${STOBE_DB_NAME}" != "${LIVE_DB_NAME}" ]; then
    echo "Refusing to start the Stobe background processor for non-live database ${STOBE_DB_NAME} (live: ${LIVE_DB_NAME})."
    exit 3
fi

# Hold the lock for the daemon lifetime so concurrent web requests cannot
# create duplicate manager loops before the listener binds.
if ! command -v flock >/dev/null 2>&1; then
    echo "Cannot start Stobe background processor: flock is unavailable."
    exit 1
fi
touch "${LOCK_FILE}" 2>/dev/null || {
    echo "Cannot create Stobe background processor lock file: ${LOCK_FILE}"
    exit 1
}
chmod 0666 "${LOCK_FILE}" 2>/dev/null || true
exec 9>"${LOCK_FILE}" || {
    echo "Cannot open Stobe background processor lock file: ${LOCK_FILE}"
    exit 1
}
if ! flock -n 9; then
    echo "An instance of the Stobe background processor is already running."
    exit 0
fi
printf 'db=%s pid=%s started=%s
' "${STOBE_DB_NAME:-${LIVE_DB_NAME}}" "$$"     "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" > "${OWNER_FILE}" 2>/dev/null || true
chmod 0666 "${OWNER_FILE}" 2>/dev/null || true

# Ensure a writable service log is available.
mkdir -p "${ENGINE_DIR}/log" 2>/dev/null
if ! touch "${SERVICE_LOG}" 2>/dev/null; then
    SERVICE_LOG="${FALLBACK_SERVICE_LOG}"
    touch "${SERVICE_LOG}" 2>/dev/null || true
fi

# We hold the lock, so no live loop owns a listener: an nc listener whose parent
# died (ppid 1, e.g. start.sh OOM-killed) is an orphan; reclaim its port.
if nc -z 127.0.0.1 "${PORT}" 2>/dev/null && command -v pgrep >/dev/null 2>&1; then
    for orphan_pid in $(pgrep -f "^nc -lk -p ${PORT}\$" 2>/dev/null || true); do
        if [ "$(ps -o ppid= -p "${orphan_pid}" 2>/dev/null | tr -d ' ')" = "1" ]; then
            kill "${orphan_pid}" 2>/dev/null || true
            sleep 0.2
        fi
    done
fi

# Refuse to claim a port already owned by another process.
if nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
    echo "Cannot start Stobe background processor: port ${PORT} is already in use."
    exit 1
fi

# Keep the health socket continuously bound for the manager lifetime.
# 9>&-: the listener must not inherit the lock fd, or an orphaned listener keeps the lock.
nc -lk -p "${PORT}" </dev/null >/dev/null 2>&1 9>&- &
LISTENER_PID=$!

trap "kill ${LISTENER_PID} 2>/dev/null || true" EXIT

sleep 0.1
if ! kill -0 "${LISTENER_PID}" 2>/dev/null || ! nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
    echo "Cannot start Stobe background processor: listener failed to bind port ${PORT}."
    exit 1
fi

while true; do
    if ! kill -0 "${LISTENER_PID}" 2>/dev/null || ! nc -z 127.0.0.1 "${PORT}" 2>/dev/null; then
        printf '%s Background listener stopped; exiting for guarded restart.\n' \
            "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" >> "${SERVICE_LOG}"
        exit 1
    fi

    manager_status=0
    if command -v timeout >/dev/null 2>&1; then
        timeout --signal=TERM --kill-after=10s "${MANAGER_TIMEOUT_SECONDS}s" \
            "${PHP_BIN}" "${MANAGER_SCRIPT}" >> "${SERVICE_LOG}" 2>&1 || manager_status=$?
    else
        "${PHP_BIN}" "${MANAGER_SCRIPT}" >> "${SERVICE_LOG}" 2>&1 || manager_status=$?
    fi

    if [ "${manager_status}" -eq 124 ] || [ "${manager_status}" -eq 137 ]; then
        printf '%s Background manager exceeded %ss timeout; restarting loop.\n' \
            "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "${MANAGER_TIMEOUT_SECONDS}" >> "${SERVICE_LOG}"
    elif [ "${manager_status}" -ne 0 ]; then
        printf '%s Background manager exited with status %s; restarting loop.\n' \
            "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "${manager_status}" >> "${SERVICE_LOG}"
    fi
    sleep 5
done
