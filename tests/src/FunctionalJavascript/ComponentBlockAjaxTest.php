<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\FunctionalJavascript;

use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use Drupal\block\Entity\Block;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Example 13 in a browser: a component block placed through the block form.
 *
 * The block layout's own form, with the block's settings drawn from its
 * structure: choosing a component draws its props and slots over AJAX,
 * read from the schema and metadata it declares, choosing another swaps
 * them for its own, a slot's kind draws what it holds, a nested component
 * swaps its own props the same way, and the block saved renders the
 * component with the props and slots it holds.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ComponentBlockAjaxTest extends WebDriverTestBase {

  use ReplacedElementsTrait;

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
    $this->drupalGet('admin/structure/block/add/surface_component/stark');
    $component = $assert->fieldExists('settings[component]');
    $this->assertSame('', $component->getValue(), 'No component is chosen yet.');
    $assert->optionExists('settings[component]', 'surface_examples:card');
    $assert->optionExists('settings[component]', 'surface_examples:hero');
    $assert->fieldNotExists('settings[props][heading]');

    // The card's props, over AJAX, as its schema declares them.
    $this->probe(['settings[component]', 'settings[label]']);
    $page->selectFieldOption('settings[component]', 'surface_examples:card');
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
    $page->selectFieldOption('settings[component]', 'surface_examples:hero');
    $assert->assertWaitOnAjaxRequest();
    $assert->fieldNotExists('settings[props][heading]');
    $this->assertSame('40', $assert->fieldExists('settings[props][overlay]')->getValue());
    $page->selectFieldOption('settings[component]', 'surface_examples:card');
    $assert->assertWaitOnAjaxRequest();
    $heading = $assert->fieldExists('settings[props][heading]');

    $id = $assert->fieldExists('id')->getValue();
    $page->fillField('settings[label]', 'Spotlight');
    $heading->setValue('Doors open at six');
    // The card's text is its slot, which holds text alone.
    $assert->fieldNotExists('settings[props][body]');
    $page->fillField('settings[slots][body][text]', 'Bring your ticket.');
    $page->selectFieldOption('settings[props][tone]', 'highlight');
    $page->fillField('settings[props][link][url]', 'https://example.com/tickets');
    $page->selectFieldOption('region', 'content');
    $page->pressButton('Save block');
    $this->assertNotNull($assert->waitForText('The block configuration has been saved.'));

    $block = Block::load($id);
    $this->assertInstanceOf(Block::class, $block);
    $settings = $block->get('settings');
    $this->assertSame('surface_examples:card', $settings['component']);
    $this->assertSame([
      'heading' => 'Doors open at six',
      'tone' => 'highlight',
      'link' => ['url' => 'https://example.com/tickets', 'label' => NULL],
    ], $settings['props']);
    $this->assertSame(['body' => ['kind' => 'text', 'text' => 'Bring your ticket.']], $settings['slots']);
    $this->assertCount(0, $this->container->get('config.typed')->get('block.block.' . $id)->validate());

    // Rendered: the card, with the props the block holds.
    $this->drupalGet('<front>');
    $card = $assert->elementExists('css', 'article.surface-card.surface-card--highlight');
    $this->assertSame('Doors open at six', $card->find('css', 'h3')?->getText());
    $this->assertSame('Bring your ticket.', $card->find('css', 'p')?->getText());
    $this->assertSame('https://example.com/tickets', $card->find('css', 'a')?->getAttribute('href'));
  }

  /**
   * Tests a slot's kind draws what it holds, and a nested component swaps.
   *
   * A section's content slot: switched to one component, it draws the
   * component key, offering the two the slot expects; a card chosen draws
   * the card's props and its own slot below it over AJAX, a hero swaps
   * them for the hero's, and the card again is saved and rendered inside
   * the section.
   */
  public function testTheSlotsKindAndTheNestedComponentSwap(): void {
    $assert = $this->assertSession();
    $page = $this->getSession()->getPage();
    $this->drupalGet('admin/structure/block/add/surface_component/stark');
    $page->selectFieldOption('settings[component]', 'surface_examples:section');
    $assert->assertWaitOnAjaxRequest();
    $kind = $assert->fieldExists('settings[slots][content][kind]');
    $this->assertSame('text', $kind->getValue());
    $assert->fieldExists('settings[slots][content][text]');
    $assert->fieldNotExists('settings[slots][content][component][component]');

    // One component: the component key, what the slot expects alone.
    $this->probe(['settings[component]']);
    $page->selectFieldOption('settings[slots][content][kind]', 'component');
    $assert->assertWaitOnAjaxRequest();
    $nested = 'settings[slots][content][component]';
    $this->assertNotNull($assert->waitForField($nested . '[component]'));
    $assert->fieldNotExists('settings[slots][content][text]');
    $assert->optionExists($nested . '[component]', 'surface_examples:card');
    $assert->optionExists($nested . '[component]', 'surface_examples:hero');
    $assert->optionNotExists($nested . '[component]', 'surface_examples:call_to_action');
    $this->assertTrue($this->stillProbed('settings[component]'), 'The component chosen above is not replaced.');

    // The nested component's props and slots, swapped over AJAX.
    $page->selectFieldOption($nested . '[component]', 'surface_examples:hero');
    $assert->assertWaitOnAjaxRequest();
    $this->assertSame('40', $assert->fieldExists($nested . '[props][overlay]')->getValue());
    $assert->fieldExists($nested . '[slots][actions][kind]');
    $page->selectFieldOption($nested . '[component]', 'surface_examples:card');
    $assert->assertWaitOnAjaxRequest();
    $assert->fieldNotExists($nested . '[props][overlay]');
    $heading = $assert->fieldExists($nested . '[props][heading]');

    $page->fillField('settings[label]', 'Tonight');
    $page->fillField('settings[props][heading]', 'Tonight');
    $heading->setValue('Talks start at seven');
    $page->fillField($nested . '[slots][body][text]', 'In the main hall.');
    $page->selectFieldOption('region', 'content');
    $page->pressButton('Save block');
    $this->assertTrue($assert->waitForText('The block configuration has been saved.'), (string) $page->find('css', '[role="contentinfo"], [role="alert"], .messages')?->getText());

    $blocks = $this->container->get('entity_type.manager')->getStorage('block')->loadByProperties(['plugin' => 'surface_component']);
    $this->assertCount(1, $blocks);
    $block = reset($blocks);
    $this->assertSame([
      'content' => [
        'kind' => 'component',
        'component' => [
          'component' => 'surface_examples:card',
          'props' => [
            'heading' => 'Talks start at seven',
            'tone' => 'plain',
            'link' => ['url' => NULL, 'label' => NULL],
          ],
          'slots' => ['body' => ['kind' => 'text', 'text' => 'In the main hall.']],
        ],
      ],
    ], $block->get('settings')['slots']);

    $this->drupalGet('<front>');
    $section = $assert->elementExists('css', 'section.surface-section');
    $this->assertSame('Tonight', $section->find('css', 'h2')?->getText());
    $this->assertSame('Talks start at seven', $section->find('css', 'article.surface-card h3')?->getText());
    $this->assertSame('In the main hall.', $section->find('css', 'article.surface-card p')?->getText());
  }

}
