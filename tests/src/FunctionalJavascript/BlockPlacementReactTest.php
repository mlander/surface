<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\FunctionalJavascript;

use Drupal\block\Entity\Block;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the React page for the placement resource, in a browser.
 *
 * The add page draws the keys every block has; choosing a plugin draws
 * its settings, read from its config schema, and choosing a theme offers
 * its regions, both from the full schema's conditionals. Submitted, the
 * block is placed and the page moves to its edit, which opens on what is
 * stored and saves a change.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BlockPlacementReactTest extends WebDriverTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'surface_examples_block',
    'surface_examples_react',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * Tests placing a block, then editing it.
   */
  public function testAddThenEdit(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalLogin($this->drupalCreateUser(['administer blocks']));

    $this->drupalGet('surface-react/block.placement/create');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-plugin'));
    $page->fillField('dsr-label', 'Branding');
    $page->fillField('dsr-id', 'branding');
    $page->selectFieldOption('plugin', 'Site branding');
    $this->assertNotNull($assert->waitForElement('css', '#dsr-settings-use_site_slogan'), 'The branding block\'s settings, read from its config schema.');
    $page->selectFieldOption('theme', 'Stark');
    $this->assertNotNull($assert->waitForElement('xpath', '//select[@id="dsr-region"]/option[text()="Left sidebar"]'), 'Stark\'s regions.');
    $page->selectFieldOption('region', 'Left sidebar');
    $page->uncheckField('dsr-settings-use_site_slogan');
    $page->pressButton('Submit');
    // Created, the page moves to where the block now lives: its edit.
    $this->assertTrue($this->getSession()->wait(10000, "window.location.pathname.endsWith('/surface-react/block.placement/update')"), 'The page moved to the placed block.');
    $this->container->get('entity_type.manager')->getStorage('block')->resetCache();
    $block = Block::load('branding');
    $this->assertNotNull($block);
    $this->assertSame('sidebar_first', $block->getRegion());
    $this->assertFalse($block->get('settings')['use_site_slogan']);

    $this->drupalGet('surface-react/block.placement/update', ['query' => ['id' => 'branding']]);
    $this->assertNotNull($assert->waitForElement('css', '#dsr-weight'));
    $this->assertSame('Branding', $page->findField('dsr-label')->getValue());
    $page->fillField('dsr-weight', '4');
    $page->pressButton('Submit');
    $this->assertNotNull($assert->waitForText('Saved: the values were written.'));
    $this->container->get('entity_type.manager')->getStorage('block')->resetCache();
    $this->assertSame(4, Block::load('branding')->getWeight());
  }

}
