<?php

declare(strict_types=1);

namespace Snapflow\System;

use Exception;

class System
{
    private const INVALID_DISKS = [
        'loop',
        'ram',
    ];

    private const INVALID_NET_INTERFACES = [
        'veth',
        'docker',
        'lo',
        'tun',
        'vboxnet',
        '.',
        'bonding_masters',
    ];

    public static function getOS(): string
    {
        return php_uname('s');
    }

    public static function getArch(): string
    {
        return php_uname('m');
    }

    public static function getArchEnum(): Architecture
    {
        return Architecture::fromString(self::getArch());
    }

    public static function getHostname(): string
    {
        return php_uname('n');
    }

    public static function getCPUCores(): int
    {
        return match (self::getOS()) {
            'Linux' => self::getLinuxCPUCores(),
            'Darwin' => shell_exec('sysctl -n hw.ncpu'),
            'Windows' => shell_exec('wmic cpu get NumberOfCores'),
            default => throw new Exception(self::getOS() . ' not supported.'),
        };
    }

    private static function getLinuxCPUCores(): int
    {
        $cpuInfo = file_get_contents('/proc/cpuinfo');
        $matches = [];

        if ($cpuInfo) {
            preg_match_all('/^processor/m', $cpuInfo, $matches);
        }

        return count($matches[0]);
    }

    private static function getProcStatData(): array
    {
        $cpustats = file_get_contents('/proc/stat');

        if (!$cpustats) {
            throw new Exception('Unable to read /proc/stat');
        }

        $cpus = array_filter(
            explode("\n", $cpustats),
            fn(string $cpu): bool => preg_match('/^cpu[0-999]/', $cpu)
        );

        $data = [];
        $totalCPUExists = false;

        foreach ($cpus as $cpu) {
            $parts = explode(' ', $cpu);
            $cpuNumber = substr($parts[0], 3);

            if ($parts[0] === 'cpu') {
                $totalCPUExists = true;
                $cpuNumber = 'total';
            }

            $data[$cpuNumber] = [
                'user' => $parts[1] ?? 0,
                'nice' => $parts[2] ?? 0,
                'system' => $parts[3] ?? 0,
                'idle' => $parts[4] ?? 0,
                'iowait' => $parts[5] ?? 0,
                'irq' => $parts[6] ?? 0,
                'softirq' => $parts[7] ?? 0,
                'steal' => $parts[8] ?? 0,
                'guest' => $parts[9] ?? 0,
            ];
        }

        if (!$totalCPUExists) {
            $data['total'] = [
                'user' => 0,
                'nice' => 0,
                'system' => 0,
                'idle' => 0,
                'iowait' => 0,
                'irq' => 0,
                'softirq' => 0,
                'steal' => 0,
                'guest' => 0,
            ];

            foreach ($data as $key => $cpu) {
                if ($key === 'total') {
                    continue;
                }

                foreach ($cpu as $field => $value) {
                    $data['total'][$field] += $value;
                }
            }
        }

        return $data;
    }

    public static function getCPUUsage(int $duration = 1): float
    {
        if (self::getOS() !== 'Linux') {
            throw new Exception(self::getOS() . ' not supported.');
        }

        $startCpu = self::getProcStatData()['total'];
        sleep($duration);
        $endCpu = self::getProcStatData()['total'];

        $prevIdle = $startCpu['idle'] + $startCpu['iowait'];
        $idle = $endCpu['idle'] + $endCpu['iowait'];

        $prevNonIdle = $startCpu['user'] + $startCpu['nice'] + $startCpu['system']
            + $startCpu['irq'] + $startCpu['softirq'] + $startCpu['steal'];
        $nonIdle = $endCpu['user'] + $endCpu['nice'] + $endCpu['system']
            + $endCpu['irq'] + $endCpu['softirq'] + $endCpu['steal'];

        $totalDiff = ($idle + $nonIdle) - ($prevIdle + $prevNonIdle);
        $idleDiff = $idle - $prevIdle;

        return (($totalDiff - $idleDiff) / $totalDiff) * 100;
    }

