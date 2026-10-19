<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Resource\KeyedList;
use Drupal\data_surface\ResourceBuild\ResourceBlueprint;
use Drupal\data_surface_resource_test\Keys\WorkshopBenches;
use Drupal\data_surface_resource_test\Resource\Workshop\WorkshopResource;
use Drupal\data_surface_resource_test\Shape\Sku;
use Drupal\data_surface_resource_test\Structure\Broken\AccountKeysStructure;
use Drupal\data_surface_resource_test\Structure\Broken\DefaultedEntryStructure;
use Drupal\data_surface_resource_test\Structure\Broken\UncacheableKeysStructure;
use Drupal\data_surface_resource_test\Structure\Broken\UnwiredEntryStructure;
use Drupal\data_surface_resource_test\Structure\Workshop\BenchStructure;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\Tests\data_surface\Traits\ContractValidationTrait;
use Drupal\Tests\data_surface\Traits\StaticRefinerAgreementTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\InputDefinitionInterface;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Keys that come from the site: one key per thing a provider lists.
 *
 * The workshop asks for a bench, a light and a stock code per bench the
 * site's configuration lists; one bench is locked there, and the bench
 * structure's declared rule applies at every bench. The provider's
 * cacheability reaches the built resource, both documents and the tool
 * listing, so a bench added to the configuration is a key at once.
 *
 * @see docs/resources.md#keys-that-come-from-the-site
 * @see docs/decisions.md#keys-that-come-from-the-site
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class KeysFromSiteTest extends DataSurfaceKernelTestBase {

  use ContractValidationTrait;
  use StaticRefinerAgreementTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_resource_test',
  ];

  /**
   * The workshop as installed.
   */
  protected const WORKSHOP = [
    'closed' => FALSE,
    'benches' => [
      'lathe' => ['enabled' => TRUE, 'hours' => 4],
      'press' => ['enabled' => TRUE, 'hours' => 2],
      'saw' => ['enabled' => FALSE],
    ],
    'lights' => ['lathe' => TRUE, 'press' => FALSE, 'saw' => FALSE],
    'codes' => ['lathe' => 'LA1', 'press' => 'DP2'],
  ];

  /**
   * The cache tag of the configuration the benches are listed in.
   */
  protected const TAG = 'config:' . WorkshopBenches::CONFIG;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Builds the workshop in its one operation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built workshop.
   */
  protected function workshop(): DataSurfaceInterface {
    return $this->container->get('data_surface.resources')->buildOperation(WorkshopResource::class, 'configure');
  }

  /**
   * Reads what the workshop's storage holds.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The built workshop.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(DataSurfaceInterface $built): array {
    $resources = $this->container->get('data_surface.resources');
    return $resources->storage(WorkshopResource::class, $resources->operation(WorkshopResource::class, 'configure'), $built)->load($built);
  }

  /**
   * Adds a bench to the site's list.
   *
   * @param string $id
   *   The bench.
   * @param string $label
   *   Its label.
   */
  protected function addBench(string $id, string $label): void {
    $config = $this->config(WorkshopBenches::CONFIG);
    $config->set('benches.' . $id, ['label' => $label])->save();
  }

  /**
   * Tests one key per bench, each as its entry says, at all three maps.
   */
  public function testOneKeyPerThing(): void {
    $workshop = $this->workshop();
    $map = $workshop->getDefinitions();
    $this->assertSame(['closed', 'benches', 'lights', 'codes'], array_keys($map->toArray()));

    // The benches: the bench structure at every entry, built once.
    $benches = $map->entry('benches')->attachment;
    $this->assertNotNull($benches);
    $this->assertNull($benches->source, 'The map is its owner\'s, of no class.');
    $entries = $benches->child->getDefinitions();
    $this->assertSame(['lathe', 'press', 'saw'], array_keys($entries->toArray()));
    $this->assertSame(['Lathe', 'Drill press', 'Band saw'], array_map(static fn ($definition): string => (string) $definition->getLabel(), array_values($entries->toArray())));
    foreach (['lathe', 'press', 'saw'] as $id) {
      $this->assertSame(BenchStructure::class, $entries->entry($id)->attachment->source);
      $this->assertSame('resource_test.workshop.bench', $entries->entry($id)->attachment->id);
    }
    $this->assertSame($entries->entry('lathe')->attachment->child, $entries->entry('saw')->attachment->child);

    // The lights: a boolean per bench, the blueprint's default for each.
    $lights = $map->entry('lights')->attachment->child;
    $this->assertSame(['lathe' => FALSE, 'press' => FALSE, 'saw' => FALSE], $lights->getDefaultValues());
    $this->assertSame('boolean', $lights->getDefinitions()->toArray()['saw']->getDataType());

    // The codes: the shape at every entry, as its alter left it.
    $codes = $map->entry('codes')->attachment->child->getDefinitions()->toArray();
    $this->assertSame('resource_test.sku', DefinitionMetadata::shapeOf($codes['lathe']));
    $this->assertSame(['max' => 12], $codes['lathe']->getConstraints()['Length']);
    $this->assertArrayHasKey('Regex', $codes['lathe']->getConstraints());
    $this->assertSame('Lathe', (string) $codes['lathe']->getLabel());
  }

  /**
   * Tests an entry the provider locks is held to what is stored.
   *
   * Per-option state for a map of keys from the site: the locked bench is
   * a locked child, its light and its code held keys, at every door.
   */
  public function testLockedEntryIsHeld(): void {
    $workshop = $this->workshop();
    $benches = $workshop->getDefinitions()->entry('benches')->attachment->child->getDefinitions();
    $this->assertTrue($benches->isLocked('press'));
    $this->assertFalse($benches->isLocked('lathe'));
    $lights = $workshop->getDefinitions()->entry('lights')->attachment->child->getDefinitions();
    $this->assertTrue($lights->isLocked('press'));
    $this->assertSame(['press'], array_values(array_filter(['lathe', 'press', 'saw'], $lights->isLocked(...))));

    // A change sent for the locked bench is not taken; storage keeps it.
    $stored = $this->stored($workshop);
    $accepted = $this->pipeline()->accept($workshop, [
      'benches' => ['press' => ['enabled' => FALSE, 'hours' => 9]] + self::WORKSHOP['benches'],
      'lights' => ['press' => TRUE] + self::WORKSHOP['lights'],
    ], $stored);
    $this->assertSame(['enabled' => TRUE, 'hours' => 2], $accepted['benches']['press']);
    $this->assertFalse($accepted['lights']['press']);
    $this->assertTrue($this->pipeline()->validate($workshop, $accepted, $stored)->isEmpty());

    // Not refused, and the caller is told, once per key: the result's
    // notes, and the tool's message after its own.
    $resources = $this->container->get('data_surface.resources');
    $storage = $resources->storage(WorkshopResource::class, $resources->operation(WorkshopResource::class, 'configure'), $workshop);
    $notes = static fn ($result): array => array_combine(
      array_map(static fn ($note): string => $note->fullPath(), $result->notes),
      array_map(static fn ($note): string => (string) $note->message, $result->notes),
    );
    $result = $this->pipeline()->submit($workshop, [
      'benches' => ['press' => ['enabled' => FALSE, 'hours' => 9]],
      'lights' => ['press' => TRUE, 'lathe' => FALSE],
    ], $storage, dry_run: TRUE);
    $this->assertTrue($result->isValid());
    $this->assertSame([
      'benches.press' => 'Drill press is locked and kept as stored; the value sent was not taken.',
      'lights.press' => 'Drill press is locked and kept as stored; the value sent was not taken.',
    ], $notes($result));
    // What it holds, sent back, a map with fewer keys or a form's "0"
    // among them, was taken: nothing to say.
    $result = $this->pipeline()->submit($workshop, [
      'benches' => ['press' => ['hours' => 2]],
      'lights' => ['press' => '0'],
    ], $storage, dry_run: TRUE);
    $this->assertSame([], $result->notes);

    $tool = $this->container->get('plugin.manager.tool')->createInstance('data_surface:resource_test.workshop:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['lights' => ['press' => TRUE]]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame('Configure the workshop: the values were accepted and written. lights.press: Drill press is locked and kept as stored; the value sent was not taken.', PlainTextOutput::renderFromHtml((string) $tool->getResult()->getMessage()));
    $this->assertSame(['lights.press' => 'Drill press is locked and kept as stored; the value sent was not taken.'], $tool->getResult()->getContextValues()['notes'] ?? NULL);
    $this->assertFalse($this->config('data_surface_resource_test.workshop')->get('lights.press'));
  }

  /**
   * Tests the structure's declared rule applies at every entry, in its frame.
   */
  public function testTheStructuresRuleAppliesAtEveryEntry(): void {
    $workshop = $this->workshop();
    $stored = $this->stored($workshop);
    $input = ['benches' => ['saw' => ['enabled' => FALSE, 'hours' => 3]] + self::WORKSHOP['benches']];
    $violations = $this->pipeline()->validate($workshop, $this->pipeline()->accept($workshop, $input, $stored), $stored);
    $this->assertSame(['benches.saw.hours'], array_values(array_unique(array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($violations)))));

    $input = ['benches' => ['lathe' => ['enabled' => TRUE, 'hours' => 13]] + self::WORKSHOP['benches']];
    $violations = $this->pipeline()->validate($workshop, $this->pipeline()->accept($workshop, $input, $stored), $stored);
    $this->assertSame(['benches.lathe.hours'], array_values(array_unique(array_map(static fn ($violation): string => $violation->fullPath(), iterator_to_array($violations)))));

    // The owner's rule rules out the whole map, as for an attached child:
    // what it holds says nothing, a change is refused by name.
    $accepted = $this->pipeline()->accept($workshop, ['closed' => TRUE], $stored);
    $this->assertArrayNotHasKey('lights', $accepted);
    $this->assertTrue($this->pipeline()->validate($workshop, $accepted, $stored)->isEmpty());
    $input = ['closed' => TRUE, 'lights' => ['lathe' => FALSE]];
    $violations = $this->pipeline()->validate($workshop, $this->pipeline()->accept($workshop, $input, $stored), $stored);
    $messages = array_map(static fn ($violation): string => $violation->fullPath() . ': ' . $violation->message, iterator_to_array($violations, FALSE));
    $this->assertSame(['lights: Lights does not apply when Closed is on.'], $messages);
  }

  /**
   * Tests the provider's cacheability reaches the build and both documents.
   *
   * And a bench added to the configuration is a key at the next build,
   * which a cached form, a cached document and the tool listing reach by
   * the configuration's cache tag.
   */
  public function testTheProvidersCacheabilityReachesEveryDocument(): void {
    $workshop = $this->workshop();
    $this->assertContains(self::TAG, $workshop->getCacheTags());
    $emitter = $this->container->get('data_surface.contract_emitter');
    $this->assertContains(self::TAG, $emitter->emitFull($workshop, 'resource_test.workshop')->getCacheTags());
    $this->assertContains(self::TAG, $emitter->emit($workshop, $this->stored($workshop), 'resource_test.workshop')->getCacheTags());

    $this->addBench('bench', 'Workbench');
    $again = $this->workshop();
    $this->assertSame(['lathe', 'press', 'saw', 'bench'], array_keys($again->getDefinitions()->entry('lights')->attachment->child->getDefinitions()->toArray()));
    $full = $emitter->emitFull($again, 'resource_test.workshop')->document['schema'];
    $this->assertSame(['lathe', 'press', 'saw', 'bench'], array_keys($full['$defs']['resource_test.workshop.benches']['properties']));
  }

  /**
   * Tests both documents, exactly.
   */
  public function testBothDocuments(): void {
    $workshop = $this->workshop();
    $emitter = $this->container->get('data_surface.contract_emitter');
    $full = $this->decoded($emitter->emitFull($workshop, 'resource_test.workshop', 'configure', 'Configure the workshop')->document);
    $this->assertSame($this->expected('workshop.full.json'), $full);
    $these = $this->decoded($emitter->emit($workshop, $this->stored($workshop), 'resource_test.workshop', 'configure', 'Configure the workshop')->document);
    $this->assertSame($this->expected('workshop.these.json'), $these);
    // Each accepts what is stored.
    $this->assertTrue($this->validates($full['schema'], self::WORKSHOP));
    $this->assertTrue($this->validates($these['schema'], $these['values']));
  }

  /**
   * Tests every conditional is what a fresh refinement answers.
   */
  public function testTheStaticRefinersAgree(): void {
    $workshop = $this->workshop();
    $this->assertGreaterThan(0, $this->assertStaticRefinersAgree($workshop, NULL, 'resource_test.workshop'));
    $this->assertGreaterThan(0, $this->assertStaticRefinersAgree($workshop, self::WORKSHOP, 'resource_test.workshop'));
  }

  /**
   * Tests the tool listing, its invalidation, and the read and write tools.
   */
  public function testTools(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('data_surface:resource_test.workshop:configure')->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(['closed', 'benches', 'lights', 'codes'], array_keys($properties));
    $benches = $properties['benches'];
    $this->assertInstanceOf(MapInputDefinition::class, $benches);
    $this->assertSame(['lathe', 'press', 'saw'], array_keys($benches->getPropertyDefinitions()));
    $locked = static fn (InputDefinitionInterface $input): bool => $input->isLocked();
    $this->assertSame(['press' => TRUE], array_filter(array_map($locked, $benches->getPropertyDefinitions())));
    $saw = $benches->getPropertyDefinitions()['saw'];
    $this->assertInstanceOf(MapInputDefinition::class, $saw);
    $this->assertSame(['enabled', 'hours'], array_keys($saw->getPropertyDefinitions()));

    // A bench added is in the listing at once: the listing is cleared by
    // the tag of the configuration it was read from.
    $this->addBench('bench', 'Workbench');
    $properties = $manager->getDefinition('data_surface:resource_test.workshop:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions();
    $this->assertSame(['lathe', 'press', 'saw', 'bench'], array_keys($properties['lights']->getPropertyDefinitions()));
    $this->assertContains(self::TAG, $this->container->get('state')->get('data_surface_tool.listed_cache_tags'));

    $read = $manager->createInstance('data_surface:resource_test.workshop:configure:read');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess(), (string) $read->getResult()->getMessage());
    $this->assertSame(self::WORKSHOP, $read->getResult()->getContextValues()[OperationInputs::VALUES] ?? NULL);

    $tool = $manager->createInstance('data_surface:resource_test.workshop:configure');
    $tool->setInputValue(OperationInputs::VALUES, [
      'benches' => ['saw' => ['enabled' => FALSE, 'hours' => 3]] + self::WORKSHOP['benches'],
    ]);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertStringContainsString('benches.saw.hours', strip_tags((string) $tool->getResult()->getMessage()));

    $tool = $manager->createInstance('data_surface:resource_test.workshop:configure');
    $tool->setInputValue(OperationInputs::VALUES, [
      'lights' => ['lathe' => FALSE, 'bench' => TRUE],
      'codes' => ['saw' => 'BS3'],
    ]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $stored = $this->config('data_surface_resource_test.workshop');
    $this->assertSame(['lathe' => FALSE, 'press' => FALSE, 'saw' => FALSE, 'bench' => TRUE], $stored->get('lights'));
    $this->assertSame('BS3', $stored->get('codes.saw'));
  }

  /**
   * Tests a provider whose list cannot be cached is refused, naming it.
   */
  public function testUncacheableProviderIsRefused(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Drupal\data_surface_resource_test\Keys\UncacheableKeys lists the keys of "moments" on resource_test.broken.uncacheable_keys with max-age 0. Keys from the site must be cacheable');
    $this->container->get('data_surface.resources')->build(UncacheableKeysStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests a provider whose list varies by the account is refused.
   */
  public function testProviderVaryingByTheAccountIsRefused(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('varying by user.permissions. Which keys there are is the site\'s, the same for every account');
    $this->container->get('data_surface.resources')->build(AccountKeysStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests an entry of a structure starts from the structure's defaults.
   */
  public function testEntryOfStructureSaysNoDefault(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('says what the "first" entry of "benches" on resource_test.broken.defaulted_entry starts from, and each entry there is resource_test.workshop.bench, which starts from its own defaults.');
    $this->container->get('data_surface.resources')->build(DefaultedEntryStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests a structure that requires a port is no entry: nothing wires one.
   */
  public function testEntryRequiresNoPort(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('resource_test.seating fills the "seats" map of keys from the site of resource_test.broken.unwired_entry and requires the "layout" port, which nothing wires: a port is wired by attach(), and a map\'s entry is wired by nothing yet.');
    $this->container->get('data_surface.resources')->build(UnwiredEntryStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests what the blueprint refuses as it is declared.
   */
  public function testTheBlueprintRefuses(): void {
    $blueprint = fn (): ResourceBlueprint => new ResourceBlueprint(new DataSurfaceBuilder(), $this->container->get('typed_data_manager'));
    $refusals = [
      'a structure where a value belongs' => [
        static fn (ResourceBlueprint $inputs) => $inputs->addEach('rows', BenchStructure::class, from: WorkshopBenches::class),
        'addEach("rows") was handed Drupal\data_surface_resource_test\Structure\Workshop\BenchStructure, a resource or structure: an entry that is a group of keys is a child, attachEach(\'rows\', ...).',
      ],
      'a class that is no shape' => [
        static fn (ResourceBlueprint $inputs) => $inputs->addEach('rows', WorkshopBenches::class, from: WorkshopBenches::class),
        'addEach("rows") was handed Drupal\data_surface_resource_test\Keys\WorkshopBenches, which is no typed data type and no shape',
      ],
      'a shape where a child belongs' => [
        static fn (ResourceBlueprint $inputs) => $inputs->attachEach('rows', Sku::class, from: WorkshopBenches::class),
        'attachEach("rows") was handed Drupal\data_surface_resource_test\Shape\Sku, a shape',
      ],
      'a provider that is none' => [
        static fn (ResourceBlueprint $inputs) => $inputs->addEach('rows', 'boolean', from: BenchStructure::class),
        'addEach("rows") names Drupal\data_surface_resource_test\Structure\Workshop\BenchStructure as where its keys come from, which does not implement Drupal\data_surface\Resource\KeysFromSiteInterface',
      ],
      'a key taken' => [
        static function (ResourceBlueprint $inputs) {
          $inputs->add('rows', 'string', 'Rows');
          $inputs->addEach('rows', 'boolean', from: WorkshopBenches::class);
        },
        'The input "rows" was already added to this blueprint.',
      ],
    ];
    foreach ($refusals as $label => [$declare, $message]) {
      try {
        $declare($blueprint());
        $this->fail($label . ': refused.');
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage(), $label);
      }
    }
    $outputs = new ResourceBlueprint(new DataSurfaceBuilder(), $this->container->get('typed_data_manager'), TRUE);
    try {
      $outputs->addEach('rows', 'boolean', from: WorkshopBenches::class);
      $this->fail('Outputs hold no keys from the site.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('addEach() cannot place a child at the output "rows"', $e->getMessage());
    }
  }

  /**
   * Tests a list's ids become keys, so a dot, a colon or a repeat is refused.
   */
  public function testKeyedListRefusesWhatCannotBeKey(): void {
    foreach (['a.b', 'a:b', ''] as $id) {
      try {
        (new KeyedList())->add($id, 'Label');
        $this->fail(sprintf('"%s" is refused.', $id));
      }
      catch (\InvalidArgumentException $e) {
        $this->assertStringContainsString('cannot be the id of an entry', $e->getMessage());
      }
    }
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('The "a" entry was added to this list already');
    KeyedList::fromLabels(['a' => 'A'])->add('a', 'Again');
  }

  /**
   * Reads an expected document.
   *
   * @param string $file
   *   The file under tests/fixtures/keys-from-site.
   *
   * @return array
   *   The document, decoded.
   */
  protected function expected(string $file): array {
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/keys-from-site/' . $file), TRUE, 512, JSON_THROW_ON_ERROR);
  }

  /**
   * Decodes a document as a client does.
   *
   * @param array $document
   *   The document.
   *
   * @return array
   *   The same, through JSON.
   */
  protected function decoded(array $document): array {
    return json_decode((string) json_encode($document), TRUE);
  }

}
