<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Functional;

use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Violation;
use Drupal\Tests\BrowserTestBase;
use Drupal\Tests\surface\Traits\ServedApiTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a placed block over HTTP: placed, read, changed and removed.
 *
 * POST to the collection places one, 201 with its Location; GET at that
 * Location reads it, with its ETag; PATCH there changes it; DELETE there
 * removes it, the method for the resource's default delete, its dry run
 * removing nothing; a refusal is problem details, a region of another
 * theme by its pointer, and DELETE of what is gone a 404.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class BlockPlacementApiTest extends BrowserTestBase {

  use ServedApiTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'block',
    'path_alias',
    'surface_examples',
    'surface_examples_component_block',
    'surface_examples_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * The collection.
   */
  protected const COLLECTION = 'surface-api/block.placement';

  /**
   * A hero in Stark's left sidebar.
   */
  protected const HERO = [
    'label' => 'Doors',
    'id' => 'doors_hero',
    'plugin' => 'surface_component',
    'settings' => [
      'component' => 'surface_examples:hero',
      'props' => ['title' => 'Doors open at half past six', 'align' => 'start'],
    ],
    'theme' => 'stark',
    'region' => 'sidebar_first',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A second theme, whose regions are not Stark's.
    $this->container->get('theme_installer')->install(['claro']);
  }

  /**
   * Loads a block afresh.
   *
   * @param string $id
   *   Its machine name.
   *
   * @return \Drupal\block\BlockInterface|null
   *   It, or NULL.
   */
  protected function block(string $id) {
    return $this->container->get('entity_type.manager')->getStorage('block')->loadUnchanged($id);
  }

  /**
   * Tests placing, reading, changing and removing one block.
   */
  public function testPlaceReadChangeRemove(): void {
    $this->problem($this->send('POST', self::COLLECTION, self::HERO, [], FALSE), 403);
    $this->drupalLogin($this->drupalCreateUser(['administer blocks']));

    // Placed, rehearsed first.
    $rehearsed = $this->decoded($this->send('POST', self::COLLECTION . '?dryRun=true', self::HERO));
    $this->assertTrue($rehearsed['x-surface-dry-run']);
    $this->assertNull($this->block('doors_hero'));
    $response = $this->send('POST', self::COLLECTION, self::HERO);
    $placed = $this->decoded($response, 201);
    $this->assertSame([...ResultDocument::KEYS, 'outputs', 'contract', 'created'], array_keys($placed));
    $location = $response->getHeaderLine('Location');
    $this->assertStringEndsWith('/surface-api/block.placement/doors_hero', $location);
    $this->assertSame(['resource' => 'block.placement', 'operation' => 'update', 'parameters' => ['id' => 'doors_hero']], $placed['created']);
    $this->assertSame('sidebar_first', $this->block('doors_hero')->getRegion());

    // Read where it lives.
    $read = $this->send('GET', self::COLLECTION . '/doors_hero');
    $envelope = $this->decoded($read);
    $this->assertSame('update', $envelope['operation']);
    $this->assertSame('surface_examples:hero', $envelope['values']['settings']['component']);
    $tag = $read->getHeaderLine('ETag');
    $this->assertNotSame('', $tag);

    // Changed, held to the ETag it was read with.
    $moved = ['weight' => 3, 'region' => 'content'];
    $changed = $this->decoded($this->send('PATCH', self::COLLECTION . '/doors_hero', $moved, ['If-Match' => $tag]));
    $this->assertSame(['/region', '/weight'], $changed['changes']);
    $this->assertSame('content', $this->block('doors_hero')->getRegion());
    $this->problem($this->send('PATCH', self::COLLECTION . '/doors_hero', ['weight' => 4], ['If-Match' => $tag]), 412);

    // A region of another theme, refused by its pointer.
    $refused = $this->problem($this->send('PATCH', self::COLLECTION . '/doors_hero', ['region' => 'pre_content']), 422);
    $this->assertSame('/region', $refused['errors'][0]['path']);
    $this->assertStringStartsWith('The stark theme has no region named pre_content.', $refused['errors'][0]['message']);

    // Removed with DELETE: rehearsed, then done.
    $rehearsed = $this->decoded($this->send('DELETE', self::COLLECTION . '/doors_hero?dryRun=true'));
    $this->assertTrue($rehearsed['x-surface-dry-run']);
    $this->assertFalse($rehearsed['committed']);
    $this->assertSame([''], $rehearsed['changes']);
    $this->assertNotNull($this->block('doors_hero'));
    $sent = $this->problem($this->send('DELETE', self::COLLECTION . '/doors_hero', ['label' => 'Gone']), 422);
    $this->assertSame(['/label'], array_column($sent['errors'], 'path'), 'A delete takes no values.');
    $this->assertSame([Violation::UNKNOWN_KEY], array_column($sent['errors'], 'code'));
    $removed = $this->send('DELETE', self::COLLECTION . '/doors_hero');
    $answer = $this->decoded($removed);
    $this->assertTrue($answer['committed']);
    $this->assertTrue($answer['changed']);
    $this->assertSame('Doors', $answer['values']['label'], 'The values it held are answered.');
    $this->assertNull($answer['contract'], 'Gone: no envelope to rebuild.');
    $this->assertSame('', $removed->getHeaderLine('ETag'));
    $this->assertNull($this->block('doors_hero'));
    $this->problem($this->send('DELETE', self::COLLECTION . '/doors_hero'), 404);

    // DELETE is a thing's, never the collection's; and it carries the
    // session's token.
    $this->problem($this->send('DELETE', self::COLLECTION), 405);
    $this->send('POST', self::COLLECTION, self::HERO);
    $this->assertSame('X-CSRF-Token request header is missing', $this->problem($this->send('DELETE', self::COLLECTION . '/doors_hero', NULL, [], FALSE), 403)['detail']);
    $this->assertNotNull($this->block('doors_hero'));
  }

}
