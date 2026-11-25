#!/usr/bin/env bash

# HRIS automatic backups, manual backups, and restore testing
set -Eeuo pipefail

BACKUP_DIR="/backups"
REQUEST_DIR="/requests"
RESTORE_TEST_DB="hris_restore_test"

RETENTION_DAYS="${BACKUP_RETENTION_DAYS:-30}"
INTERVAL="${BACKUP_INTERVAL_SECONDS:-86400}"
POLL_INTERVAL=2

BACKUP_RESULT_FILENAME=""
SAFETY_BACKUP_FILENAME=""
CURRENT_RESTORE_REQUEST_ID=""
log() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*"
}

mysql_command() {
    mysql \
        --host="$DB_HOST" \
        --port="${DB_PORT:-3306}" \
        --user="$DB_USER" \
        --default-character-set=utf8mb4 \
        "$@"
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
    SAFETY_BACKUP_FILENAME=""

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

create_safety_backup() {
    local timestamp
    local filename
    local temp_file
    local final_file

    SAFETY_BACKUP_FILENAME=""

    timestamp=$(date '+%Y-%m-%d_%H%M%S')

    filename="hris_pre_restore_${timestamp}.sql.gz"

    temp_file="${BACKUP_DIR}/.${filename}.tmp"
    final_file="${BACKUP_DIR}/${filename}"

    log "Creating safety backup before live database restoration..."

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

        if [[ ! -s "$temp_file" ]] || ! gzip -t "$temp_file"; then
            rm -f "$temp_file"
            log "ERROR: Safety backup is empty or corrupted."
            return 1
        fi

        if ! mv "$temp_file" "$final_file"; then
            rm -f "$temp_file"
            log "ERROR: Could not save safety backup."
            return 1
        fi

        SAFETY_BACKUP_FILENAME="$filename"

        log "Safety backup created successfully."
        log "Safety backup file: ${filename}"
        log "Safety backup size: $(du -h "$final_file" | cut -f1)"

        return 0

    else
        rm -f "$temp_file"

        log "ERROR: Could not create safety backup."
        return 1
    fi
}

import_backup_to_database() {
    local filename="$1"
    local database="$2"
    local backup_file="${BACKUP_DIR}/${filename}"

    validate_restore_database_name "$database" || return 1

    if [[ ! -f "$backup_file" ]]; then
        log "ERROR: Backup file does not exist: ${filename}"
        return 1
    fi

    if ! gzip -t "$backup_file"; then
        log "ERROR: Backup archive is corrupted: ${filename}"
        return 1
    fi

    log "Importing ${filename} into ${database}..."

    if gzip -dc "$backup_file" | mysql_command "$database"; then
        log "Import completed: ${database}"
        return 0
    else
        log "ERROR: Import failed: ${database}"
        return 1
    fi
}

rollback_live_database() {
    local safety_file="$1"

    log "=========================================="
    log "RESTORE FAILED. STARTING AUTOMATIC ROLLBACK."
    log "=========================================="

    write_request_progress \
        "$CURRENT_RESTORE_REQUEST_ID" \
        "rollback" \
        "Restore failed. Recovering the previous live database."

    if [[ ! -f "${BACKUP_DIR}/${safety_file}" ]]; then
        log "CRITICAL ERROR: Safety backup is missing."
        return 1
    fi

    if ! cleanup_restore_database "$DB_NAME"; then
        log "CRITICAL ERROR: Could not clean live database for rollback."
        return 1
    fi

    if ! import_backup_to_database "$safety_file" "$DB_NAME"; then
        log "CRITICAL ERROR: Safety backup import failed."
        return 1
    fi

    if ! validate_restore_database "$DB_NAME"; then
        log "CRITICAL ERROR: Rolled-back database failed validation."
        return 1
    fi

    log "ROLLBACK SUCCESSFUL: Previous live database restored."
    return 0
}

perform_live_restore() {
    local request_id="$1"
    local filename="$2"
    local backup_file="${BACKUP_DIR}/${filename}"
    local failure_message=""

    CURRENT_RESTORE_REQUEST_ID="$request_id"
    SAFETY_BACKUP_FILENAME=""

    export MYSQL_PWD="$DB_PASSWORD"

    log "=========================================="
    log "LIVE DATABASE RESTORE STARTED"
    log "Selected backup: ${filename}"
    log "=========================================="

    # Stage 1: Validate selected archive and test import.
    write_request_progress \
        "$request_id" \
        "validate" \
        "Checking backup archive and testing its contents."

    if [[ ! "$filename" =~ ^(hris_backup|hris_pre_restore)_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql\.gz$ ]]; then
        failure_message="Invalid backup filename."
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    if [[ ! -f "$backup_file" ]] || ! gzip -t "$backup_file"; then
        failure_message="The selected backup is missing or corrupted."
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    if ! cleanup_restore_database "$RESTORE_TEST_DB"; then
        failure_message="Could not prepare the restore test database."
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    if ! import_backup_to_database "$filename" "$RESTORE_TEST_DB"; then
        cleanup_restore_database "$RESTORE_TEST_DB" || true
        failure_message="The selected backup could not be imported during preflight."
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    if ! validate_restore_database "$RESTORE_TEST_DB"; then
        cleanup_restore_database "$RESTORE_TEST_DB" || true
        failure_message="The selected backup failed preflight validation."
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    if ! cleanup_restore_database "$RESTORE_TEST_DB"; then
        failure_message="Could not clean the restore test database."
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    # Stage 2: Create safety backup of current live database.
    write_request_progress \
        "$request_id" \
        "safety" \
        "Creating a safety backup of the current live database."

    if ! create_safety_backup; then
        failure_message="Could not create a safety backup. Live database was not changed."
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    # Stage 3: Replace live database contents.
    write_request_progress \
        "$request_id" \
        "replace" \
        "Replacing live database contents with the selected backup."

    if ! cleanup_restore_database "$DB_NAME"; then
        failure_message="Could not prepare the live database for restoration."
        if ! rollback_live_database "$SAFETY_BACKUP_FILENAME"; then
            failure_message="Live restore failed and automatic rollback also failed. Contact the system administrator immediately."
        else
            failure_message="Live restore could not proceed. The previous database was successfully recovered."
        fi
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    if ! import_backup_to_database "$filename" "$DB_NAME"; then
        if ! rollback_live_database "$SAFETY_BACKUP_FILENAME"; then
            failure_message="Live import failed and automatic rollback also failed. Contact the system administrator immediately."
        else
            failure_message="Live import failed. The previous database was successfully recovered."
        fi
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    # Stage 4: Validate restored live database.
    write_request_progress \
        "$request_id" \
        "validate-live" \
        "Checking the restored live database."

    if ! validate_restore_database "$DB_NAME"; then
        if ! rollback_live_database "$SAFETY_BACKUP_FILENAME"; then
            failure_message="Live validation failed and automatic rollback also failed. Contact the system administrator immediately."
        else
            failure_message="Live validation failed. The previous database was successfully recovered."
        fi
        write_request_result "$request_id" "failed" "$filename" "$failure_message"
        unset MYSQL_PWD
        return 1
    fi

    log "=========================================="
    log "LIVE DATABASE RESTORE COMPLETED"
    log "Selected backup: ${filename}"
    log "Safety backup: ${SAFETY_BACKUP_FILENAME}"
    log "=========================================="

    write_request_result \
        "$request_id" \
        "success" \
        "$filename" \
        "Database restoration completed and live database validation passed."

    unset MYSQL_PWD
    return 0
}



write_request_result() {
    local request_id="$1"
    local status="$2"
    local filename="${3:-}"
    local message="${4:-}"

    local result_file="${REQUEST_DIR}/${request_id}.result"
    local temp_result="${result_file}.tmp"

    {
        echo "status=${status}"
        echo "filename=${filename}"
        echo "message=${message}"
        echo "completed_at=$(date '+%Y-%m-%d %H:%M:%S')"
    } > "$temp_result"

    mv "$temp_result" "$result_file"

    if [[ "$status" == "success" ]]; then
        write_request_progress \
            "$request_id" \
            "complete" \
            "${message:-Database restoration completed successfully.}" \
            "success"
    else
        write_request_progress \
            "$request_id" \
            "failed" \
            "${message:-The requested operation failed.}" \
            "failed"
    fi
}


write_request_progress() {
    local request_id="$1"
    local stage="$2"
    local message="$3"
    local status="${4:-running}"

    local progress_file="${REQUEST_DIR}/${request_id}.progress"
    local temp_file="${progress_file}.tmp"

    {
        printf 'status=%s\n' "$status"
        printf 'stage=%s\n' "$stage"
        printf 'message=%s\n' "$message"
        printf 'updated_at=%s\n' "$(date '+%Y-%m-%d %H:%M:%S')"
    } > "$temp_file"

    mv "$temp_file" "$progress_file"
}



# Only allow operations on the two designated restore databases.
validate_restore_database_name() {
    case "${1:-}" in
        hris|hris_restore_test)
            return 0
            ;;
        *)
            log "ERROR: Refusing to operate on an unapproved database."
            return 1
            ;;
    esac
}


