<?php

namespace App\Filament\Pages;

use App\Models\User;
use Filament\Pages\Dashboard as BaseDashboard;
use Illuminate\Support\Facades\Auth;

/**
 * The panel's home: what needs attention today, for the signed-in role.
 * Each widget checks the same policy as the screen it links to, so a
 * role only sees the figures it could open (CLAUDE.md: one authorisation
 * path).
 */
class Dashboard extends BaseDashboard
{
    protected static ?string $navigationIcon = 'heroicon-o-home';

    public function getHeading(): string
    {
        $user = Auth::user();
        $hour = (int) now()->format('G');
        $greeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 18 => 'Good afternoon',
            default => 'Good evening',
        };

        return $user instanceof User ? "{$greeting}, {$user->first_name}" : $greeting;
    }

    public function getSubheading(): string
    {
        return now()->format('l j F Y').' · what needs attention today.';
    }

    public function getColumns(): int|string|array
    {
        return ['default' => 1, 'lg' => 3];
    }
}
