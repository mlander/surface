<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface_resource_test\Resource\Poster\PosterResource;
use Drupal\surface_resource_test\Storage\PosterStorage;
use Drupal\surface_resource_test\Variants\PosterLayouts;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tool door leaves out the variants the site lists, under the limit.
 *
 * The poster's provider lists eight layouts, fewer than the site list
 * limit, five of them by value and label alone, each schema answered when
 * asked. Both documents state every layout. A tool's listing states the
 * layout the stored values choose and no other, names the two ways to
 * learn another's keys, and reads no layout's schema but the stored one's.
 *
 * Decision: see docs/decisions.md#a-budget-for-what-the-site-decides.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class SiteListToolDoorTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'surface',
    'surface_resource_test',
  ];

  /**
   * The poster's write tool.
   */
  protected const WRITE = 'surface:resource_test.poster:configure';

  /**
   * The note on a key of layouts nothing stored chooses.
   */
  protected const NONE_STORED = "Its keys depend on layout and are not listed here: choose layout, then fetch GET /surface-api/resource_test.poster/schema?choose=/panels={layout} once per layout, or call surface:resource_test.poster:configure:schema with your values, to get this key's schema.";

  /**
   * The note on a key of layouts the stored shelf_2 chooses.
   */
  protected const SHELF_STORED = "Its keys depend on layout: listed here are those of shelf_2, the layout stored. For another layout, fetch GET /surface-api/resource_test.poster/schema?choose=/panels={layout} once per layout, or call surface:resource_test.poster:configure:schema with your values, to get this key's schema.";

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface', 'surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
    // Five shelves beside the three layouts always listed, each read when
    // asked: eight, under the limit of twenty.
    $shelves = [];
    for ($i = 1; $i <= 5; $i++) {
      $shelves['shelf_' . $i] = [
        'label' => 'Shelf ' . $i,
        'schema' => [
          'type' => 'object',
          'properties' => [
            'heading' => ['type' => 'string', 'title' => 'Heading of shelf ' . $i, 'maxLength' => 80],
            'rows' => ['type' => 'integer', 'title' => 'Rows', 'minimum' => 1, 'maximum' => 12, 'default' => 2],
          ],
          'additionalProperties' => FALSE,
        ],
      ];
    }
    $this->container->get('state')->set(PosterLayouts::STATE, $shelves);
    $this->container->get('state')->set(PosterLayouts::LAZY, TRUE);
    Cache::invalidateTags([PosterLayouts::TAG]);
    $this->config(PosterStorage::CONFIG)->set('layout', 'shelf_2')->set('panels', ['heading' => 'New in', 'rows' => 3])->save();
    $this->resources()->forgetBuilds();
    PosterLayouts::$read = [];
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\Resources
   *   The service.
   */
  protected function resources(): Resources {
    $resources = $this->container->get('surface.resources');
    assert($resources instanceof Resources);
    return $resources;
  }

  /**
   * Builds the poster for the current account.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The build.
   */
  protected function poster(): SurfaceInterface {
    return $this->resources()->buildOperation(PosterResource::class, 'configure');
  }

  /**
   * Gets the tool plugin manager, its definitions read again.
   *
   * @return \Drupal\Component\Plugin\PluginManagerInterface
   *   The manager.
   */
  protected function tools(): object {
    $manager = $this->container->get('plugin.manager.tool');
    $manager->clearCachedDefinitions();
    return $manager;
  }

  /**
   * Lists the layouts whose schema was read, the shared defs aside.
   *
   * Building one variant asks its schema more than once, for its ports and
   * for its keys; what counts here is which.
   *
   * @return string[]
   *   The values, each once, in the order first read.
   */
  protected static function read(): array {
    return array_values(array_unique(array_diff(PosterLayouts::$read, ['$defs'])));
  }

  /**
   * Tests both documents state every layout, and the tool none of them.
   */
  public function testUnderTheLimitOnlyTheDocumentsStateEveryLayout(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $full = $emitter->emitFull($this->poster(), 'resource_test.poster', 'configure', 'Configure the poster')->document['schema'];
    $this->assertCount(8, (array) $full['properties']['panels']['x-surface']['variants']);
    $this->assertArrayNotHasKey('deferred', $full['properties']['panels']['x-surface']);
    $this->assertArrayHasKey('resource_test.poster.panels.shelf_4', $full['$defs']);
    $these = $emitter->emit($this->poster(), ['layout' => 'shelf_4'], 'resource_test.poster', 'configure', 'Configure the poster')->document['schema'];
    $this->assertSame('shelf_4', $these['properties']['panels']['x-surface']['chosen']);
    $this->assertSame(['heading', 'rows'], array_keys($these['$defs']['resource_test.poster.panels.shelf_4']['properties']));

    // The cached definition reads nothing stored: the panels hold no key,
    // and the layout offers all eight.
    PosterLayouts::$read = [];
    $manager = $this->tools();
    $values = $manager->getDefinition(self::WRITE)->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $panels = $values->getPropertyDefinition('panels');
    $this->assertInstanceOf(MapInputDefinition::class, $panels);
    $this->assertSame([], $panels->getPropertyDefinitions());
    $this->assertStringEndsWith(self::NONE_STORED, (string) $panels->getDescription());
    $this->assertCount(8, $values->getPropertyDefinition('layout')->getConstraint('Choice')['choices'] ?? []);
    // The read tool says the same, and a narrowing tool is derived.
    $read = $manager->getDefinition(self::WRITE . ':read')->getOutputDefinitions()[OperationInputs::VALUES];
    $this->assertSame([], $read->getPropertyDefinition('panels')->getPropertyDefinitions());
    $this->assertStringEndsWith(self::NONE_STORED, (string) $read->getPropertyDefinition('panels')->getDescription());
    $this->assertTrue($manager->hasDefinition(self::WRITE . ':schema'));
    $this->assertSame([], static::read(), 'Deriving the tools reads no layout.');

    // The fetch the note names answers, under the limit, the full schema,
    // which states every layout already.
    $fetched = $this->servedJson('GET', '/surface-api/resource_test.poster/schema?choose=/panels=shelf_4');
    $this->assertArrayHasKey('resource_test.poster.panels.shelf_4', $fetched['$defs']);
  }

  /**
   * Tests the listing states the stored layout's keys, and reads it alone.
   */
  public function testTheStoredLayoutIsListedAndAloneRead(): void {
    $manager = $this->tools();
    $manager->getDefinitions();
    $this->assertSame([], static::read());

    // The listing for the account: the stored shelf's keys, as variants
    // nothing chose state each, and the way to any other layout.
    $tool = $manager->createInstance(self::WRITE);
    $panels = $tool->getInputDefinitions()[OperationInputs::VALUES]->getPropertyDefinition('panels');
    $this->assertInstanceOf(MapInputDefinition::class, $panels);
    $this->assertSame(['heading', 'rows'], array_keys($panels->getPropertyDefinitions()));
    $this->assertFalse($panels->getPropertyDefinition('heading')->isRequired());
    $this->assertSame('Only when layout is shelf_2.', (string) $panels->getPropertyDefinition('heading')->getDescription());
    $this->assertStringEndsWith(self::SHELF_STORED, (string) $panels->getDescription());
    $this->assertSame(['shelf_2'], static::read(), 'The listing reads the stored layout alone.');

    // The refined values say the same, defaulted to what is stored.
    $refined = $tool->refineInputDefinition(OperationInputs::VALUES, $manager->getDefinition(self::WRITE)->getInputDefinition(OperationInputs::VALUES), []);
    $this->assertInstanceOf(MapInputDefinition::class, $refined);
    $panels = $refined->getPropertyDefinition('panels');
    $this->assertInstanceOf(MapInputDefinition::class, $panels);
    $this->assertSame(['heading', 'rows'], array_keys($panels->getPropertyDefinitions()));
    $heading = $panels->getPropertyDefinition('heading');
    $this->assertInstanceOf(InputDefinitionInterface::class, $heading);
    $this->assertSame('New in', $heading->getDefaultValue());
    $this->assertSame(['shelf_2'], static::read());

    // So changing a key of the stored layout is one call.
    $tool->setInputValue(OperationInputs::VALUES, ['panels' => ['heading' => 'Sale', 'rows' => 3]]);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());

    // The narrowing tool answers another layout, which it reads then.
    $schema = $manager->createInstance(self::WRITE . ':schema');
    $schema->setInputValue(OperationInputs::VALUES, ['layout' => 'shelf_4']);
    $schema->execute();
    $answer = $schema->getResult()->getContextValues()[OperationInputs::SCHEMA];
    $this->assertSame(['heading', 'rows'], array_keys($answer['$defs']['resource_test.poster.panels.shelf_4']['properties']));
    $this->assertContains('shelf_4', static::read());

    // An account the operation is not open to is listed nothing stored.
    $this->setUpCurrentUser();
    $panels = $manager->createInstance(self::WRITE)->getInputDefinitions()[OperationInputs::VALUES]->getPropertyDefinition('panels');
    $this->assertSame([], $panels->getPropertyDefinitions());
    $this->assertStringEndsWith(self::NONE_STORED, (string) $panels->getDescription());
  }

  /**
   * Tests a stored value naming no layout chooses nothing.
   */
  public function testStoredValueNamingNoLayoutListsNone(): void {
    $this->config(PosterStorage::CONFIG)->set('layout', 'gone')->save();
    $this->resources()->forgetBuilds();
    $panels = $this->tools()->createInstance(self::WRITE)->getInputDefinitions()[OperationInputs::VALUES]->getPropertyDefinition('panels');
    $this->assertSame([], $panels->getPropertyDefinitions());
    $this->assertStringEndsWith(self::NONE_STORED, (string) $panels->getDescription());
    $this->assertSame([], static::read());
  }

}