# Remove database objects from an approved schema.

cleanup_restore_database() {
    local database="$1"
    local drop_sql

    validate_restore_database_name "$database" || return 1

    log "Cleaning database: ${database}"

    # Phase 1: Drop views first.
    drop_sql="
        SELECT CONCAT(
            'DROP VIEW IF EXISTS \`',
            REPLACE(TABLE_NAME, '\`', '\`\`'),
            '\`;'
        )
        FROM information_schema.VIEWS
        WHERE TABLE_SCHEMA = '${database}';
    "

    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        mysql_command --batch --skip-column-names --raw --execute="$drop_sql"
    } | mysql_command "$database" || return 1

    # Phase 2: Drop events.
    drop_sql="
        SELECT CONCAT(
            'DROP EVENT IF EXISTS \`',
            REPLACE(EVENT_NAME, '\`', '\`\`'),
            '\`;'
        )
        FROM information_schema.EVENTS
        WHERE EVENT_SCHEMA = '${database}';
    "

    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        mysql_command --batch --skip-column-names --raw --execute="$drop_sql"
    } | mysql_command "$database" || return 1

    # Phase 3: Drop stored procedures and functions.
    drop_sql="
        SELECT CONCAT(
            'DROP ',
            ROUTINE_TYPE,
            ' IF EXISTS \`',
            REPLACE(ROUTINE_NAME, '\`', '\`\`'),
            '\`;'
        )
        FROM information_schema.ROUTINES
        WHERE ROUTINE_SCHEMA = '${database}';
    "

    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        mysql_command --batch --skip-column-names --raw --execute="$drop_sql"
    } | mysql_command "$database" || return 1

    # Phase 4: Drop base tables last.
    drop_sql="
        SELECT CONCAT(
            'DROP TABLE IF EXISTS \`',
            REPLACE(TABLE_NAME, '\`', '\`\`'),
            '\`;'
        )
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '${database}'
          AND TABLE_TYPE = 'BASE TABLE';
    "

    {
        echo "SET FOREIGN_KEY_CHECKS=0;"
        mysql_command --batch --skip-column-names --raw --execute="$drop_sql"
    } | mysql_command "$database" || return 1

    log "Database cleanup completed: ${database}"
}



