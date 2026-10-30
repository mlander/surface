<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Cache\Cache;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Resource\SiteList;
use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface_resource_test\Resource\Poster\PosterResource;
use Drupal\surface_resource_test\Structure\Broken\AccountVariantsStructure;
use Drupal\surface_resource_test\Structure\Broken\UncacheableVariantsStructure;
use Drupal\surface_resource_test\Structure\Poster\BannerPanelsStructure;
use Drupal\surface_resource_test\Variants\Broken\BareLayouts;
use Drupal\surface_resource_test\Variants\Broken\LockedLayouts;
use Drupal\surface_resource_test\Variants\Broken\RestatingLayouts;
use Drupal\surface_resource_test\Variants\Broken\UnnamedLayouts;
use Drupal\surface_resource_test\Variants\PosterLayouts;
use Drupal\surface_tool\OperationInputs;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Variants from the site: one per layout, each read from the schema it carries.
 *
 * The poster's layouts are a provider's, each with the JSON Schema of the
 * panels it holds; the reader writes each into a variant at `panels`,
 * beside the banner a class declares, which wins. Both documents state
 * them as they state variants a class declares, the tool lists and reads
 * them, a layout added is a variant at the next request, and a submission
 * sending one layout's panels for another is refused naming the layout.
 *
 * @see docs/resources.md#variants-from-the-site
 * @see docs/decisions.md#a-schema-can-be-read-back-into-a-structure
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class VariantsFromSiteTest extends SurfaceKernelTestBase {

  use ContractValidationTrait;
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
    'surface_tool',
    'surface_resource_test',
  ];

  /**
   * The poster as installed.
   */
  protected const POSTER = [
    'layout' => 'grid',
    'panels' => ['columns' => 3, 'gap' => 'small'],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Builds the poster in its one operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built poster.
   */
  protected function poster(): SurfaceInterface {
    return $this->container->get('surface.resources')->buildOperation(PosterResource::class, 'configure');
  }

  /**
   * Reads a fixture document.
   *
   * @param string $file
   *   The file, under tests/fixtures/variants-from-site.
   *
   * @return array
   *   The decoded document.
   */
  protected function expected(string $file): array {
    return json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/variants-from-site/' . $file), TRUE, 512, JSON_THROW_ON_ERROR);
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

  /**
   * Tests one variant per layout, each read from its schema, the class winning.
   */
  public function testOneVariantPerLayout(): void {
    $poster = $this->poster();
    $entry = $poster->getDefinitions()->entry('panels');
    $this->assertNotNull($entry->variants);
    $this->assertSame(['banner', 'grid', 'strip'], array_keys($entry->variants->variants));
    $this->assertSame(PosterLayouts::class, DefinitionMetadata::variantsFrom($entry->variants->shell));

    // The deciding key offers each value under the provider's label, the
    // banner under its class's.
    $layout = $poster->getDefinition('layout');
    $this->assertSame(['banner' => 'Banner', 'grid' => 'Grid', 'strip' => 'Strip'], array_map('strval', $this->options()->resolve($layout)->options));

    // The banner is its class: the provider's schema for it is not read.
    $banner = $entry->variants->variants['banner'];
    $this->assertSame(BannerPanelsStructure::class, $banner->source);
    $this->assertSame(['headline'], $banner->child->getDefinitions()->names());

    // The grid is its schema, draft-04 read as 2020-12: an exclusive
    // minimum on an integer is the next one in.
    $grid = $entry->variants->variants['grid'];
    $this->assertSame('resource_test.poster.panels.grid', $grid->id);
    $this->assertSame(['columns', 'gap'], $grid->child->getDefinitions()->names());
    $columns = $grid->child->getDefinition('columns');
    $this->assertSame('integer', $columns->getDataType());
    $this->assertTrue($columns->isRequired());
    $this->assertSame(['min' => 1, 'max' => 4], $columns->getConstraint('Range'));
    $this->assertSame(2, $grid->child->getDefault('columns'));
    $gap = $grid->child->getDefinition('gap');
    $this->assertSame(['choices' => ['none', 'small', 'large']], $gap->getConstraint('Choice'));
    $this->assertTrue(DefinitionMetadata::isAlwaysHeld($gap), 'An optional key that refuses null and starts from a value is always held.');

    // The strip's caption says what no constraint does: kept, and
    // checked; its tags' uniqueItems is the stock UniqueItems.
    $strip = $entry->variants->variants['strip']->child;
    $this->assertSame(['schema' => ['not' => ['const' => 'Untitled']]], $strip->getDefinition('caption')->getConstraint('JsonSchema'));
    $this->assertSame([], $strip->getDefinition('tags')->getConstraint('UniqueItems'));
    $this->assertArrayNotHasKey('JsonSchema', $strip->getDefinition('tags')->getConstraints());
  }

  /**
   * Tests the provider's cacheability reaches the build, and a layout added.
   */
  public function testLayoutAddedBecomesVariant(): void {
    $poster = $this->poster();
    $this->assertContains(PosterLayouts::TAG, $poster->getCacheTags());
    $emitter = $this->container->get('surface.contract_emitter');
    $this->assertContains(PosterLayouts::TAG, $emitter->emitFull($poster, 'resource_test.poster')->getCacheTags());

    $this->container->get('state')->set(PosterLayouts::STATE, [
      'column' => [
        'label' => 'Column',
        'schema' => ['type' => 'object', 'properties' => ['width' => ['type' => 'integer', 'title' => 'Width']]],
      ],
    ]);
    Cache::invalidateTags([PosterLayouts::TAG]);
    $again = $this->poster();
    $this->assertSame(['banner', 'grid', 'strip', 'column'], array_keys($again->getDefinitions()->entry('panels')->variants->variants));
    $full = $emitter->emitFull($again, 'resource_test.poster')->document['schema'];
    $this->assertSame('#/$defs/resource_test.poster.panels.column', $full['properties']['panels']['x-surface']['variants']->column);
  }

  /**
   * Tests both documents, exactly, and that each accepts what is stored.
   */
  public function testBothDocuments(): void {
    $poster = $this->poster();
    $emitter = $this->container->get('surface.contract_emitter');
    $full = $this->decoded($emitter->emitFull($poster, 'resource_test.poster', 'configure', 'Configure the poster')->document);
    $this->assertSame($this->expected('poster.full.json'), $full);
    $these = $this->decoded($emitter->emit($poster, self::POSTER, 'resource_test.poster', 'configure', 'Configure the poster')->document);
    $this->assertSame($this->expected('poster.these.json'), $these);
    $this->assertTrue($this->validates($full['schema'], self::POSTER));
    $this->assertTrue($this->validates($these['schema'], $these['values']));
    // One layout's panels under another: both documents refuse it.
    $mixed = ['layout' => 'strip', 'panels' => ['columns' => 3, 'gap' => 'small']];
    $this->assertFalse($this->validates($full['schema'], $mixed));
    // The strip's caption is never "Untitled", which the documents leave
    // to the server: wider than the pipeline, never stricter.
    $this->assertTrue($this->validates($full['schema'], ['layout' => 'strip', 'panels' => ['caption' => 'Untitled']]));
    $this->assertSame(['not' => ['const' => 'Untitled']], $full['schema']['$defs']['resource_test.poster.panels.strip']['properties']['caption']['x-surface']['checkedOnServer'][0]['schema']);
    // Its tags are unique, which the documents state.
    $this->assertFalse($this->validates($full['schema'], ['layout' => 'strip', 'panels' => ['tags' => ['a', 'a']]]));
    $this->assertTrue($full['schema']['$defs']['resource_test.poster.panels.strip']['properties']['tags']['uniqueItems']);
  }

  /**
   * Tests the tool and the read tool for the layouts the site lists.
   */
  public function testTools(): void {
    $manager = $this->container->get('plugin.manager.tool');
    $values = $manager->getDefinition('surface:resource_test.poster:configure')->getInputDefinition(OperationInputs::VALUES);
    $this->assertInstanceOf(MapInputDefinition::class, $values);
    $properties = $values->getPropertyDefinitions();
    $this->assertSame(['layout', 'panels'], array_keys($properties));
    $panels = $properties['panels'];
    $this->assertInstanceOf(MapInputDefinition::class, $panels);
    // The layouts the site lists are left out of the cached definition,
    // which reads nothing stored; the listing for the account states the
    // stored grid's panels, none required, as for a class's variants.
    $this->assertSame([], $panels->getPropertyDefinitions());
    $listed = $manager->createInstance('surface:resource_test.poster:configure')->getInputDefinitions()[OperationInputs::VALUES];
    $this->assertInstanceOf(MapInputDefinition::class, $listed);
    $panels = $listed->getPropertyDefinition('panels');
    $this->assertInstanceOf(MapInputDefinition::class, $panels);
    $this->assertSame(['columns', 'gap'], array_keys($panels->getPropertyDefinitions()));
    $this->assertContains(PosterLayouts::TAG, $this->container->get('state')->get('surface_tool.listed_cache_tags'));

    $read = $manager->createInstance('surface:resource_test.poster:configure:read');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess(), (string) $read->getResult()->getMessage());
    $this->assertSame(self::POSTER, $read->getResult()->getContextValues()[OperationInputs::VALUES] ?? NULL);

    // The grid's panels sent for the strip: refused, naming the layout.
    $tool = $manager->createInstance('surface:resource_test.poster:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['layout' => 'strip', 'panels' => ['columns' => 2]]);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/panels/columns: panels.columns belongs to the grid variant, but layout chose strip.", PlainTextOutput::renderFromHtml((string) $tool->getResult()->getMessage()));

    // The strip's caption held to the schema it was read from, and its
    // tags to UniqueItems.
    $tool = $manager->createInstance('surface:resource_test.poster:configure');
    $tool->setInputValue(OperationInputs::VALUES, [
      'layout' => 'strip',
      'panels' => ['caption' => 'Untitled', 'tags' => ['music', 'music']],
    ]);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $message = PlainTextOutput::renderFromHtml((string) $tool->getResult()->getMessage());
    $this->assertStringContainsString('/panels/caption: This value does not hold to the schema it was read from:', $message);
    $this->assertStringContainsString('/panels/tags: The item "music" is listed more than once.', $message);

    // And written.
    $tool = $manager->createInstance('surface:resource_test.poster:configure');
    $tool->setInputValue(OperationInputs::VALUES, [
      'layout' => 'strip',
      'panels' => ['caption' => 'Spring', 'tags' => ['music', 'film']],
    ]);
    $tool->execute();
    $this->assertTrue($tool->getResult()->isSuccess(), (string) $tool->getResult()->getMessage());
    $this->assertSame(['layout' => 'strip', 'panels' => ['caption' => 'Spring', 'tags' => ['music', 'film']]], array_intersect_key($this->config('surface_resource_test.poster')->getRawData(), self::POSTER));
  }

  /**
   * Tests a provider whose list cannot be cached is refused, naming it.
   */
  public function testUncacheableProviderIsRefused(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('Drupal\surface_resource_test\Variants\UncacheableLayouts lists the variants at "detail" on resource_test.broken.uncacheable_variants with max-age 0. Variants from the site must be cacheable');
    $this->container->get('surface.resources')->build(UncacheableVariantsStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests a provider whose list varies by the account is refused.
   */
  public function testProviderVaryingByTheAccountIsRefused(): void {
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('varying by user.permissions. Which variants there are is the site\'s, the same for every account');
    $this->container->get('surface.resources')->build(AccountVariantsStructure::class, new OperationContext('configure'));
  }

  /**
   * Tests a provider that is none is refused, and a list's values are kept.
   */
  public function testWhatIsRefusedAsDeclared(): void {
    $blueprint = new ResourceBlueprint(new SurfaceBuilder(), $this->container->get('typed_data_manager'));
    try {
      $blueprint->attachVariants('panels', by: 'layout', from: BannerPanelsStructure::class);
      $this->fail('A provider that is none is refused.');
    }
    catch (\LogicException $e) {
      $this->assertSame('attachVariants("panels") names Drupal\surface_resource_test\Structure\Poster\BannerPanelsStructure as where its variants come from, which does not implement Drupal\surface\Resource\SiteListInterface: a provider lists the site\'s variants, each with its schema or structure, with list().', $e->getMessage());
    }
    $list = new SiteList();
    $list->add('one', 'One', schema: ['type' => 'object']);
    $refusals = [
      '' => 'An empty id cannot name an entry: it becomes a key, or the value that chooses a variant.',
      'one' => 'The "one" entry was added to this list already: one entry per thing.',
    ];
    foreach ($refusals as $value => $message) {
      try {
        $list->add((string) $value, 'Again', schema: ['type' => 'object']);
        $this->fail($message);
      }
      catch (\InvalidArgumentException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

  /**
   * Tests what a variant does not read is refused, as a closed list's own.
   *
   * One list type serves keys and variants from the site; a variant reads
   * a value, a label, and a schema or a structure, and a closed provider's
   * entry names the value and the label alone, its structure named once,
   * in structures().
   */
  public function testWhatVariantsDoNotReadIsRefused(): void {
    // The variants from the site are the attach phase's to read.
    $build = $this->container->get('surface.resources');
    $resources = (new \ReflectionProperty($build, 'attaching'))->getValue($build);
    $variantsFrom = new \ReflectionMethod($resources, 'variantsFrom');
    $poster = $this->container->get('surface.resource_registry')->getDefinition(PosterResource::class);
    $refusals = [
      LockedLayouts::class => LockedLayouts::class . ' lists "wide" for the variants at "panels" on resource_test.poster with a lock, which a variant does not read: each entry is a value of the deciding key, its label, and the schema or structure it chooses.',
      BareLayouts::class => BareLayouts::class . ' lists "wide" for the variants at "panels" on resource_test.poster with no schema and no structure: each variant from the site is one of them, SiteList::add(..., schema: ...) or add(..., structure: ...).',
      RestatingLayouts::class => RestatingLayouts::class . ' lists "banner" for the variants at "panels" on resource_test.poster with a structure of its own. A closed provider names each structure once, in its structures(); its list names a value and its label.',
      UnnamedLayouts::class => UnnamedLayouts::class . ' lists "poster" for the variants at "panels" on resource_test.poster, which its structures() does not name. A closed provider lists only the structures it names.',
    ];
    foreach ($refusals as $provider => $message) {
      try {
        $variantsFrom->invoke($resources, $poster, 'panels', $provider);
        $this->fail($message);
      }
      catch (\LogicException $e) {
        $this->assertSame($message, $e->getMessage());
      }
    }
  }

}
