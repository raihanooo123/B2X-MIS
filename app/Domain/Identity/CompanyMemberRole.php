<?php

namespace App\Domain\Identity;

/** 02 §4.4 and §17.1: customer roles, never staff roles. */
enum CompanyMemberRole: string
{
    case Owner = 'owner';
    case Buyer = 'buyer';
    case Approver = 'approver';
    case Viewer = 'viewer';

    /** @return array<string, string> */
    public static function options(): array
    {
        return array_combine(array_column(self::cases(), 'value'), array_map(fn (self $role): string => ucfirst($role->value), self::cases()));
    }
}