# Validate tables, columns, and record queries in an approved schema.
validate_restore_database() {
    local database="$1"
    local table_query
    local table_list
    local table
    local escaped_table
    local column_count
    local row_count
    local total_records=0
    local total_tables=0

    validate_restore_database_name "$database" || return 1

    log "Starting database validation: ${database}"

    table_query="
        SELECT TABLE_NAME
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = '${database}'
          AND TABLE_TYPE = 'BASE TABLE'
        ORDER BY TABLE_NAME;
    "

    if ! table_list=$(
        mysql_command \
            --batch \
            --skip-column-names \
            --raw \
            --execute="$table_query"
    ); then
        log "ERROR: Could not retrieve table list from ${database}."
        return 1
    fi

    if [[ -z "$table_list" ]]; then
        log "ERROR: No tables were found in ${database}."
        return 1
    fi

    while IFS= read -r table; do

        [[ -z "$table" ]] && continue

        escaped_table=$(printf '%s' "$table" | sed 's/`/``/g')

        log "Validating table: ${table}"

        if ! column_count=$(
            mysql_command "$database" \
                --batch \
                --skip-column-names \
                --execute="SHOW COLUMNS FROM \`${escaped_table}\`;" |
                wc -l
        ); then
            log "ERROR: Could not inspect columns in ${table}."
            return 1
        fi

        if (( column_count < 1 )); then
            log "ERROR: Table ${table} has no columns."
            return 1
        fi

        if ! row_count=$(
            mysql_command "$database" \
                --batch \
                --skip-column-names \
                --execute="SELECT COUNT(*) FROM \`${escaped_table}\`;"
        ); then
            log "ERROR: Could not count records in ${table}."
            return 1
        fi

        if [[ ! "$row_count" =~ ^[0-9]+$ ]]; then
            log "ERROR: Invalid record count for ${table}."
            return 1
        fi

        total_tables=$((total_tables + 1))
        total_records=$((total_records + row_count))

        log "Table passed: ${table} | Columns: ${column_count} | Records: ${row_count}"

    done <<< "$table_list"

    if (( total_tables < 1 )); then
        log "ERROR: No tables were successfully validated."
        return 1
    fi

    log "=========================================="
    log "DATABASE VALIDATION PASSED: ${database}"
    log "Tables validated: ${total_tables}"
    log "Total records checked: ${total_records}"
    log "=========================================="

    return 0
}