    private static function getProcMemoryInfo(string $field): int
    {
        $memInfo = file_get_contents('/proc/meminfo');

        if (!$memInfo) {
            throw new Exception('Unable to read /proc/meminfo');
        }

        $matches = [];
        preg_match(sprintf('/%s:\s+(\d+)/', $field), $memInfo, $matches);

        if (!isset($matches[1])) {
            throw new Exception("Unable to find {$field} in /proc/meminfo.");
        }

        return $matches[1] / 1024;
    }

    public static function getMemoryTotal(): int
    {
        return match (self::getOS()) {
            'Linux' => self::getProcMemoryInfo('MemTotal'),
            'Darwin' => shell_exec('sysctl -n hw.memsize') / 1024 / 1024,
            default => throw new Exception(self::getOS() . ' not supported.'),
        };
    }

    public static function getMemoryFree(): int
    {
        return match (self::getOS()) {
            'Linux' => self::getProcMemoryInfo('MemFree'),
            'Darwin' => shell_exec('sysctl -n vm.page_free_count') / 1024 / 1024,
            default => throw new Exception(self::getOS() . ' not supported.'),
        };
    }

    public static function getMemoryAvailable(): int
    {
        if (self::getOS() !== 'Linux') {
            throw new Exception(self::getOS() . ' not supported.');
        }

        return self::getProcMemoryInfo('MemAvailable');
    }

    public static function getDiskTotal(string $directory = __DIR__): int
    {
        $totalSpace = disk_total_space($directory);

        if ($totalSpace === false) {
            throw new Exception('Unable to get disk space');
        }

        return $totalSpace / 1024 / 1024;
    }

    public static function getDiskFree(string $directory = __DIR__): int
    {
        $freeSpace = disk_free_space($directory);

        if ($freeSpace === false) {
            throw new Exception('Unable to get free disk space');
        }

        return $freeSpace / 1024 / 1024;
    }

    private static function getDiskStats(): array
    {
        $diskStats = file_get_contents('/proc/diskstats');

        if (!$diskStats) {
            throw new Exception('Unable to read /proc/diskstats');
        }

        $lines = array_filter(
            array_map(
                fn($line) => preg_replace('/\t+/', ' ', trim($line)),
                explode("\n", $diskStats)
            ),
            fn($line) => !empty($line)
        );

        $data = [];
        foreach ($lines as $line) {
            $parts = explode(' ', $line);
            $data[$parts[2]] = $parts;
        }

        return $data;
    }

    public static function getIOUsage(int $duration = 1): array
    {
        $diskStat = self::getDiskStats();
        sleep($duration);
        $diskStat2 = self::getDiskStats();

        $filterDisks = fn(array $disk): bool => !isset($disk[2])
            || !is_string($disk[2])
            || self::containsInvalidDisk($disk[2]);

        $diskStat = array_filter($diskStat, fn($disk) => !$filterDisks($disk));
        $diskStat2 = array_filter($diskStat2, fn($disk) => !$filterDisks($disk));

        $stats = [];

        foreach ($diskStat as $key => $disk) {
            $read1 = $disk[5];
            $read2 = $diskStat2[$key][5];
            $write1 = $disk[9];
            $write2 = $diskStat2[$key][9];

            $stats[$key] = [
                'read' => (($read2 - $read1) * 512) / 1048576,
                'write' => (($write2 - $write1) * 512) / 1048576,
            ];
        }

        $stats['total'] = [
            'read' => array_sum(array_column($stats, 'read')),
            'write' => array_sum(array_column($stats, 'write')),
        ];

        return $stats;
    }

    private static function containsInvalidDisk(string $disk): bool
    {
        foreach (self::INVALID_DISKS as $filter) {
            if (str_contains($disk, $filter)) {
                return true;
            }
        }

        return false;
    }

