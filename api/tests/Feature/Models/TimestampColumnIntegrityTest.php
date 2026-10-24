<?php

use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| MariaDB implicit ON UPDATE timestamps
|--------------------------------------------------------------------------
|
| PROD runs MariaDB 10.6 with explicit_defaults_for_timestamp=0. Under that
| setting the first non-nullable TIMESTAMP column in a table that has no
| explicit DEFAULT is silently given:
|
|     DEFAULT current_timestamp() ON UPDATE current_timestamp()
|
| Laravel's `$table->timestamp('x')` emits `timestamp not null` with no
| DEFAULT, so a plain column declaration can acquire the clause. Any UPDATE
| to the row then overwrites a historical value with the current time. That is
| how every shift came to report 0 seconds: completing the shift reset
| started_at to the same instant as ended_at.
|
| The fix migration converts the affected columns to DATETIME. These tests keep
| the leak closed: the schema assertion catches a column acquiring the clause in
| future migrations, and the behavioural assertions catch application code that
| rewrites a timestamp it should be preserving.
|
*/

it('has no column that is silently rewritten on update', function () {
    // The clause only exists on MySQL/MariaDB. SQLite stores no such metadata,
    // so there is nothing to assert and the migration is a no-op there.
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Implicit ON UPDATE CURRENT_TIMESTAMP is a MySQL/MariaDB behaviour.');
    }

    $offenders = DB::select(
        'select TABLE_NAME as table_name, COLUMN_NAME as column_name
           from information_schema.COLUMNS
          where TABLE_SCHEMA = ?
            and EXTRA like ?',
        [DB::connection()->getDatabaseName(), '%on update CURRENT_TIMESTAMP%']
    );

    expect($offenders)->toBe(
        [],
        'These columns rewrite themselves on every UPDATE, corrupting historical '
        .'values: '.collect($offenders)
            ->map(fn ($column) => $column->table_name.'.'.$column->column_name)
            ->implode(', ')
    );
});

it('stores the timestamp columns the defect affected as datetime', function () {
    if (DB::connection()->getDriverName() !== 'mysql') {
        $this->markTestSkipped('Column types differ on SQLite; the fix is MySQL-only.');
    }

    $expected = [
        'shifts' => 'started_at',
        'inventory_action_challenges' => 'expires_at',
        'device_locations' => 'recorded_at',
        'device_ownership_history' => 'transferred_at',
        'geofence_events' => 'event_time',
        'theft_reports' => 'theft_date',
    ];

    foreach ($expected as $table => $column) {
        $type = DB::selectOne(
            'select DATA_TYPE as data_type
               from information_schema.COLUMNS
              where TABLE_SCHEMA = ?
                and TABLE_NAME = ?
                and COLUMN_NAME = ?',
            [DB::connection()->getDatabaseName(), $table, $column]
        );

        expect($type?->data_type)->toBe('datetime', "{$table}.{$column} should be datetime");
    }
});
