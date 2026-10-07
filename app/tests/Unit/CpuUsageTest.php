<?php

namespace Tests\Unit;

use App\Services\CpuUsage;
use PHPUnit\Framework\TestCase;

class CpuUsageTest extends TestCase
{
    public function test_parses_the_aggregate_cpu_line_with_idle_and_iowait_as_idle(): void
    {
        $this->assertSame(['idle' => 1000 + 50, 'total' => 100 + 10 + 40 + 1000 + 50 + 5 + 5 + 0],
            CpuUsage::parse("cpu  100 10 40 1000 50 5 5 0 0 0\n"));
        $this->assertNull(CpuUsage::parse("cpu0 1 2 3 4\n"), 'per-core lines are not the aggregate');
        $this->assertNull(CpuUsage::parse('intr 12345'));
    }

    public function test_percentage_between_two_samples_is_clamped_and_guards_zero_elapsed(): void
    {
        $this->assertSame(25, CpuUsage::between(['idle' => 0, 'total' => 0], ['idle' => 300, 'total' => 400]));
        $this->assertSame(100, CpuUsage::between(['idle' => 10, 'total' => 10], ['idle' => 10, 'total' => 110]));
        $this->assertSame(0, CpuUsage::between(['idle' => 0, 'total' => 0], ['idle' => 100, 'total' => 100]));
        $this->assertNull(CpuUsage::between(['idle' => 5, 'total' => 50], ['idle' => 5, 'total' => 50]));
    }

    public function test_reads_a_stat_file_twice_and_returns_null_when_unavailable(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'stat');
        file_put_contents($file, "cpu  50 0 50 900 0 0 0 0 0 0\ncpu0 1 1 1 1\n");
        $percent = (new CpuUsage($file))->percent(0);
        $this->assertNull($percent, 'identical samples have no elapsed time');
        unlink($file);

        $this->assertNull((new CpuUsage('/nonexistent/stat'))->percent(0));
    }

    public function test_real_proc_stat_gives_a_valid_percentage_on_linux(): void
    {
        if (! is_readable('/proc/stat')) {
            $this->markTestSkipped('no /proc/stat');
        }
        $percent = (new CpuUsage)->percent(50000);
        $this->assertTrue($percent === null || ($percent >= 0 && $percent <= 100));
    }
}
