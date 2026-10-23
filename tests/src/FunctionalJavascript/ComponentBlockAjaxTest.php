<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\block\Entity\Block;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Example 13 in a browser: a component block placed through the block form.
 *
 * The block layout's own form, with the block's settings drawn from its
 * structure: choosing a component draws its props over AJAX, read from
 * the schema it declares, choosing another swaps them for its own, and the
 * block saved renders the component with the props it holds.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ComponentBlockAjaxTest extends WebDriverTestBase {

  use ReplacedElementsTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'data_surface',
    'data_surface_examples',
    'data_surface_component_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

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
   * Tests choosing a component draws its props, and the block renders it.
   */
  public function testChoosingComponentDrawsItsProps(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('admin/structure/block/add/data_surface_component/stark');
    $component = $assert->fieldExists('settings[component]');
    $this->assertSame('', $component->getValue(), 'No component is chosen yet.');
    $assert->optionExists('settings[component]', 'data_surface_examples:card');
    $assert->optionExists('settings[component]', 'data_surface_examples:hero');
    $assert->fieldNotExists('settings[props][heading]');

    // The card's props, over AJAX, as its schema declares them.
    $this->probe(['settings[component]', 'settings[label]']);
    $page->selectFieldOption('settings[component]', 'data_surface_examples:card');
    $assert->assertWaitOnAjaxRequest();
    $heading = $assert->fieldExists('settings[props][heading]');
    $this->assertTrue($heading->isVisible());
    $this->assertSame('80', $heading->getAttribute('maxlength'));
    $this->assertSame('plain', $assert->fieldExists('settings[props][tone]')->getValue());
    $assert->fieldExists('settings[props][link][url]');
    // The component chosen and the host's own elements are not replaced.
    $this->assertTrue($this->stillProbed('settings[component]'));
    $this->assertTrue($this->stillProbed('settings[label]'));

    // Another component swaps them for its own.
    $page->selectFieldOption('settings[component]', 'data_surface_examples:hero');
    $assert->assertWaitOnAjaxRequest();
    $assert->fieldNotExists('settings[props][heading]');
    $this->assertSame('40', $assert->fieldExists('settings[props][overlay]')->getValue());
    $page->selectFieldOption('settings[component]', 'data_surface_examples:card');
    $assert->assertWaitOnAjaxRequest();
    $heading = $assert->fieldExists('settings[props][heading]');

    $id = $assert->fieldExists('id')->getValue();
    $page->fillField('settings[label]', 'Spotlight');
    $heading->setValue('Doors open at six');
    $page->fillField('settings[props][body]', 'Bring your ticket.');
    $page->selectFieldOption('settings[props][tone]', 'highlight');
    $page->fillField('settings[props][link][url]', 'https://example.com/tickets');
    $page->selectFieldOption('region', 'content');
    $page->pressButton('Save block');
    $this->assertNotNull($assert->waitForText('The block configuration has been saved.'));

    $block = Block::load($id);
    $this->assertInstanceOf(Block::class, $block);
    $settings = $block->get('settings');
    $this->assertSame('data_surface_examples:card', $settings['component']);
    $this->assertSame([
      'heading' => 'Doors open at six',
      'body' => 'Bring your ticket.',
      'tone' => 'highlight',
      'link' => ['url' => 'https://example.com/tickets', 'label' => NULL],
    ], $settings['props']);
    $this->assertCount(0, $this->container->get('config.typed')->get('block.block.' . $id)->validate());

    // Rendered: the card, with the props the block holds.
    $this->drupalGet('<front>');
    $card = $assert->elementExists('css', 'article.data-surface-card.data-surface-card--highlight');
    $this->assertSame('Doors open at six', $card->find('css', 'h3')?->getText());
    $this->assertSame('Bring your ticket.', $card->find('css', 'p')?->getText());
    $this->assertSame('https://example.com/tickets', $card->find('css', 'a')?->getAttribute('href'));
  }

}
