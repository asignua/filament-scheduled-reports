<?php

declare(strict_types=1);

namespace Asignua\FilamentScheduledReports\Tests\Databases;

use Asignua\FilamentScheduledReports\Enums\RunStatus;
use Asignua\FilamentScheduledReports\Models\ScheduledReport;
use Asignua\FilamentScheduledReports\Tests\TestCase;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Group;

/**
 * The part of "exactly once" that only a real database can prove: many OS processes, each with its own
 * connection, racing for the same due report. Runs against MariaDB 11.4 and PostgreSQL 16 (see run.sh);
 * skipped when `SR_DB` is not set, because SQLite in memory has no second connection to race from.
 */
#[Group('databases')]
class ClaimConcurrencyTest extends TestCase
{
    private const int CONTENDERS = 16;

    protected function setUp(): void
    {
        if (getenv('SR_DB') === false) {
            $this->markTestSkipped('Set SR_DB=mariadb|pgsql (see tests/Databases/run.sh) to race against a real database.');
        }

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        if (getenv('SR_DB') === false) {
            return;
        }

        $driver = (string) getenv('SR_DB');
        $app['config']->set('database.default', $driver);
        $app['config']->set("database.connections.{$driver}", [
            'driver' => $driver,
            'host' => getenv('SR_DB_HOST'),
            'port' => getenv('SR_DB_PORT'),
            'database' => getenv('SR_DB_NAME'),
            'username' => getenv('SR_DB_USER'),
            'password' => getenv('SR_DB_PASSWORD'),
            'charset' => $driver === 'pgsql' ? 'utf8' : 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'schema' => 'public',
        ]);
    }

    /**
     * The probe processes and the testbench console see the rows only once they are committed, so this test
     * builds a real (throwaway) database instead of wrapping each test in a rolled-back transaction.
     */
    protected function refreshTestDatabase(): void
    {
        Schema::dropAllTables();
        $this->artisan('migrate', ['--database' => getenv('SR_DB')]);
    }

    public function test_sixteen_processes_race_for_one_claim_and_exactly_one_wins(): void
    {
        $report = $this->makeReport(['next_run_at' => '2026-11-01 07:00:00']);
        $barrier = sys_get_temp_dir().'/sr-barrier-'.bin2hex(random_bytes(6));
        $processes = [];

        for ($i = 0; $i < self::CONTENDERS; $i++) {
            $processes[] = $this->start([PHP_BINARY, __DIR__.'/claim-probe.php', (string) getenv('SR_DB'), (string) getenv('SR_DB_HOST'), (string) getenv('SR_DB_PORT'), (string) getenv('SR_DB_NAME'), (string) getenv('SR_DB_USER'), (string) getenv('SR_DB_PASSWORD'), $report->getTable(), (string) $report->id, '2026-11-01 07:00:00', '2026-11-02 07:00:00', $barrier]);
        }

        // Every contender is connected and waiting by now; open the gate for all at once.
        usleep(1_500_000);
        touch($barrier);

        $outputs = array_map(fn (array $process): string => $this->finish($process), $processes);
        @unlink($barrier);

        $this->assertSame(self::CONTENDERS, count(array_filter($outputs, static fn (string $out): bool => in_array($out, ['WON', 'LOST'], true))), 'A contender failed: '.implode(' | ', $outputs));
        $this->assertSame(1, count(array_filter($outputs, static fn (string $out): bool => $out === 'WON')), 'Winners: '.implode(',', $outputs));
        $this->assertSame('2026-11-02 07:00:00', $report->refresh()->next_run_at);
    }

    public function test_concurrent_scheduler_commands_start_one_run(): void
    {
        $this->seedOrders();
        $report = $this->makeReport();
        $env = [
            ...getenv(),
            'DB_CONNECTION' => (string) getenv('SR_DB'),
            'DB_HOST' => (string) getenv('SR_DB_HOST'),
            'DB_PORT' => (string) getenv('SR_DB_PORT'),
            'DB_DATABASE' => (string) getenv('SR_DB_NAME'),
            'DB_USERNAME' => (string) getenv('SR_DB_USER'),
            'DB_PASSWORD' => (string) getenv('SR_DB_PASSWORD'),
            'APP_KEY' => 'base64:'.base64_encode(str_repeat('a', 32)),
            'QUEUE_CONNECTION' => 'sync',
            'MAIL_MAILER' => 'array',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
        ];
        $processes = [];

        for ($i = 0; $i < 6; $i++) {
            $processes[] = $this->start([PHP_BINARY, __DIR__.'/../../vendor/bin/testbench', 'scheduled-reports:run'], $env);
        }

        $outputs = array_map(fn (array $process): string => $this->finish($process), $processes);

        $this->assertSame(1, $report->runs()->count(), implode(' | ', $outputs));
        $this->assertSame(RunStatus::Success, $report->runs()->firstOrFail()->status, implode(' | ', $outputs));
        $this->assertSame(1, ScheduledReport::query()->count());
    }

    /**
     * @param list<string>               $command
     * @param array<string, string>|null $env
     *
     * @return array{resource, array<int, resource>}
     */
    private function start(array $command, ?array $env = null): array
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__, 2), $env);
        $this->assertIsResource($process);

        return [$process, $pipes];
    }

    /**
     * @param array{resource, array<int, resource>} $process
     */
    private function finish(array $process): string
    {
        [$handle, $pipes] = $process;
        $out = trim((string) stream_get_contents($pipes[1])).trim((string) stream_get_contents($pipes[2]));
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($handle);

        return $out;
    }
}