# Compatibility wrappers for the existing restore-test workflow.
cleanup_restore_test_database() {
    cleanup_restore_database "$RESTORE_TEST_DB"
}

validate_restore_test_database() {
    validate_restore_database "$RESTORE_TEST_DB"
}


perform_restore_test() {
    local filename="$1"
    local backup_file="${BACKUP_DIR}/${filename}"
    local import_status=0
    local validation_status=0
    local cleanup_status=0

    log "Starting restore test for: ${filename}"

    if [[ ! "$filename" =~ ^hris_backup_[0-9]{4}-[0-9]{2}-[0-9]{2}_[0-9]{6}\.sql\.gz$ ]]; then
        log "ERROR: Invalid restore-test filename."
        return 1
    fi

    if [[ "$RESTORE_TEST_DB" == "$DB_NAME" ]]; then
        log "ERROR: Restore-test database cannot be the live database."
        return 1
    fi

    if [[ ! -f "$backup_file" ]]; then
        log "ERROR: Selected backup does not exist."
        return 1
    fi

    if ! gzip -t "$backup_file"; then
        log "ERROR: Backup archive failed gzip integrity check."
        return 1
    fi

    export MYSQL_PWD="$DB_PASSWORD"

    if ! cleanup_restore_test_database; then
        log "ERROR: Could not clean restore-test database."
        unset MYSQL_PWD
        return 1
    fi

    log "Importing backup into ${RESTORE_TEST_DB}..."

    if gzip -dc "$backup_file" | mysql_command "$RESTORE_TEST_DB"; then
        log "SQL import completed successfully."
    else
        import_status=1
        log "ERROR: SQL import failed."
    fi

    # Validate only after a successful import.
    if (( import_status == 0 )); then
        if validate_restore_test_database; then
            validation_status=0
        else
            validation_status=1
            log "ERROR: Restored database validation failed."
        fi
    else
        validation_status=1
    fi

    # Always attempt cleanup after testing.
    if ! cleanup_restore_test_database; then
        cleanup_status=1
        log "ERROR: Could not clean restore-test database after testing."
    fi

    unset MYSQL_PWD

    if (( import_status != 0 || validation_status != 0 || cleanup_status != 0 )); then
        log "Restore test FAILED: ${filename}"
        return 1
    fi

    log "Restore test PASSED: ${filename}"
    return 0
}

