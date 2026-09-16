<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\block\Entity\Block;
use Drupal\data_surface\Pipeline\DataSurfacePipelineInterface;
use Drupal\node\Entity\NodeType;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a placed block whose stored bundle was deleted under it.
 *
 * The scenario item 11 was decided from, end to end, through a real
 * browser: a block is configured for a node bundle, the bundle is
 * deleted, and somebody opens the block's configuration form.
 *
 * Before the stale model this was a fatal, and not a small one. Every
 * host calls setConfiguration() from inside its own constructor, so the
 * plugin could not be built at all: the block's form, the block listing
 * and every page the block was placed on died with
 * "Invalid configuration: bundle: The value you selected is not a valid
 * choice." The one page that could have fixed the value was the one page
 * that could not be opened.
 *
 * The kernel tests state the rules; this states that they hold across a
 * real request, a real POST and a real config write, which is the only
 * place the trap can actually be sprung — a browser submits the select
 * it was handed, whether or not anybody touched it.
 *
 * @see \Drupal\Tests\data_surface\Kernel\StaleValueTest
 * @see \Drupal\Tests\data_surface\Kernel\DemoBlockTest
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class StaleValueBlockFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'node',
    'field',
    'text',
    'data_surface',
    'data_surface_demo',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The id the placed block is saved under.
   */
  protected const BLOCK_ID = 'stale_demo';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    NodeType::create(['type' => 'article', 'name' => 'Article'])->save();
    NodeType::create(['type' => 'page', 'name' => 'Page'])->save();
    $this->drupalLogin($this->drupalCreateUser([
      'administer blocks',
      'access administration pages',
    ]));
  }

  /**
   * Reads what the placed block has stored.
   *
   * @return array
   *   The block's settings.
   */
  protected function storedSettings(): array {
    $block = Block::load(self::BLOCK_ID);
    $this->assertInstanceOf(Block::class, $block);
    return $block->get('settings');
  }

  /**
   * Tests the whole life of a stale bundle, from deletion to a new choice.
   */
  public function testTheStaleBundleIsShownKeptAndChosenAgain(): void {
    $assert_session = $this->assertSession();
    $block = Block::create([
      'id' => self::BLOCK_ID,
      'theme' => 'stark',
      'region' => 'content',
      'plugin' => 'data_surface_demo',
      'settings' => [
        'id' => 'data_surface_demo',
        'label' => 'Featured',
        'provider' => 'data_surface_demo',
        'headline' => 'Featured',
        'entity_type' => 'node',
        'bundle' => 'article',
        'limit' => 5,
      ],
    ]);
    $block->save();
    $this->assertSame('article', $this->storedSettings()['bundle']);

    // The bundle goes out from under the block.
    NodeType::load('article')->delete();

    // Opening the form is the moment this used to die, before any of it
    // was about forms at all: the plugin could not be constructed.
    $this->drupalGet($block->toUrl('edit-form'));
    $assert_session->statusCodeEquals(200);
    $assert_session->pageTextNotContains('Invalid configuration');

    // The select comes up on a placeholder that names what is gone, and
    // it is a placeholder rather than an option: the deleted bundle is
    // not offered back, and no other bundle has been quietly chosen.
    $assert_session->fieldValueEquals('settings[bundle]', DataSurfacePipelineInterface::KEEP_STALE);
    $assert_session->optionNotExists('settings[bundle]', 'article');
    $assert_session->optionExists('settings[bundle]', 'page');
    $this->assertStringContainsString(
      'article',
      $assert_session->optionExists('settings[bundle]', DataSurfacePipelineInterface::KEEP_STALE)->getText(),
    );
    // Nothing is flagged as an error, because nothing is wrong.
    $assert_session->elementNotExists('css', 'select[name="settings[bundle]"].error');

    // Somebody edits the headline and never touches the bundle. The
    // browser submits the select it was handed, which is the placeholder
    // — the trap, since an unrecognized or empty select otherwise means
    // "clear this".
    $this->submitForm(['settings[headline]' => 'Changed'], 'Save block');
    $assert_session->pageTextContains('The block configuration has been saved.');
    // Said out loud, so the value does not stay broken silently.
    $assert_session->pageTextContains('no longer available');

    $settings = $this->storedSettings();
    $this->assertSame('Changed', $settings['headline']);
    $this->assertSame('article', $settings['bundle']);

    // And choosing a real bundle replaces it, which is the way out.
    $this->drupalGet($block->toUrl('edit-form'));
    $this->submitForm(['settings[bundle]' => 'page'], 'Save block');
    $assert_session->pageTextContains('The block configuration has been saved.');
    $this->assertSame('page', $this->storedSettings()['bundle']);
    // Nothing is stale any more, so nothing nags.
    $this->drupalGet($block->toUrl('edit-form'));
    $assert_session->pageTextNotContains('no longer available');
    $assert_session->fieldValueEquals('settings[bundle]', 'page');
  }

}
