import preset from '../../../../vendor/filament/filament/tailwind.config.preset';

/**
 * The admin panel's Filament theme (resources/css/filament/admin/theme.css).
 * Separate from the storefront's tailwind.config.js: the panel is Filament's
 * Blade, the storefront is React, and neither should purge the other.
 */
export default {
    presets: [preset],
    content: [
        './app/Filament/**/*.php',
        './app/Providers/Filament/**/*.php',
        './resources/views/filament/**/*.blade.php',
        './vendor/filament/**/*.blade.php',
    ],
};
