<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\block\Entity\Block;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the placement forms: place a block, see it render, edit, remove.
 *
 * The generic operation form serves each of the resource's operations at
 * a route of its own under the block layout, without JavaScript: the add
 * form places a "Powered by Drupal" block in Stark's footer, which then
 * renders, after a region of another theme is refused; the edit form
 * changes its title; the remove form asks before
 * it removes, and the block is gone from the page.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class BlockPlacementFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'data_surface_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A second theme, whose regions are offered until Stark is chosen.
    $this->container->get('theme_installer')->install(['claro']);
  }

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests placing, editing and removing through the forms.
   */
  public function testPlaceEditRemove(): void {
    $assert = $this->assertSession();
    $this->drupalGet('admin/structure/block/surface/add');
    $assert->statusCodeEquals(403);
    $this->drupalLogin($this->drupalCreateUser(['administer blocks']));

    $this->drupalGet('admin/structure/block/surface/add');
    $assert->statusCodeEquals(200);
    $assert->optionExists('surface[plugin]', 'system_powered_by_block');
    $assert->optionExists('surface[theme]', 'stark');
    // A region the theme lacks is refused on the form, by its element:
    // the theme chosen draws its regions, which the value is not among.
    $edit = [
      'surface[label]' => 'Made with Drupal',
      'surface[id]' => 'made_with',
      'surface[plugin]' => 'system_powered_by_block',
      'surface[theme]' => 'stark',
      'surface[region]' => 'pre_content',
    ];
    $this->submitForm($edit, 'Save');
    $assert->pageTextContains('The submitted value pre_content in the Region element is not allowed.');
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('made_with'));
    $this->drupalGet('admin/structure/block/surface/add');
    $this->submitForm([
      'surface[label]' => 'Made with Drupal',
      'surface[id]' => 'made_with',
      'surface[plugin]' => 'system_powered_by_block',
      'surface[theme]' => 'stark',
      'surface[region]' => 'footer',
    ], 'Save');
    $assert->pageTextContains('The block Made with Drupal has been placed.');
    $assert->addressEquals('admin/structure/block/list/stark');
    $this->container->get('entity_type.manager')->getStorage('block')->resetCache();
    $block = Block::load('made_with');
    $this->assertNotNull($block);
    $this->assertSame('footer', $block->getRegion());

    // It renders, its title shown.
    $this->drupalGet('<front>');
    $assert->pageTextContains('Made with Drupal');
    $assert->pageTextContains('Powered by Drupal');

    // On edit the machine name is held, and the region offers the theme's.
    $this->drupalGet('admin/structure/block/surface/made_with');
    $assert->statusCodeEquals(200);
    $assert->fieldDisabled('surface[id]');
    $assert->optionExists('surface[region]', 'sidebar_first');
    $this->submitForm([
      'surface[label]' => 'Built with Drupal',
      'surface[region]' => 'footer',
      'surface[label_display]' => FALSE,
    ], 'Save');
    $assert->pageTextContains('The block Built with Drupal has been saved.');
    $this->drupalGet('<front>');
    $assert->pageTextNotContains('Built with Drupal');
    $assert->pageTextContains('Powered by Drupal');

    // Removed after a confirmation.
    $this->drupalGet('admin/structure/block/surface/made_with/delete');
    $assert->pageTextContains('made_with is removed, and this cannot be undone.');
    $this->submitForm([], 'Remove a placed block');
    $assert->pageTextContains('The block Built with Drupal has been removed.');
    $this->assertNull($this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged('made_with'));
    $this->drupalGet('<front>');
    $assert->pageTextNotContains('Powered by Drupal');
  }

}
