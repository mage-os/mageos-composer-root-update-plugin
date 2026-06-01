<?php
/**
 * Copyright © 2016 Magento. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Updater;

use Composer\Composer;
use Composer\Downloader\FilesystemException;
use Magento\ComposerRootUpdatePlugin\Utils\PackageUtils;
use Magento\ComposerRootUpdatePlugin\Utils\Console;
use Magento\ComposerRootUpdatePlugin\Plugin\PluginDefinition;
use RuntimeException;

/**
 * Handles updates of the root project composer.json file based on necessary changes for the target version
 */
class RootProjectUpdater
{
    /**
     * @var Console $console
     */
    protected $console;
    
    /**
     * @var Composer $composer
     */
    protected $composer;

    /**
     * @var PackageUtils $pkgUtils;
     */
    protected $pkgUtils;

    /**
     * @var array $jsonChanges Json-writable sections of composer.json that have been updated
     */
    protected $jsonChanges;

    /**
     * @param Console $console
     * @param Composer $composer
     * @return void
     */
    public function __construct(Console $console, Composer $composer)
    {
        $this->console = $console;
        $this->composer = $composer;
        $this->pkgUtils = new PackageUtils($console, $composer);
        $this->jsonChanges = [];
    }

    /**
     * Look ahead to the target magento/project version and execute any changes to the root composer.json file in-memory
     *
     * @param RootPackageRetriever $retriever
     * @param bool $overrideOption
     * @param bool $ignorePlatformReqs
     * @param string $phpVersion
     * @param string $stability
     * @return bool Returns true if updates were necessary and prepared successfully
     */
    public function runUpdate(
        RootPackageRetriever $retriever,
        bool $overrideOption,
        bool $ignorePlatformReqs,
        string $phpVersion,
        string $stability,
        bool $isOverrideCommand
    ): bool {
        $composer = $this->composer;

        if (!$this->pkgUtils->findRequire($composer, PluginDefinition::PACKAGE_NAME)) {
            // If the plugin requirement has been removed but we're still trying to run (code still existing in the
            // vendor directory), return without executing.
            return false;
        }

        $origEdition = $retriever->getOriginalEdition();
        $origVersion = $retriever->getOriginalVersion();
        $prettyOrigVersion = $retriever->getPrettyOriginalVersion();

        if ($origEdition === null || $origVersion === null) {
            // The currently-installed Mage-OS metapackage could not be determined from composer.lock (for
            // example when migrating from a magento/* installation, which this plugin no longer recognizes as
            // an origin). Skip gracefully instead of failing with a TypeError; the base project can be supplied
            // explicitly with --base-project-edition / --base-project-version when an upgrade is intended.
            $this->console->warning(
                'Could not determine the currently-installed Mage-OS edition/version from composer.lock; ' .
                'skipping root composer.json update. Re-run with --base-project-edition and ' .
                '--base-project-version to supply the base project explicitly.'
            );
            return false;
        }

        if (!$retriever->getTargetRootPackage($ignorePlatformReqs, $phpVersion, $stability)) {
            throw new RuntimeException('Root composer.json updates cannot run without a valid target metapackage');
        }

        if ($origEdition == $retriever->getTargetEdition() && $origVersion == $retriever->getTargetVersion()) {
            $this->console->labeledVerbose(
                'The metapackage requirement matches the current installation; no root updates are required'
            );
            return false;
        }

        if (!$retriever->getOriginalRootPackage($overrideOption)) {
            $this->console->log('Skipping root composer.json update.');
            return false;
        }

        $this->console->setVerboseLabel($retriever->getTargetLabel());
        $project = $this->pkgUtils->getProjectPackageName($origEdition);
        $this->console->labeledVerbose(
            "Base root project package version: $project $prettyOrigVersion"
        );

        $resolver = new DeltaResolver($this->console, $overrideOption, $retriever, $isOverrideCommand);

        $jsonChanges = $resolver->resolveRootDeltas();

        if ($jsonChanges) {
            $this->jsonChanges = $jsonChanges;
            return true;
        }

        return false;
    }

    /**
     * Write the changed composer.json file
     *
     * @return void
     * @throws FilesystemException if the composer.json read or write failed
     */
    public function writeUpdatedComposerJson()
    {
        if (!$this->jsonChanges) {
            return;
        }
        $filePath = $this->composer->getConfig()->getConfigSource()->getName();
        $json = json_decode(file_get_contents($filePath), true);
        if ($json === null) {
            throw new FilesystemException('Failed to read ' . $filePath);
        }

        foreach ($this->jsonChanges as $section => $newContents) {
            if ($newContents === null || $newContents === []) {
                if (key_exists($section, $json)) {
                    unset($json[$section]);
                }
            } else {
                $json[$section] = $newContents;
            }
        }

        $this->console->labeledVerbose('Writing changes to the root composer.json...');

        $retVal = file_put_contents(
            $filePath,
            json_encode($json, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        if ($retVal === false) {
            throw new FilesystemException('Failed to write updated magento/project values to ' . $filePath);
        }
        $this->console->labeledVerbose("$filePath has been updated");
    }

    /**
     * Return the changes to be made in composer.json
     *
     * @return array
     */
    public function getJsonChanges(): array
    {
        return $this->jsonChanges;
    }
}
