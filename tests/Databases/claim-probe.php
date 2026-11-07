<?php

declare(strict_types=1);

/**
 * One contender for a claim, run as its own PHP process by ClaimConcurrencyTest.
 *
 *   php claim-probe.php <driver> <host> <port> <database> <user> <password> <table> <id> <expected> <next> <barrier-file>
 *
 * It connects, then waits for the barrier file so that every contender fires its UPDATE at the same
 * moment, then prints WON or LOST.
 */

use Asignua\FilamentScheduledReports\Support\Claim;
use Illuminate\Database\Capsule\Manager;

require __DIR__.'/../../vendor/autoload.php';

[, $driver, $host, $port, $database, $user, $password, $table, $id, $expected, $next, $barrier] = $argv;

$capsule = new Manager;
$capsule->addConnection([
    'driver' => $driver,
    'host' => $host,
    'port' => $port,
    'database' => $database,
    'username' => $user,
    'password' => $password,
    'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
    'prefix' => '',
    'schema' => 'public',
]);

$connection = $capsule->getConnection();
$connection->getPdo();

$deadline = microtime(true) + 30;

while (!file_exists($barrier) && microtime(true) < $deadline) {
    usleep(200);
}

echo Claim::advance($connection, $table, (int) $id, $expected, $next) ? 'WON' : 'LOST';
