<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\block\Entity\Block;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the component block through the block form, without JavaScript.
 *
 * What a person without JavaScript gets: the block form offers the
 * components the site has, a block placed with no component renders
 * nothing, and a placed block's form draws the props of the component it
 * holds, takes a change and renders it. Choosing a component on the add
 * form and saving in the same request is refused for a component whose
 * props require something, since the props are drawn for what is stored
 * and the host cannot overlay the submission yet; with JavaScript the
 * props are drawn when the component is chosen (ComponentBlockAjaxTest).
 *
 * @see docs/decisions.md#a-plugin-host-cannot-overlay-a-full-submission
 * @see \Drupal\Tests\surface\FunctionalJavascript\ComponentBlockAjaxTest
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ComponentBlockPlacementTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'surface',
    'surface_examples',
    'surface_examples_component_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The block placement form of the component block, in stark.
   */
  protected const ADD = 'admin/structure/block/add/surface_component/stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser([
      'administer blocks',
      'access administration pages',
    ]));
  }

  /**
   * Tests a block with no component is placed and renders nothing.
   */
  public function testNoComponentRendersNothing(): void {
    $assert = $this->assertSession();
    $this->drupalGet(self::ADD);
    $assert->statusCodeEquals(200);
    $assert->optionExists('settings[component]', 'surface_examples:card');
    $assert->optionExists('settings[component]', 'surface_examples:hero');
    $assert->optionExists('settings[component]', 'surface_examples:call_to_action');
    $assert->fieldNotExists('settings[props][heading]');

    $id = $assert->fieldExists('id')->getValue();
    $this->submitForm(['region' => 'content', 'settings[label]' => 'Nothing yet'], 'Save block');
    $assert->pageTextContains('The block configuration has been saved.');
    $block = Block::load($id);
    $this->assertInstanceOf(Block::class, $block);
    $this->assertNull($block->get('settings')['component']);

    $this->drupalGet('<front>');
    $assert->statusCodeEquals(200);
    $assert->pageTextNotContains('Nothing yet');
    $assert->elementNotExists('css', 'article.surface-card');
  }

  /**
   * Tests choosing a component and saving at once waits for its props.
   */
  public function testComponentChosenWithoutItsPropsIsRefused(): void {
    $assert = $this->assertSession();
    $this->drupalGet(self::ADD);
    $id = $assert->fieldExists('id')->getValue();
    $this->submitForm(['region' => 'content', 'settings[component]' => 'surface_examples:card'], 'Save block');
    $assert->pageTextContains('Heading is required.');
    $assert->pageTextNotContains('The block configuration has been saved.');
    $this->assertNull(Block::load($id));
  }

  /**
   * Tests a placed block's form draws its component's props and saves.
   */
  public function testPlacedBlockIsChangedThroughItsForm(): void {
    $assert = $this->assertSession();
    $block = Block::create([
      'id' => 'hero',
      'theme' => 'stark',
      'region' => 'content',
      'plugin' => 'surface_component',
      'settings' => [
        'label' => 'Hero',
        'label_display' => '0',
        'component' => 'surface_examples:hero',
        'props' => ['title' => 'Doors open at half past six'],
      ],
    ]);
    $block->save();
    $this->assertSame(['module' => ['surface_examples', 'surface_examples_component_block'], 'theme' => ['stark']], $block->getDependencies(), 'The block depends on the module that provides its component.');

    $this->drupalGet('<front>');
    $hero = $assert->elementExists('css', 'header.surface-hero.surface-hero--center');
    $this->assertSame('Doors open at half past six', $hero->find('css', 'h2')?->getText());
    $this->assertStringContainsString('--surface-hero-overlay: 40%', (string) $hero->getAttribute('style'));

    $this->drupalGet($block->toUrl('edit-form'));
    $this->assertSame('Doors open at half past six', $assert->fieldExists('settings[props][title]')->getValue());
    $this->assertSame('40', $assert->fieldExists('settings[props][overlay]')->getValue());
    $assert->fieldNotExists('settings[props][heading]');
    $this->submitForm([
      'settings[props][title]' => 'Doors open at six',
      'settings[props][align]' => 'start',
      'settings[props][overlay]' => '60',
    ], 'Save block');
    $assert->pageTextContains('The block configuration has been saved.');
    $this->assertSame([
      'title' => 'Doors open at six',
      'subtitle' => NULL,
      'align' => 'start',
      'overlay' => 60,
      'full_width' => FALSE,
    ], Block::load('hero')->get('settings')['props']);
    $this->assertCount(0, $this->container->get('config.typed')->get('block.block.hero')->validate());

    // A hero darker than its schema allows is refused on its element.
    $this->drupalGet($block->toUrl('edit-form'));
    $this->submitForm(['settings[props][overlay]' => '140'], 'Save block');
    $assert->pageTextNotContains('The block configuration has been saved.');
    $assert->elementExists('css', 'input[name="settings[props][overlay]"].error');

    $this->drupalGet('<front>');
    $hero = $assert->elementExists('css', 'header.surface-hero.surface-hero--start');
    $this->assertSame('Doors open at six', $hero->find('css', 'h2')?->getText());
    $this->assertStringContainsString('--surface-hero-overlay: 60%', (string) $hero->getAttribute('style'));
  }

}