    public static function getNetworkUsage(int $duration = 1): array
    {
        $interfaces = scandir('/sys/class/net', SCANDIR_SORT_NONE);

        if (!$interfaces) {
            throw new Exception('Unable to read /sys/class/net');
        }

        $interfaces = array_filter(
            $interfaces,
            fn($interface) => !self::containsInvalidInterface($interface)
        );

        $ioUsage = [];

        foreach ($interfaces as $interface) {
            $path = '/sys/class/net/' . $interface . '/statistics/';
            $tx1 = file_get_contents($path . 'tx_bytes');
            $rx1 = file_get_contents($path . 'rx_bytes');

            sleep($duration);

            $tx2 = file_get_contents($path . 'tx_bytes');
            $rx2 = file_get_contents($path . 'rx_bytes');

            $ioUsage[$interface] = [
                'download' => round(($rx2 - $rx1) / 1048576, 2),
                'upload' => round(($tx2 - $tx1) / 1048576, 2),
            ];
        }

        $ioUsage['total'] = [
            'download' => array_sum(array_column($ioUsage, 'download')),
            'upload' => array_sum(array_column($ioUsage, 'upload')),
        ];

        return $ioUsage;
    }

    private static function containsInvalidInterface(string $interface): bool
    {
        foreach (self::INVALID_NET_INTERFACES as $filter) {
            if (str_contains($interface, $filter)) {
                return true;
            }
        }

        return false;
    }

    public static function getEnv(string $name, ?string $default = null): ?string
    {
        return getenv($name) ?: $default;
    }

    public static function isArm64(): bool
    {
        return Architecture::ARM64->matches(self::getArch());
    }

    public static function isArmV7(): bool
    {
        return Architecture::ARMV7->matches(self::getArch());
    }

    public static function isArmV8(): bool
    {
        return Architecture::ARMV8->matches(self::getArch());
    }

    public static function isX86(): bool
    {
        return Architecture::X86->matches(self::getArch());
    }

    public static function isPPC(): bool
    {
        return Architecture::PPC->matches(self::getArch());
    }

    public static function isArch(Architecture|string $arch): bool
    {
        if (is_string($arch)) {
            $arch = Architecture::from($arch);
        }

        return $arch->matches(self::getArch());
    }

    public static function isContainer(): bool
    {
        if (file_exists('/.dockerenv')) {
            return true;
        }

        if (file_exists('/run/.containerenv')) {
            return true;
        }

        $cgroup = @file_get_contents('/proc/1/cgroup');
        if ($cgroup && (str_contains($cgroup, 'docker') || str_contains($cgroup, 'lxc') || str_contains($cgroup, 'containerd'))) {
            return true;
        }

        return false;
    }

    public static function getContainerType(): ?string
    {
        if (file_exists('/.dockerenv')) {
            return 'docker';
        }

        if (file_exists('/run/.containerenv')) {
            return 'podman';
        }

        $cgroup = @file_get_contents('/proc/1/cgroup');
        if ($cgroup) {
            if (str_contains($cgroup, 'docker')) {
                return 'docker';
            }
            if (str_contains($cgroup, 'lxc')) {
                return 'lxc';
            }
            if (str_contains($cgroup, 'containerd')) {
                return 'containerd';
            }
        }

        return null;
    }

    public static function isVirtualized(): bool
    {
        if (self::getOS() !== 'Linux') {
            return false;
        }

        $systemdDetect = @shell_exec('systemd-detect-virt 2>/dev/null');
        if ($systemdDetect && trim($systemdDetect) !== 'none') {
            return true;
        }

        $dmi = @file_get_contents('/sys/class/dmi/id/product_name');
        if ($dmi) {
            $vmIndicators = ['VirtualBox', 'VMware', 'KVM', 'QEMU', 'Xen', 'Parallels', 'Hyper-V'];
            foreach ($vmIndicators as $indicator) {
                if (str_contains($dmi, $indicator)) {
                    return true;
                }
            }
        }

        return false;
    }

