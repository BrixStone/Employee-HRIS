#!/usr/bin/env bash

# HRIS automatic and manual MySQL backup
set -Eeuo pipefail

BACKUP_DIR="/backups"
REQUEST_DIR="/requests"

RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
INTERVAL="${BACKUP_INTERVAL_SECONDS:-86400}"
POLL_INTERVAL=2

BACKUP_RESULT_FILENAME=""

log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

perform_backup() {
    local trigger="${1:-automatic}"
    local timestamp
    local filename
    local temp_file

    timestamp=$(date '+%Y-%m-%d_%H%M%S')
    filename="hris_backup_${timestamp}.sql.gz"
    temp_file="${BACKUP_DIR}/.${filename}.tmp"

    BACKUP_RESULT_FILENAME=""

    log "Starting ${trigger} database backup..."

    mkdir -p "$BACKUP_DIR"

    export MYSQL_PWD="$DB_PASSWORD"

    if mysqldump \
        --host="$DB_HOST" \
        --port="${DB_PORT:-3306}" \
        --user="$DB_USER" \
        --no-tablespaces \
        --single-transaction \
        --routines \
        --triggers \
        --events \
        --hex-blob \
        "$DB_NAME" | gzip -c > "$temp_file"; then

        unset MYSQL_PWD

        if [[ ! -s "$temp_file" ]] || ! gzip -t "$temp_file"; then
            rm -f "$temp_file"
            log "ERROR: Backup file is empty or corrupted."
            return 1
        fi

        mv "$temp_file" "${BACKUP_DIR}/${filename}"

        BACKUP_RESULT_FILENAME="$filename"

        log "Backup successful: ${filename}"
        log "File size: $(du -h "${BACKUP_DIR}/${filename}" | cut -f1)"

        log "Removing backups older than ${RETENTION_DAYS} days..."

        find "$BACKUP_DIR" \
            -maxdepth 1 \
            -type f \
            -name 'hris_backup_*.sql.gz' \
            -mtime "+${RETENTION_DAYS}" \
            -delete

        log "Backup cleanup completed."
        return 0

    else
        unset MYSQL_PWD
        rm -f "$temp_file"
        log "ERROR: Database backup failed."
        return 1
    fi
}

write_request_result() {
    local request_id="$1"
    local status="$2"
    local filename="${3:-}"
    local result_file="${REQUEST_DIR}/${request_id}.result"
    local temp_result="${result_file}.tmp"

    {
        echo "status=${status}"
        echo "filename=${filename}"
        echo "completed_at=$(date '+%Y-%m-%d %H:%M:%S')"
    } > "$temp_result"

    mv "$temp_result" "$result_file"
}

process_manual_requests() {
    local request_file
    local request_id
    local processing_file

    shopt -s nullglob

    for request_file in "$REQUEST_DIR"/*.request; do

        request_id="$(basename "$request_file" .request)"

        # Only accept UUID-shaped request IDs.
        if [[ ! "$request_id" =~ ^[a-fA-F0-9-]{36}$ ]]; then
            log "Ignoring invalid backup request: $(basename "$request_file")"
            rm -f "$request_file"
            continue
        fi

        processing_file="${REQUEST_DIR}/${request_id}.processing"

        # Claim the request so it cannot be processed twice.
        if ! mv "$request_file" "$processing_file" 2>/dev/null; then
            continue
        fi

        log "Manual backup request received: ${request_id}"

        if perform_backup "manual"; then
            write_request_result \
                "$request_id" \
                "success" \
                "$BACKUP_RESULT_FILENAME"

            log "Manual backup request completed: ${request_id}"
        else
            write_request_result \
                "$request_id" \
                "failed"

            log "Manual backup request failed: ${request_id}"
        fi

        rm -f "$processing_file"
    done

    shopt -u nullglob
}

# Prepare shared directories.
mkdir -p "$BACKUP_DIR" "$REQUEST_DIR"

# Allow the PHP application to create request files.
chmod 1777 "$REQUEST_DIR"

log "HRIS backup scheduler started."
log "Backup interval: ${INTERVAL} seconds"
log "Retention: ${RETENTION_DAYS} days"
log "Manual request polling: every ${POLL_INTERVAL} seconds"

# Preserve the immediate backup on container startup.
if perform_backup "automatic"; then
    log "Initial backup completed successfully."
else
    log "Initial backup failed."
fi

next_auto_backup=$(( $(date +%s) + INTERVAL ))

while true; do

    # Check for manual requests.
    process_manual_requests

    # Check whether the next automatic backup is due.
    current_time=$(date +%s)

    if (( current_time >= next_auto_backup )); then
        if perform_backup "automatic"; then
            log "Automatic backup cycle completed successfully."
        else
            log "Automatic backup cycle failed."
        fi

        next_auto_backup=$(( $(date +%s) + INTERVAL ))
        log "Next automatic backup scheduled."
    fi

    sleep "$POLL_INTERVAL"
done
