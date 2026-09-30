<?php

/*
 * This file is part of the Kimai Google Calendar plugin.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace KimaiPlugin\GoogleCalendarBundle\Command;

use App\Command\AbstractBundleInstallerCommand;

final class InstallCommand extends AbstractBundleInstallerCommand
{
    protected function getBundleCommandNamePart(): string
    {
        return 'google-calendar';
    }

    protected function getMigrationConfigFilename(): ?string
    {
        return __DIR__ . '/../Resources/config/doctrine_migrations.yaml';
    }
}
