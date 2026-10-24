<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Cache\Cache;
use Drupal\data_surface\Contract\SiteListBudget;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface_resource_test\Keys\WorkshopBenches;
use Drupal\data_surface_resource_test\Resource\Poster\PosterResource;
use Drupal\data_surface_resource_test\Resource\Workshop\WorkshopResource;
use Drupal\data_surface_resource_test\Variants\PosterLayouts;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\data_surface_tool\Plugin\tool\Tool\ResourceOperationSchemaTool;
use Drupal\Tests\data_surface\Traits\ContractValidationTrait;
use Drupal\Tests\data_surface\Traits\SchemaRoundTripTrait;
use Drupal\Tests\data_surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A budget for what the site decides, and the tool that asks again.
 *
 * A list the site decides, variants from the site or keys from the site,
 * is spelled out in the full schema and the tool listing while it has at
 * most `data_surface.settings:site_list_limit` entries (20). Past it the
 * full schema states the key open and `refine`, the deciding key keeps its
 * options, and the keys from the site are named under `x-surface.entries`;
 * the schema for these answers states the chosen variant exactly; each
 * key counts at its own level; the write tool's listing names the
 * narrowing tool, `<resource>:<operation>:schema`, which is derived only
 * where such a key exists and answers what `POST .../schema` answers.
 *
 * @see docs/decisions.md#a-budget-for-what-the-site-decides
 * @see docs/served-contract.md#asking-again-at-the-tool-door
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class SiteListBudgetTest extends DataSurfaceKernelTestBase {

  use ContractValidationTrait;
  use SchemaRoundTripTrait;
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
    'data_surface',
    'data_surface_tool',
    'data_surface_react',
    'data_surface_resource_test',
  ];

  /**
   * The narrowing tool of the poster.
   */
  protected const POSTER_SCHEMA = 'data_surface:resource_test.poster:configure:schema';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface', 'data_surface_resource_test']);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Lists more poster layouts, so the poster has this many in all.
   *
   * The three the provider always lists, then plain ones, `extra_<n>`,
   * each holding one caption.
   *
   * @param int $count
   *   How many layouts in all.
   * @param array<string, array> $more
   *   Layouts to list beside them, by value.
   */
  protected function layouts(int $count, array $more = []): void {
    $layouts = [];
    for ($n = 1; $n <= $count - 3 - count($more); $n++) {
      $layouts['extra_' . $n] = [
        'label' => 'Extra ' . $n,
        'schema' => [
          'type' => 'object',
          'properties' => ['caption_' . $n => ['type' => 'string', 'title' => 'Caption']],
          'additionalProperties' => FALSE,
        ],
      ];
    }
    $this->container->get('state')->set(PosterLayouts::STATE, $layouts + $more);
    Cache::invalidateTags([PosterLayouts::TAG]);
  }

  /**
   * Lists this many benches for the workshop.
   *
   * @param int $count
   *   How many.
   */
  protected function benches(int $count): void {
    $benches = [];
    for ($n = 1; $n <= $count; $n++) {
      $benches['bench_' . $n] = ['label' => 'Bench ' . $n];
    }
    $this->config(WorkshopBenches::CONFIG)->set('benches', $benches)->save();
  }

  /**
   * Builds a resource in its configure operation.
   *
   * @param class-string $class
   *   The resource's class.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built resource.
   */
  protected function built(string $class): DataSurfaceInterface {
    return $this->container->get('data_surface.resources')->buildOperation($class, 'configure');
  }

  /**
   * Emits a full schema, decoded as a client holds it.
   *
   * @param class-string $class
   *   The resource's class.
   * @param string $id
   *   Its id.
   *
   * @return array
   *   The schema.
   */
  protected function full(string $class, string $id): array {
    return json_decode((string) json_encode($this->container->get('data_surface.contract_emitter')->emitFull($this->built($class), $id, 'configure')->document['schema']), TRUE);
  }

  /**
   * Emits the schema for some values, decoded.
   *
   * @param class-string $class
   *   The resource's class.
   * @param string $id
   *   Its id.
   * @param array $values
   *   The values.
   *
   * @return array
   *   The document: schema and values.
   */
  protected function these(string $class, string $id, array $values): array {
    $built = $this->built($class);
    return json_decode((string) json_encode($this->container->get('data_surface.contract_emitter')->emit($built, array_replace($built->getDefaultValues(), $values), $id, 'configure')->document), TRUE);
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
   * Tests twenty-five variants go `refine`, and the chooser keeps its options.
   */
  public function testPastTheLimitTheVariantsAreAskedFor(): void {
    $this->layouts(25);
    $full = $this->full(PosterResource::class, 'resource_test.poster');
    $panels = $full['properties']['panels'];
    // An open object, `refine`, its deciding key named: no union, no def
    // and no conditional per variant.
    $this->assertSame([
      'title' => 'Panels',
      'type' => ['object', 'null'],
      'x-surface' => ['locked' => FALSE, 'dependsOn' => ['layout'], 'by' => 'layout', 'refine' => TRUE],
    ], $panels);
    $this->assertArrayNotHasKey('$defs', $full);
    $this->assertArrayNotHasKey('allOf', $full);
    // Every layout is still offered, by id and label.
    $this->assertCount(25, $full['properties']['layout']['oneOf']);
    $this->assertSame(['const' => 'extra_22', 'title' => 'Extra 22'], $full['properties']['layout']['oneOf'][24]);
    // Whether the list is spelled out is the setting's.
    $emitted = $this->container->get('data_surface.contract_emitter')->emitFull($this->built(PosterResource::class), 'resource_test.poster');
    $this->assertContains('config:data_surface.settings', $emitted->getCacheTags());
    // Wider than the pipeline, never stricter: another layout's panels are
    // accepted by the full schema and refused by the pipeline by name.
    $this->assertTrue($this->validates($full, ['layout' => 'strip', 'panels' => ['columns' => 3]]));
  }

  /**
   * Tests nineteen variants are spelled out, and the limit is a setting.
   */
  public function testUpToTheLimitTheVariantsAreEnumerated(): void {
    $this->layouts(19);
    $full = $this->full(PosterResource::class, 'resource_test.poster');
    $panels = $full['properties']['panels'];
    $this->assertCount(20, $panels['anyOf'], 'A def per layout, and null.');
    $this->assertCount(19, (array) $panels['x-surface']['variants']);
    $this->assertArrayNotHasKey('refine', $panels['x-surface']);
    $this->assertCount(19, $full['allOf']);
    $this->assertFalse($this->validates($full, ['layout' => 'strip', 'panels' => ['columns' => 3]]));

    // Twenty is still at most the limit; a lower limit is the site's to set.
    $this->layouts(20);
    $this->assertArrayHasKey('anyOf', $this->full(PosterResource::class, 'resource_test.poster')['properties']['panels']);
    $this->config(SiteListBudget::SETTINGS)->set(SiteListBudget::LIMIT, 10)->save();
    $this->assertTrue($this->full(PosterResource::class, 'resource_test.poster')['properties']['panels']['x-surface']['refine']);
  }

  /**
   * Tests the schema for these answers states the chosen variant exactly.
   */
  public function testTheNarrowingStatesTheChosenVariant(): void {
    $this->layouts(25);
    $values = ['layout' => 'grid', 'panels' => ['columns' => 3]];
    $these = $this->these(PosterResource::class, 'resource_test.poster', $values);
    $schema = $these['schema'];
    $this->assertSame([['$ref' => '#/$defs/resource_test.poster.panels.grid'], ['type' => 'null']], $schema['properties']['panels']['anyOf']);
    $this->assertSame(['grid' => '#/$defs/resource_test.poster.panels.grid'], $schema['properties']['panels']['x-surface']['variants']);
    $this->assertSame('grid', $schema['properties']['panels']['x-surface']['chosen']);
    $this->assertSame(['resource_test.poster.panels.grid'], array_keys($schema['$defs']));
    $this->assertCount(1, $schema['allOf']);
    // The def is the one the variants spelled out state.
    $listed = $this->container->get('data_surface.site_list_budget')->withLimit(100, fn (): array => $this->these(PosterResource::class, 'resource_test.poster', $values));
    $this->assertSame($listed['schema']['$defs']['resource_test.poster.panels.grid'], $schema['$defs']['resource_test.poster.panels.grid']);
    $this->assertSame($listed['values'], $these['values']);
    $this->assertTrue($this->validates($schema, $these['values']));
    $this->assertFalse($this->validates($schema, ['layout' => 'grid', 'panels' => ['columns' => 9]]));

    // Nothing chosen: open and `refine`, as in the full schema.
    $none = $this->these(PosterResource::class, 'resource_test.poster', ['layout' => '']);
    $this->assertTrue($none['schema']['properties']['panels']['x-surface']['refine']);
    $this->assertArrayNotHasKey('anyOf', $none['schema']['properties']['panels']);

    // The pipeline refuses another variant's keys by name, as ever.
    $tool = $this->tools()->createInstance('data_surface:resource_test.poster:configure');
    $tool->setInputValue(OperationInputs::VALUES, ['layout' => 'strip', 'panels' => ['columns' => 2]]);
    $tool->execute();
    $this->assertFalse($tool->getResult()->isSuccess());
    $this->assertSame("The values were refused:\n/panels/columns: panels.columns belongs to the grid variant, but layout chose strip.", PlainTextOutput::renderFromHtml((string) $tool->getResult()->getMessage()));
  }

  /**
   * Tests keys from the site past the limit are an open map naming them.
   */
  public function testKeysFromTheSitePastTheLimit(): void {
    $this->benches(25);
    $full = $this->full(WorkshopResource::class, 'resource_test.workshop');
    foreach (['benches', 'lights', 'codes'] as $key) {
      $map = $full['properties'][$key];
      $this->assertSame(['object', 'null'], $map['type'], $key);
      $this->assertArrayNotHasKey('$ref', $map, $key);
      $this->assertTrue($map['x-surface']['refine'], $key);
      $this->assertCount(25, $map['x-surface']['entries'], $key);
      $this->assertSame(['id' => 'bench_7', 'label' => 'Bench 7'], $map['x-surface']['entries'][6], $key);
      $this->assertArrayNotHasKey('resource_test.workshop.' . $key, $full['$defs'] ?? []);
    }
    // The schema for these answers states every one.
    $these = $this->these(WorkshopResource::class, 'resource_test.workshop', []);
    $this->assertCount(25, $these['schema']['$defs']['resource_test.workshop.lights']['properties']);

    // Nineteen are spelled out.
    $this->benches(19);
    $full = $this->full(WorkshopResource::class, 'resource_test.workshop');
    $this->assertSame('#/$defs/resource_test.workshop.lights', $full['properties']['lights']['$ref']);
    $this->assertCount(19, $full['$defs']['resource_test.workshop.lights']['properties']);
    $this->assertArrayNotHasKey('refine', $full['properties']['lights']['x-surface']);
  }

  /**
   * Tests variants inside a variant count at their own level.
   *
   * A layout whose panels hold a detail of twenty-five kinds, read from its
   * own schema as variants a sibling chooses: four layouts are spelled
   * out, and inside the one, the detail is `refine`; at nineteen kinds it
   * is spelled out too.
   */
  public function testNestedVariantsCountAtTheirLevel(): void {
    $this->layouts(4, ['nested' => ['label' => 'Nested', 'schema' => static::nested(25)]]);
    $full = $this->full(PosterResource::class, 'resource_test.poster');
    $this->assertCount(5, $full['properties']['panels']['anyOf']);
    $detail = $full['$defs']['resource_test.poster.panels.nested']['properties']['detail'];
    $this->assertTrue($detail['x-surface']['refine']);
    $this->assertSame('kind', $detail['x-surface']['by']);
    $this->assertArrayNotHasKey('anyOf', $detail);
    $this->assertCount(25, array_filter($full['$defs']['resource_test.poster.panels.nested']['properties']['kind']['oneOf'], static fn (array $choice): bool => isset($choice['const'])));
    $this->assertTrue($this->container->get('data_surface.site_list_budget')->anyOver($this->built(PosterResource::class)));

    $this->layouts(4, ['nested' => ['label' => 'Nested', 'schema' => static::nested(19)]]);
    $full = $this->full(PosterResource::class, 'resource_test.poster');
    $detail = $full['$defs']['resource_test.poster.panels.nested']['properties']['detail'];
    $this->assertCount(20, $detail['anyOf']);
    $this->assertFalse($this->container->get('data_surface.site_list_budget')->anyOver($this->built(PosterResource::class)));
  }

  /**
   * Writes a layout's schema whose detail is one of many kinds.
   *
   * @param int $kinds
   *   How many kinds.
   *
   * @return array
   *   The schema: a kind, and a detail the kind chooses, each its def.
   */
  protected static function nested(int $kinds): array {
    $choices = [];
    $variants = [];
    $defs = [];
    for ($n = 1; $n <= $kinds; $n++) {
      $choices[] = ['const' => 'kind_' . $n, 'title' => 'Kind ' . $n];
      $variants['kind_' . $n] = '#/$defs/detail_' . $n;
      $defs['detail_' . $n] = [
        'type' => 'object',
        'properties' => ['note_' . $n => ['type' => 'string']],
        'additionalProperties' => FALSE,
      ];
    }
    return [
      'type' => 'object',
      'properties' => [
        'kind' => ['type' => 'string', 'title' => 'Kind', 'oneOf' => $choices],
        'detail' => [
          'title' => 'Detail',
          'anyOf' => array_map(static fn (string $reference): array => ['$ref' => $reference], array_values($variants)),
          'x-surface' => ['by' => 'kind', 'variants' => $variants],
        ],
      ],
      'additionalProperties' => FALSE,
      '$defs' => $defs,
    ];
  }

  /**
   * Tests a full schema past the limit is reported by its kind, read back.
   *
   * An adopter's ResourceTestBase reports a document that does not read
   * back to itself by kind: past the limit, the key is open, which reads
   * back as an open map.
   */
  public function testPastTheLimitReadsBackAsItsKind(): void {
    $this->layouts(25);
    $full = $this->container->get('data_surface.contract_emitter')->emitFull($this->built(PosterResource::class), 'resource_test.poster', 'configure', 'Configure the poster')->document['schema'];
    $read = $this->readBack($full, 'resource_test.poster', 'data_surface_resource_test', 'configure', 'configure', 'Configure the poster', []);
    $this->assertNotSame('error', $read['verdict'], (string) $read['error']);
    if ($read['verdict'] !== 'same') {
      $definition = $this->container->get('data_surface.resource_registry')->getDefinition(PosterResource::class);
      $this->assertSame('site list', static::kindOf($full, $read['again'], $definition));
    }
  }

  /**
   * Tests the narrowing tool: derived only where a key is past the limit.
   */
  public function testTheNarrowingToolIsDerivedOnlyWhereSomeKeyIsOpen(): void {
    $manager = $this->tools();
    $this->assertFalse($manager->hasDefinition(self::POSTER_SCHEMA), 'Three layouts: no narrowing tool.');
    $this->assertFalse($manager->hasDefinition('data_surface:resource_test.workshop:configure:schema'));
    $panels = $manager->getDefinition('data_surface:resource_test.poster:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['panels'];
    $this->assertNotSame([], $panels->getPropertyDefinitions());

    $this->layouts(25);
    $manager = $this->tools();
    $this->assertTrue($manager->hasDefinition(self::POSTER_SCHEMA));
    $definition = $manager->getDefinition(self::POSTER_SCHEMA);
    $this->assertSame(ResourceOperationSchemaTool::class, $definition->getClass());
    $this->assertSame(ToolOperation::Read, $definition->getOperation());
    $this->assertFalse($definition->getOperation()->isModifying(), 'Read only.');
    $this->assertTrue($definition->getOperation()->isIdempotent());
    $this->assertFalse($definition->isDestructive());
    $this->assertSame('Schema for these values: Configure the poster', (string) $definition->getLabel());
    $inputs = $definition->getInputDefinitions();
    $this->assertSame([OperationInputs::VALUES], array_keys($inputs));
    $this->assertInstanceOf(MapInputDefinition::class, $inputs[OperationInputs::VALUES]);
    $this->assertFalse($inputs[OperationInputs::VALUES]->isRequired());
    $this->assertSame([], $inputs[OperationInputs::VALUES]->getPropertyDefinitions());
    $this->assertSame([OperationInputs::SCHEMA, OperationInputs::VALUES], array_keys($definition->getOutputDefinitions()));

    // The write tool's listing states the panels open, naming the tool.
    $panels = $manager->getDefinition('data_surface:resource_test.poster:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['panels'];
    $this->assertInstanceOf(MapInputDefinition::class, $panels);
    $this->assertSame([], $panels->getPropertyDefinitions());
    $this->assertStringEndsWith("Too many to list: choose layout, then call data_surface:resource_test.poster:configure:schema with your values to get this key's schema.", (string) $panels->getDescription());
    $this->assertCount(25, $manager->getDefinition('data_surface:resource_test.poster:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['layout']->getConstraint('Choice')['choices'] ?? []);

    // Keys from the site past the limit name it too.
    $this->benches(25);
    $manager = $this->tools();
    $this->assertTrue($manager->hasDefinition('data_surface:resource_test.workshop:configure:schema'));
    $lights = $manager->getDefinition('data_surface:resource_test.workshop:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['lights'];
    $this->assertSame([], $lights->getPropertyDefinitions());
    $this->assertStringContainsString("Too many to list: call data_surface:resource_test.workshop:configure:schema with your values to get this key's schema.", (string) $lights->getDescription());
  }

  /**
   * Tests the narrowing tool answers what POST .../schema answers.
   */
  public function testTheNarrowingToolAnswersAsTheHttpNarrowing(): void {
    $this->layouts(25);
    $manager = $this->tools();
    foreach ([['layout' => 'strip'], ['layout' => 'grid', 'panels' => ['columns' => 4]], ['layout' => 'extra_5'], []] as $values) {
      $tool = $manager->createInstance(self::POSTER_SCHEMA);
      $tool->setInputValue(OperationInputs::VALUES, $values);
      $tool->execute();
      $result = $tool->getResult();
      $this->assertTrue($result->isSuccess(), (string) $result->getMessage());
      $outputs = $result->getContextValues();
      $http = $this->servedJson('POST', '/surface-api/resource_test.poster/schema', $values);
      $this->assertSame(static::withoutHints($http['schema']), json_decode((string) json_encode($outputs[OperationInputs::SCHEMA]), TRUE), json_encode($values));
      $this->assertSame($http['values'], json_decode((string) json_encode($outputs[OperationInputs::VALUES]), TRUE), json_encode($values));
    }
    // The strip's own keys, exactly.
    $tool = $manager->createInstance(self::POSTER_SCHEMA);
    $tool->setInputValue(OperationInputs::VALUES, ['layout' => 'strip']);
    $tool->execute();
    $schema = $tool->getResult()->getContextValues()[OperationInputs::SCHEMA];
    $this->assertSame(['caption', 'tags'], array_keys($schema['$defs']['resource_test.poster.panels.strip']['properties']));

    // Gated as the write tool is.
    $this->setUpCurrentUser();
    $tool = $manager->createInstance(self::POSTER_SCHEMA);
    $this->assertFalse($tool->access());
  }

  /**
   * Drops what only a renderer reads, and the `$id` a route serves.
   *
   * @param array $schema
   *   The schema, as the API serves it.
   *
   * @return array
   *   The schema the emitter writes with no widget hints.
   */
  protected static function withoutHints(array $schema): array {
    unset($schema['$id']);
    $strip = static function (array $node) use (&$strip): array {
      foreach ($node as $key => $value) {
        if ($key === 'x-surface' && is_array($value)) {
          unset($value['widget'], $value['multiple'], $value['arrangement']);
          if (isset($value['within']) && is_array($value['within'])) {
            $hints = array_flip(['widget', 'multiple', 'arrangement']);
            $value['within'] = array_map(static fn (array $within): array => array_diff_key($within, $hints), $value['within']);
          }
          $node[$key] = $value;
        }
        elseif (is_array($value)) {
          $node[$key] = $strip($value);
        }
      }
      return $node;
    };
    return $strip($schema);
  }

}