process_manual_requests() {
    local request_file
    local request_id
    local processing_file

    shopt -s nullglob

    for request_file in "$REQUEST_DIR"/*.request; do

        if  [[ "$request_file" == *.restore-test.request ]] ||
            [[ "$request_file" == *.restore.request ]]; then
            continue
        fi

        request_id="$(basename "$request_file" .request)"

        if [[ ! "$request_id" =~ ^[a-fA-F0-9-]{36}$ ]]; then
            log "Ignoring invalid backup request: $(basename "$request_file")"
            rm -f "$request_file"
            continue
        fi

        processing_file="${REQUEST_DIR}/${request_id}.processing"

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
            write_request_result "$request_id" "failed"
            log "Manual backup request failed: ${request_id}"
        fi

        rm -f "$processing_file"
    done

    shopt -u nullglob
}

process_live_restore_requests() {
    local request_file
    local request_id
    local processing_file
    local filename

    shopt -s nullglob

    for request_file in "$REQUEST_DIR"/*.restore.request; do

        request_id="$(basename "$request_file" .restore.request)"

        if [[ ! "$request_id" =~ ^[a-fA-F0-9-]{36}$ ]]; then
            log "Ignoring invalid live restore request."
            rm -f "$request_file"
            continue
        fi

        processing_file="${REQUEST_DIR}/${request_id}.restore.processing"

        if ! mv "$request_file" "$processing_file" 2>/dev/null; then
            continue
        fi

        filename="$(sed -n 's/^filename=//p' "$processing_file" | head -n 1)"

        log "Live restore request received: ${request_id}"

        if perform_live_restore "$request_id" "$filename"; then
            log "Live restore request completed: ${request_id}"
        else
            log "Live restore request failed: ${request_id}"
        fi

        rm -f "$processing_file"
    done

    shopt -u nullglob
}

process_restore_test_requests() {
    local request_file
    local request_id
    local processing_file
    local filename

    shopt -s nullglob

    for request_file in "$REQUEST_DIR"/*.restore-test.request; do

        request_id="$(basename "$request_file" .restore-test.request)"

        if [[ ! "$request_id" =~ ^[a-fA-F0-9-]{36}$ ]]; then
            log "Ignoring invalid restore-test request."
            rm -f "$request_file"
            continue
        fi

        processing_file="${REQUEST_DIR}/${request_id}.restore-test.processing"

        if ! mv "$request_file" "$processing_file" 2>/dev/null; then
            continue
        fi

        filename="$(sed -n 's/^filename=//p' "$processing_file" | head -n 1)"

        log "Restore-test request received: ${request_id}"

        if perform_restore_test "$filename"; then
            write_request_result \
                "$request_id" \
                "success" \
                "$filename"

            log "Restore-test request completed: ${request_id}"
        else
            write_request_result \
                "$request_id" \
                "failed" \
                "$filename"

            log "Restore-test request failed: ${request_id}"
        fi

        rm -f "$processing_file"
    done

    shopt -u nullglob
}

mkdir -p "$BACKUP_DIR" "$REQUEST_DIR"
chmod 1777 "$REQUEST_DIR"

log "HRIS backup scheduler started."
log "Backup interval: ${INTERVAL} seconds"
log "Retention: ${RETENTION_DAYS} days"
log "Manual request polling: every ${POLL_INTERVAL} seconds"
log "Restore-test database: ${RESTORE_TEST_DB}"

if perform_backup "automatic"; then
    log "Initial backup completed successfully."
else
    log "Initial backup failed."
fi

next_auto_backup=$(( $(date +%s) + INTERVAL ))

while true; do

    process_live_restore_requests
    process_restore_test_requests
    process_manual_requests

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
