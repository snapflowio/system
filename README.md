# Snapflow System

A simple and lightweight library for retrieving system information from the host device.

## Installation

```bash
composer require snapflow/system
```

## Quick Start

```php
<?php

use Snapflow\System\System;

System::getOS();
System::getHostname();
System::getArch();
System::getArchEnum();
System::getKernelVersion();
System::getCurrentUser();
System::isRoot();
System::getUptime();
System::getProcessCount();
System::getLinuxDistribution();
System::getSystemInfo();

System::getCPUCores();
System::getCPUUsage(1);
System::getLoadAverage();

System::getMemoryTotal();
System::getMemoryFree();
System::getMemoryAvailable();

System::getDiskTotal('/');
System::getDiskFree('/');
System::getIOUsage(1);

System::getNetworkUsage(1);

System::isArm64();
System::isArmV7();
System::isArmV8();
System::isX86();
System::isPPC();
System::isArch('x86_64');

System::isContainer();
System::getContainerType();
System::isVirtualized();
System::getVirtualizationType();

System::getEnv('PATH');
System::getEnv('HOME', '/default/path');
```

## License

This library is available under the MIT License.

## Copyright

```
Copyright (c) 2025 Snapflow
```