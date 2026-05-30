<?php
/**
 * Copyright © Magento, Inc. All rights reserved.
 * See COPYING.txt for license details.
 */

namespace Magento\ComposerRootUpdatePlugin\Utils;

use Composer\IO\IOInterface;
use Magento\ComposerRootUpdatePlugin\UpdatePluginTestCase;

/**
 * Covers the Mage-OS package-name handling: the plugin recognizes mage-os/product-* metapackages and
 * constructs mage-os/project-* / mage-os/product-* names, and no longer treats magento/* as a metapackage.
 */
class PackageUtilsTest extends UpdatePluginTestCase
{
    /**
     * @var PackageUtils
     */
    private $pkgUtils;

    protected function setUp(): void
    {
        $io = $this->getMockForAbstractClass(IOInterface::class);
        $this->pkgUtils = new PackageUtils(new Console($io));
    }

    /**
     * @dataProvider metapackageEditionDataProvider
     * @param string $packageName
     * @param string|null $expectedEdition
     */
    public function testGetMetapackageEdition(string $packageName, ?string $expectedEdition): void
    {
        $this->assertSame($expectedEdition, $this->pkgUtils->getMetapackageEdition($packageName));
    }

    /**
     * @return array
     */
    public function metapackageEditionDataProvider(): array
    {
        return [
            'mage-os community' => ['mage-os/product-community-edition', 'community'],
            'mage-os minimal' => ['mage-os/product-minimal-edition', 'minimal'],
            'mage-os community mixed case' => ['mage-os/Product-Community-Edition', 'community'],
            'magento community no longer recognized' => ['magento/product-community-edition', null],
            'magento enterprise no longer recognized' => ['magento/product-enterprise-edition', null],
            'magento cloud no longer recognized' => ['magento/magento-cloud-metapackage', null],
            'unrelated package' => ['some/other-package', null],
            'empty string' => ['', null],
        ];
    }

    public function testGetProjectPackageName(): void
    {
        $this->assertSame(
            'mage-os/project-community-edition',
            $this->pkgUtils->getProjectPackageName('community')
        );
        $this->assertSame(
            'mage-os/project-minimal-edition',
            $this->pkgUtils->getProjectPackageName('minimal')
        );
    }

    public function testGetMetapackageName(): void
    {
        $this->assertSame(
            'mage-os/product-community-edition',
            $this->pkgUtils->getMetapackageName('community')
        );
        $this->assertSame(
            'mage-os/product-minimal-edition',
            $this->pkgUtils->getMetapackageName('minimal')
        );
    }

    public function testGetEditionLabel(): void
    {
        $this->assertSame('Mage-OS', $this->pkgUtils->getEditionLabel('community'));
        $this->assertSame('Mage-OS Minimal', $this->pkgUtils->getEditionLabel('minimal'));
        // Editions that no longer exist for Mage-OS return null.
        $this->assertNull($this->pkgUtils->getEditionLabel('enterprise'));
        $this->assertNull($this->pkgUtils->getEditionLabel('cloud'));
    }
}