    public static function getVirtualizationType(): ?string
    {
        if (self::getOS() !== 'Linux') {
            return null;
        }

        $systemdDetect = @shell_exec('systemd-detect-virt 2>/dev/null');
        if ($systemdDetect && trim($systemdDetect) !== 'none') {
            return trim($systemdDetect);
        }

        $dmi = @file_get_contents('/sys/class/dmi/id/product_name');
        if ($dmi) {
            $vmTypes = [
                'VirtualBox' => 'virtualbox',
                'VMware' => 'vmware',
                'KVM' => 'kvm',
                'QEMU' => 'qemu',
                'Xen' => 'xen',
                'Parallels' => 'parallels',
                'Hyper-V' => 'hyperv',
            ];

            foreach ($vmTypes as $indicator => $type) {
                if (str_contains($dmi, $indicator)) {
                    return $type;
                }
            }
        }

        return null;
    }

    public static function getUptime(): int
    {
        return match (self::getOS()) {
            'Linux' => self::getLinuxUptime(),
            'Darwin' => self::getDarwinUptime(),
            default => throw new Exception(self::getOS() . ' not supported.'),
        };
    }

    private static function getLinuxUptime(): string
    {
        $uptime = file_get_contents('/proc/uptime');

        if (!$uptime) {
            throw new Exception('Unable to read /proc/uptime');
        }

        $parts = explode(' ', $uptime);
        return $parts[0];
    }

    private static function getDarwinUptime(): int
    {
        $bootTime = shell_exec('sysctl -n kern.boottime | awk \'{print $4}\' | sed \'s/,//\'');
        return time() - $bootTime;
    }

    public static function getLoadAverage(): array
    {
        if (self::getOS() === 'Windows') {
            throw new Exception(self::getOS() . ' not supported.');
        }

        $load = sys_getloadavg();

        if ($load === false) {
            throw new Exception('Unable to get load average');
        }

        return [
            '1min' => $load[0],
            '5min' => $load[1],
            '15min' => $load[2],
        ];
    }

    public static function getKernelVersion(): string
    {
        return php_uname('r');
    }

    public static function getCurrentUser(): string
    {
        $user = posix_getpwuid(posix_geteuid());
        return $user['name'] ?? 'unknown';
    }

    public static function isRoot(): bool
    {
        return posix_geteuid() === 0;
    }

    public static function getProcessCount(): int
    {
        return match (self::getOS()) {
            'Linux' => self::getLinuxProcessCount(),
            'Darwin' => shell_exec('ps aux | wc -l'),
            default => throw new Exception(self::getOS() . ' not supported.'),
        };
    }

    private static function getLinuxProcessCount(): int
    {
        $dirs = scandir('/proc');

        if (!$dirs) {
            throw new Exception('Unable to read /proc');
        }

        return count(array_filter($dirs, fn($dir) => is_numeric($dir)));
    }

    public static function getLinuxDistribution(): ?string
    {
        if (self::getOS() !== 'Linux') {
            return null;
        }

        if (file_exists('/etc/os-release')) {
            $osRelease = file_get_contents('/etc/os-release');
            if ($osRelease && preg_match('/^NAME="?([^"\n]+)"?/m', $osRelease, $matches)) {
                return $matches[1];
            }
        }

        if (file_exists('/etc/lsb-release')) {
            $lsbRelease = file_get_contents('/etc/lsb-release');
            if ($lsbRelease && preg_match('/DISTRIB_ID=(.+)/', $lsbRelease, $matches)) {
                return trim($matches[1]);
            }
        }

        return null;
    }

    public static function getSystemInfo(): array
    {
        return [
            'os' => self::getOS(),
            'hostname' => self::getHostname(),
            'architecture' => self::getArch(),
            'kernel' => self::getKernelVersion(),
            'uptime' => self::getUptime(),
            'container' => self::getContainerType(),
            'virtualization' => self::getVirtualizationType(),
            'distribution' => self::getLinuxDistribution(),
            'user' => self::getCurrentUser(),
            'is_root' => self::isRoot(),
        ];
    }
}
