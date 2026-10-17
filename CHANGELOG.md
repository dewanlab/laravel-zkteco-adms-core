# Changelog

All notable changes to `dewanlab/laravel-zkteco-adms-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [1.0.0] - 2026-10-17

### Added

- **Core ZKTeco ADMS Push Protocol**:

  - Full handshake implementation (`GET /iclock/cdata`, `GET /iclock/registry`).
  - Attendance punch log ingestion (`POST /iclock/cdata?table=ATTLOG`).
  - Terminal operational audit log ingestion (`POST /iclock/cdata?table=OPERLOG`).
  - Multimodal biometric template ingestion (`POST /iclock/cdata?table=BIODATA`).
  - Command queue polling (`GET /iclock/getrequest`).
  - Asynchronous command execution acknowledgment (`POST /iclock/devicecmd`).
  - Terminal query data ingestion (`GET|POST /iclock/querydata`).
  - Real-time clock time synchronization (`GET /iclock/rtdata`).
- **Developer-Friendly Fluent API**:
  - Device scoping: `ZktecoAdms::device('SN')->...` for fluent chaining.
  - Employee CRUD: Single and bulk `createUser()`, `createUsers()`, `updateUser()`, `deleteUser()`, `deleteUsers()`, `getUsers()`, and `getUser()`.
  - Attendance Log Querying: `getAttendanceLogs()`, `getAttendanceLogsForUser()`, `getAttendanceLogsByDate()`, `getTodayAttendanceLogs()`, and `clearAttendanceLogs()`.
  - Bidirectional Synchronization:
    - `push($users = null)`: Push local database employees to the hardware terminal.
    - `pull()`: Pull users and all historical attendance records from device memory into the database.
    - `pullUsers()`: Query device employee roster (`DATA QUERY USERINFO`).
    - `pullLogs()`: Pull all historical attendance records via watermark cursor reset (`Stamp=0`) and on-demand `DATA QUERY ATTLOG`.
    - `sync($users = null)`: Complete bidirectional synchronization.
  - Remote Hardware Controls: `unlockDoor()`, `reboot()`, `cancelAlarm()`, and `enrollBio()`.
- **Real-Time Lifecycle Events**:
  - `AttendanceLogged`: Dispatched when an employee punches in/out.
  - `UserSynchronized`: Dispatched when employee details are imported or modified on device UI.
  - `CommandAcknowledged`: Dispatched when the device successfully executes a queued command.
  - `CommandFailed`: Dispatched with error code and diagnostic message if a command fails on hardware.
- **Dedicated Structured Logging**:
  - Package-scoped daily log channel (`storage/logs/zkteco-adms-YYYY-MM-DD.log`).
  - Formatted logs for device polling, command delivery, acknowledgments, and data ingestion.
- **Database Architecture & Robustness**:
  - Composite unique indexes on `(device_id, pin, recorded_at)` preventing duplicate punches.
  - Year-2000 epoch drift protection for dead RTC coin batteries.
  - Automatic purge-before-update mitigation for the firmware authorization stacking bug.
