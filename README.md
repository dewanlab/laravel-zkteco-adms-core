# Laravel ZKTeco ADMS Core

[![Latest Version on Packagist](https://img.shields.io/packagist/v/dewanlab/laravel-zkteco-adms-core.svg?style=flat-square)](https://packagist.org/packages/dewanlab/laravel-zkteco-adms-core)
[![GitHub Tests Action Status](https://img.shields.io/github/actions/workflow/status/dewanlab/laravel-zkteco-adms-core/ci.yml?branch=main&label=tests&style=flat-square)](https://github.com/dewanlab/laravel-zkteco-adms-core/actions)
[![Total Downloads](https://img.shields.io/packagist/dt/dewanlab/laravel-zkteco-adms-core.svg?style=flat-square)](https://packagist.org/packages/dewanlab/laravel-zkteco-adms-core)
[![License](https://img.shields.io/packagist/l/dewanlab/laravel-zkteco-adms-core.svg?style=flat-square)](LICENSE.md)

A clean, developer-friendly Laravel package for communicating with ZKTeco biometric attendance and access control devices via the **ADMS (Automatic Data Master Server) Push Protocol**.

Terminals communicate over HTTP/HTTPS with your Laravel server automatically—no static public IP needed on the device!

---

## What This Package Does

1. **Employee Management (CRUD)**: Create, edit, list, and delete employees in your database and sync them to connected devices (single or bulk).
2. **Attendance Log Querying**: Retrieve real-time clock-in/out records from your database (all logs, employee-specific, date-specific, or today's logs).
3. **Auto-Import & Real-time Events**: Receive instant Laravel events when someone punches (`AttendanceLogged`) or when someone registers a user directly on the device touchscreen (`UserSynchronized`).
4. **Hardware Controls**: Remote door relay unlock, alarm cancel, and device reboot.

---

## Installation

```bash
composer require dewanlab/laravel-zkteco-adms-core
```

Publish configuration and migrations:

```bash
php artisan vendor:publish --tag="zkteco-adms-core-config"
```

```bash
php artisan vendor:publish --tag="zkteco-adms-core-migrations"
```

```bash
php artisan migrate
```

---

## Device Setup

On your ZKTeco physical device:

1. Open **Menu** $\rightarrow$ **Comm.** $\rightarrow$ **Cloud Server Setting** (or **ADMS / Web Server**).
2. Set:
   - **Server Address**: Your server IP or domain (e.g. `192.168.0.120` or `app.yourdomain.com`)
   - **Server Port**: `8000` (or `80` / `443` for production)
   - **Enable Domain Name**: OFF (or ON if using a domain name)
   - **Proxy**: OFF
3. Save and reboot the device. The device will connect to your Laravel app automatically.

---

## Usage Guide

All interactions are available via the `ZktecoAdms` facade:

```php
use DewanLab\LaravelZktecoAdmsCore\Facades\ZktecoAdms;
```

---

### Fluent Device Scoping (`ZktecoAdms::device()`)

Instead of repeatedly specifying the device serial number in every method call, you can target a device once and chain whatever actions you need:

```php
$device = ZktecoAdms::device('C3-400-DEMO');

// Employee CRUD on this device
$device->createUser('1001', 'Alice Smith', privilege: 0, card: '12345678');
$device->createUsers([
    ['pin' => '1002', 'name' => 'Bob Jones'],
    ['pin' => '1003', 'name' => 'Charlie Brown'],
]);
$device->updateUser('1001', ['name' => 'Alice Johnson']);
$device->deleteUser('1001');

// Attendance Queries on this device
$logs = $device->getAttendanceLogs();
$todayLogs = $device->getTodayAttendanceLogs();
$userLogs = $device->getAttendanceLogsForUser('1002');

// Bidirectional Syncing on this device
$device->push();               // Push all database employees to the device
$device->pull();               // Pull both users and old historical attendance logs from device
$device->pullUsers();          // Pull all employees from device UI into database
$device->pullLogs();           // Re-pull all historical attendance logs from device memory
$device->sync();               // Two-way sync: pull from device, push database users

// Hardware Controls on this device
$device->unlockDoor(1, 5);
$device->reboot();
$device->clearAttendanceLogs();
```

You can also inline calls:

```php
ZktecoAdms::device('C3-400-DEMO')->createUser('1001', 'Alice Smith');
ZktecoAdms::device('C3-400-DEMO')->pull();
ZktecoAdms::device('C3-400-DEMO')->reboot();
```

---

### Bidirectional Syncing (`push`, `pull`, and `sync`)

Synchronize data seamlessly between your Laravel database and edge devices:

```php
$device = ZktecoAdms::device('C3-400-DEMO');

// 1. PUSH: Send users from Laravel DB -> Device
$device->push();                         // Push all users in database to device
$device->push($usersCollection);         // Push specific users or collection

// 2. PULL: Fetch users and historical logs from Device -> Laravel DB
$device->pull();                         // Pull both users and all historical logs
$device->pullUsers();                    // Pull only users/employees
$device->pullLogs();                     // Pull all historical attendance logs

// 3. SYNC: Complete two-way synchronization
$device->sync();                         // Pulls from device, then pushes any DB users
```

Or statically via facade:

```php
ZktecoAdms::push('C3-400-DEMO');
ZktecoAdms::pull('C3-400-DEMO');
ZktecoAdms::sync('C3-400-DEMO');
```

---

### Direct Facade Usage

You can also pass the device explicitly if you prefer:

#### Add / Create Single Employee

Creates or updates the employee in the database and pushes it to the device:

```php
ZktecoAdms::createUser(
    device: 'C3-400-DEMO',
    pin: '1001',
    name: 'Alice Smith',
    privilege: 0,          // 0 = Normal User, 14 = Super Admin
    card: '12345678',      // Optional RFID card number
    password: '1234'       // Optional keypad PIN
);
```

#### Bulk Create / Push Employees

Create multiple employees at once:

```php
ZktecoAdms::createUsers('C3-400-DEMO', [
    ['pin' => '1002', 'name' => 'Bob Jones', 'card' => '87654321'],
    ['pin' => '1003', 'name' => 'Charlie Brown', 'privilege' => 14],
]);

// Or push all employees currently stored in your database to a newly added device:
ZktecoAdms::syncUsers('C3-400-DEMO');
```

#### Edit / Update Employee

Updates local record and pushes changes to the terminal:

```php
ZktecoAdms::updateUser('C3-400-DEMO', pin: '1001', attributes: [
    'name' => 'Alice Johnson',
    'card_number' => '99887766',
]);
```

#### Get / Show Employees

```php
// Retrieve all registered employees
$users = ZktecoAdms::getUsers();

// Retrieve a single employee by PIN (with biometrics & authorizations)
$user = ZktecoAdms::getUser('1001');
```

#### Delete Employee (Single or Bulk)

```php
// Delete single employee (removes from device and deletes local DB record)
ZktecoAdms::deleteUser('C3-400-DEMO', pin: '1001');

// Bulk delete employees
ZktecoAdms::deleteUsers('C3-400-DEMO', ['1002', '1003']);

// Delete from device only (preserve local database record)
ZktecoAdms::deleteUser('C3-400-DEMO', pin: '1001', deleteFromDatabase: false);
```

---

### 2. Querying Attendance Logs

When employees punch on the device, records are pushed to your server in real time. Query them easily:

#### Get All Logs

```php
// Get all attendance logs (latest first)
$logs = ZktecoAdms::getAttendanceLogs();

// Get logs filtered by a specific device
$deviceLogs = ZktecoAdms::getAttendanceLogs('C3-400-DEMO');
```

#### Get Logs for a Specific Employee

```php
// All logs for employee 1001
$userLogs = ZktecoAdms::getAttendanceLogsForUser('1001');

// Logs for employee within a date range
$userLogs = ZktecoAdms::getAttendanceLogsForUser(
    pin: '1001',
    fromDate: '2026-10-01 00:00:00',
    toDate: '2026-10-15 23:59:59'
);
```

#### Get Logs by Date

```php
// Logs for a specific single date
$dailyLogs = ZktecoAdms::getAttendanceLogsByDate('2026-10-15');

// Logs across a date range
$rangeLogs = ZktecoAdms::getAttendanceLogsByDate(
    startDate: '2026-10-01',
    endDate: '2026-10-15',
    device: 'C3-400-DEMO' // optional
);
```

#### Get Today's Logs

```php
$todayLogs = ZktecoAdms::getTodayAttendanceLogs();
```

---

### 3. Real-Time Events

The package dispatches standard Laravel events that you can listen for in your application:

#### Attendance Punch Event (`AttendanceLogged`)

Fires **instantly** whenever an employee punches on any device:

```php
use DewanLab\LaravelZktecoAdmsCore\Events\AttendanceLogged;
use Illuminate\Support\Facades\Event;

Event::listen(AttendanceLogged::class, function (AttendanceLogged $event) {
    $log = $event->attendanceLog; // Model: pin, recorded_at, status, verify_mode
    $device = $event->device;     // Model: serial_number, name, ip_address

    // Example: send SMS, notify Slack, or trigger payroll
    Log::info("Punch recorded: Employee {$log->pin} at {$log->recorded_at} on {$device->serial_number}");
});
```

#### Terminal User Enrollment (`UserSynchronized`)

Fires when someone is added or edited directly on the device keypad/touchscreen:

```php
use DewanLab\LaravelZktecoAdmsCore\Events\UserSynchronized;

Event::listen(UserSynchronized::class, function (UserSynchronized $event) {
    $user = $event->user;     // Model: pin, name, card_number, privilege
    $device = $event->device; // Device where the enrollment happened

    Log::info("Employee {$user->pin} ({$user->name}) imported from device {$device->serial_number}");
});
```

---

### 4. Device Controls

```php
// Momentarily unlock Door 1 for 5 seconds
ZktecoAdms::unlockDoor('C3-400-DEMO', doorId: 1, durationSeconds: 5);

// Reboot the device remotely
ZktecoAdms::rebootDevice('C3-400-DEMO');

// Clear attendance punch log memory on device
ZktecoAdms::clearAttendanceLogs('C3-400-DEMO');
```

---

## Quality & Standards

- **PHP**: 8.2, 8.3, 8.4, 8.5+
- **Laravel**: 10.x, 11.x, 12.x
- **Static Analysis**: PHPStan Level 8 clean
- **Code Style**: Laravel Pint

```bash
composer test              # Run Pest tests
composer run types:check   # Run PHPStan Level 8
composer run format        # Run Laravel Pint
```

---

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.
