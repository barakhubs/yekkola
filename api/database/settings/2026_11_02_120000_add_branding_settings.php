<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/** Brand images (PRD-09 FR-15): none uploaded yet, so the apps show their built-in logo and icon. */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('branding.logo_path', null);
        $this->migrator->add('branding.footer_logo_path', null);
        $this->migrator->add('branding.favicon_path', null);
        $this->migrator->add('branding.share_image_path', null);
    }
};
