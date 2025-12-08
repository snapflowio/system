<?php

declare(strict_types=1);

namespace Snapflow\System;

enum Architecture: string
{
    case X86 = 'x86';
    case PPC = 'ppc';
    case ARM64 = 'arm64';
    case ARMV7 = 'armv7';
    case ARMV8 = 'armv8';

    public function getPattern(): string
    {
        return match ($this) {
            self::X86 => '/(x86*|i386|i686)/',
            self::PPC => '/(ppc*)/',
            self::ARM64 => '/(arm64|aarch64)/',
            self::ARMV7 => '/(armv7)/',
            self::ARMV8 => '/(armv8)/',
        };
    }
    public static function fromString(string $arch): self
    {
        foreach (self::cases() as $case) {
            if (preg_match($case->getPattern(), $arch)) {
                return $case;
            }
        }

        throw new \Exception("'{$arch}' enum not found.");
    }

    public function matches(string $arch): bool
    {
        return preg_match($this->getPattern(), $arch);
    }
}
