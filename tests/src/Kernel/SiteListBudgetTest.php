<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableResponseInterface;
use Drupal\surface\Contract\SiteListBudget;
use Drupal\surface_react\Controller\ResourceApiController;
use Drupal\surface_react\EventSubscriber\SchemaTagSubscriber;
use Drupal\surface_react\ServedPaths;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_resource_test\Keys\WorkshopBenches;
use Drupal\surface_resource_test\Resource\Poster\PosterResource;
use Drupal\surface_resource_test\Resource\Workshop\WorkshopResource;
use Drupal\surface_resource_test\Variants\PosterLayouts;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface\Plugin\tool\Tool\ResourceOperationSchemaTool;
use Drupal\Tests\surface\Traits\ContractValidationTrait;
use Drupal\Tests\surface\Traits\SchemaRoundTripTrait;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolOperation;
use Drupal\tool\TypedData\MapInputDefinition;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * A budget for what the site decides, and the tool that asks again.
 *
 * A list the site decides, variants from the site or keys from the site,
 * is spelled out in the full schema while it has at most
 * `surface.settings:site_list_limit` entries (20), and so is a map
 * of keys from the site in the tool listing. Past it the
 * full schema states the key open and `deferred`, an object holding a
 * `fetch` template, the deciding key keeps its options, and the keys from
 * the site are named under `x-surface.deferred.entries`;
 * `GET .../schema?choose=<pointer>=<value>`
 * answers the full schema with that choice stated as the full schema under
 * the limit states it; the schema for these answers states the chosen
 * variant exactly; each key counts at its own level; the write tool's
 * listing names the fetch and the narrowing tool,
 * `<resource>:<operation>:schema`, which is derived only where such a key
 * exists and answers what `POST .../schema` answers.
 *
 * @see docs/decisions.md#a-budget-for-what-the-site-decides
 * @see docs/decisions.md#a-list-left-out-for-size-is-deferred-not-refined
 * @see docs/served-contract.md#asking-again-at-the-tool-door
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class SiteListBudgetTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_react',
    'surface_resource_test',
  ];

  /**
   * The narrowing tool of the poster.
   */
  protected const POSTER_SCHEMA = 'surface:resource_test.poster:configure:schema';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface', 'surface_resource_test']);
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
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function built(string $class): SurfaceInterface {
    return $this->container->get('surface.resources')->buildOperation($class, 'configure');
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
    return json_decode((string) json_encode($this->container->get('surface.contract_emitter')->emitFull($this->built($class), $id, 'configure')->document['schema']), TRUE);
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
    return json_decode((string) json_encode($this->container->get('surface.contract_emitter')->emit($built, array_replace($built->getDefaultValues(), $values), $id, 'configure')->document), TRUE);
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
   * Tests twenty-five variants are deferred; the chooser keeps its options.
   */
  public function testPastTheLimitTheVariantsAreDeferred(): void {
    $this->layouts(25);
    $full = $this->full(PosterResource::class, 'resource_test.poster');
    $panels = $full['properties']['panels'];
    // An open object, `deferred`, its deciding key named and the fetch of
    // one variant beside it (its query alone: this document is served at
    // no URL): no union, no def and no conditional per variant.
    $this->assertSame([
      'title' => 'Panels',
      'type' => ['object', 'null'],
      'x-surface' => [
        'dependsOn' => ['layout'],
        'by' => 'layout',
        'deferred' => ['fetch' => '?choose=/panels={value}'],
      ],
    ], $panels);
    $this->assertArrayNotHasKey('$defs', $full);
    $this->assertArrayNotHasKey('allOf', $full);
    // Every layout is still offered, by id and label.
    $this->assertCount(25, $full['properties']['layout']['oneOf']);
    $this->assertSame(['const' => 'extra_22', 'title' => 'Extra 22'], $full['properties']['layout']['oneOf'][24]);
    // Whether the list is spelled out is the setting's.
    $emitted = $this->container->get('surface.contract_emitter')->emitFull($this->built(PosterResource::class), 'resource_test.poster');
    $this->assertContains('config:surface.settings', $emitted->getCacheTags());
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
    $this->assertArrayNotHasKey('deferred', $panels['x-surface']);
    $this->assertCount(19, $full['allOf']);
    $this->assertFalse($this->validates($full, ['layout' => 'strip', 'panels' => ['columns' => 3]]));

    // Twenty is still at most the limit; a lower limit is the site's to set.
    $this->layouts(20);
    $this->assertArrayHasKey('anyOf', $this->full(PosterResource::class, 'resource_test.poster')['properties']['panels']);
    $this->config(SiteListBudget::SETTINGS)->set(SiteListBudget::LIMIT, 10)->save();
    $this->assertArrayHasKey('fetch', $this->full(PosterResource::class, 'resource_test.poster')['properties']['panels']['x-surface']['deferred']);
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
    $listed = $this->container->get('surface.site_list_budget')->withLimit(100, fn (): array => $this->these(PosterResource::class, 'resource_test.poster', $values));
    $this->assertSame($listed['schema']['$defs']['resource_test.poster.panels.grid'], $schema['$defs']['resource_test.poster.panels.grid']);
    $this->assertSame($listed['values'], $these['values']);
    $this->assertTrue($this->validates($schema, $these['values']));
    $this->assertFalse($this->validates($schema, ['layout' => 'grid', 'panels' => ['columns' => 9]]));

    // Nothing chosen: open and `deferred`, as in the full schema.
    $none = $this->these(PosterResource::class, 'resource_test.poster', ['layout' => '']);
    $this->assertArrayHasKey('fetch', $none['schema']['properties']['panels']['x-surface']['deferred']);
    $this->assertArrayNotHasKey('refine', $none['schema']['properties']['panels']['x-surface']);
    $this->assertArrayNotHasKey('anyOf', $none['schema']['properties']['panels']);

    // The pipeline refuses another variant's keys by name, as ever.
    $tool = $this->tools()->createInstance('surface:resource_test.poster:configure');
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
      $this->assertArrayHasKey('fetch', $map['x-surface']['deferred'], $key);
      $this->assertArrayNotHasKey('refine', $map['x-surface'], $key);
      $this->assertSame('?choose=/' . $key . '={value}', $map['x-surface']['deferred']['fetch'], $key);
      $this->assertCount(25, $map['x-surface']['deferred']['entries'], $key);
      $this->assertSame(['id' => 'bench_7', 'label' => 'Bench 7'], $map['x-surface']['deferred']['entries'][6], $key);
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
    $this->assertArrayNotHasKey('deferred', $full['properties']['lights']['x-surface'] ?? []);
  }

  /**
   * Tests variants inside a variant count at their own level.
   *
   * A layout whose panels hold a detail of twenty-five kinds, read from its
   * own schema as variants a sibling chooses: four layouts are spelled
   * out, and inside the one, the detail is `deferred`, its fetch naming the
   * layout chosen on the way; at nineteen kinds it is spelled out too.
   */
  public function testNestedVariantsCountAtTheirLevel(): void {
    $this->layouts(4, ['nested' => ['label' => 'Nested', 'schema' => static::nested(25)]]);
    $full = $this->full(PosterResource::class, 'resource_test.poster');
    $this->assertCount(5, $full['properties']['panels']['anyOf']);
    $detail = $full['$defs']['resource_test.poster.panels.nested']['properties']['detail'];
    $this->assertArrayHasKey('fetch', $detail['x-surface']['deferred']);
    $this->assertSame('?choose=/panels=nested&choose=/panels/detail={value}', $detail['x-surface']['deferred']['fetch']);
    $this->assertSame('kind', $detail['x-surface']['by']);
    $this->assertArrayNotHasKey('anyOf', $detail);
    $this->assertCount(25, array_filter($full['$defs']['resource_test.poster.panels.nested']['properties']['kind']['oneOf'], static fn (array $choice): bool => isset($choice['const'])));
    $this->assertTrue($this->container->get('surface.site_list_budget')->anyOver($this->built(PosterResource::class)));

    $this->layouts(4, ['nested' => ['label' => 'Nested', 'schema' => static::nested(19)]]);
    $full = $this->full(PosterResource::class, 'resource_test.poster');
    $detail = $full['$defs']['resource_test.poster.panels.nested']['properties']['detail'];
    $this->assertCount(20, $detail['anyOf']);
    $this->assertFalse($this->container->get('surface.site_list_budget')->anyOver($this->built(PosterResource::class)));
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
    $full = $this->container->get('surface.contract_emitter')->emitFull($this->built(PosterResource::class), 'resource_test.poster', 'configure', 'Configure the poster')->document['schema'];
    $read = $this->readBack($full, 'resource_test.poster', 'surface_resource_test', 'configure', 'configure', 'Configure the poster', []);
    $this->assertNotSame('error', $read['verdict'], (string) $read['error']);
    if ($read['verdict'] !== 'same') {
      $definition = $this->container->get('surface.resource_registry')->getDefinition(PosterResource::class);
      $this->assertSame('site list', static::kindOf($full, $read['again'], $definition));
    }
  }

  /**
   * Tests the narrowing tool: derived only where a key is left out.
   *
   * Variants the site lists are left out of the listing whatever their
   * count (SiteListToolDoorTest); a map of keys from the site, past the
   * limit alone.
   */
  public function testTheNarrowingToolIsDerivedOnlyWhereSomeKeyIsOpen(): void {
    $manager = $this->tools();
    $this->assertTrue($manager->hasDefinition(self::POSTER_SCHEMA), 'Three layouts the site lists: a narrowing tool.');
    $this->assertFalse($manager->hasDefinition('surface:resource_test.workshop:configure:schema'), 'Three benches: no narrowing tool.');
    $lights = $manager->getDefinition('surface:resource_test.workshop:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['lights'];
    $this->assertNotSame([], $lights->getPropertyDefinitions());

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
    $panels = $manager->getDefinition('surface:resource_test.poster:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['panels'];
    $this->assertInstanceOf(MapInputDefinition::class, $panels);
    $this->assertSame([], $panels->getPropertyDefinitions());
    $this->assertStringEndsWith("Its keys depend on layout and are not listed here: choose layout, then fetch GET /surface-api/resource_test.poster/schema?choose=/panels={layout} once per layout, or call surface:resource_test.poster:configure:schema with your values, to get this key's schema.", (string) $panels->getDescription());
    $this->assertStringContainsString('fetch GET /surface-api/resource_test.poster/schema?choose={key}={value} once per value and keep the answer', (string) $definition->getDescription());
    $this->assertStringContainsString('or send your values here', (string) $definition->getDescription());
    $this->assertCount(25, $manager->getDefinition('surface:resource_test.poster:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['layout']->getConstraint('Choice')['choices'] ?? []);

    // Keys from the site past the limit name it too.
    $this->benches(25);
    $manager = $this->tools();
    $this->assertTrue($manager->hasDefinition('surface:resource_test.workshop:configure:schema'));
    $lights = $manager->getDefinition('surface:resource_test.workshop:configure')->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions()['lights'];
    $this->assertSame([], $lights->getPropertyDefinitions());
    $this->assertStringContainsString("Too many to list: fetch GET /surface-api/resource_test.workshop/schema?choose=/lights={id} once per entry (GET /surface-api/resource_test.workshop/schema lists the ids under x-surface.deferred.entries), or call surface:resource_test.workshop:configure:schema with your values, to get this key's schema.", (string) $lights->getDescription());
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
   * Tests the fetch of one choice: the variant stated as under the limit.
   *
   * `GET .../schema?choose=/panels=extra_5` answers the full schema with
   * the fifth extra layout's def and its one conditional, and nothing for
   * the other layouts; the key stays open and `deferred`, so the answer
   * accepts what the pipeline accepts. The def and the conditional are what
   * the full schema states when every layout is spelled out. It depends on
   * what the full schema depends on, the provider's tag and the setting
   * among it, and carries an `ETag` of its bytes.
   */
  public function testTheFetchOfOneChoice(): void {
    $this->layouts(25);
    $response = $this->served('GET', '/surface-api/resource_test.poster/schema?choose=/panels=extra_5');
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getContent());
    $this->assertSame(ResourceApiController::SCHEMA_TYPE, $response->headers->get('Content-Type'));
    $chosen = json_decode((string) $response->getContent(), TRUE);
    $this->assertSame('http://localhost/surface-api/resource_test.poster/schema?choose=/panels=extra_5', $chosen['$id']);
    $panels = $chosen['properties']['panels'];
    $this->assertSame(['object', 'null'], $panels['type']);
    $this->assertArrayNotHasKey('anyOf', $panels);
    $this->assertArrayHasKey('fetch', $panels['x-surface']['deferred']);
    $this->assertSame('http://localhost/surface-api/resource_test.poster/schema?choose=/panels={value}', $panels['x-surface']['deferred']['fetch']);
    $this->assertSame(['extra_5' => '#/$defs/resource_test.poster.panels.extra_5'], $panels['x-surface']['variants']);
    $this->assertSame(['resource_test.poster.panels.extra_5'], array_keys($chosen['$defs']));
    $this->assertCount(1, $chosen['allOf']);

    // What the full schema states of it with every layout spelled out.
    $listed = $this->container->get('surface.site_list_budget')->withLimit(100, fn (): array => $this->servedJson('GET', '/surface-api/resource_test.poster/schema'));
    $this->assertSame($listed['$defs']['resource_test.poster.panels.extra_5'], $chosen['$defs']['resource_test.poster.panels.extra_5']);
    $branch = array_values(array_filter($listed['allOf'], static fn (array $condition): bool => ($condition['if']['properties']['layout']['const'] ?? NULL) === 'extra_5'));
    $this->assertSame($branch, $chosen['allOf']);
    // The rest as the plain full schema says it.
    $plain = $this->servedJson('GET', '/surface-api/resource_test.poster/schema');
    unset($plain['$id'], $chosen['$id'], $chosen['allOf'], $chosen['$defs'], $chosen['properties']['panels']['x-surface']['variants']);
    $this->assertSame($plain, $chosen);

    // Never stricter: another layout's panels are accepted, the chosen
    // layout's held to its keys.
    $chosen = json_decode((string) $response->getContent(), TRUE);
    $this->assertTrue($this->validates($chosen, ['layout' => 'strip', 'panels' => ['columns' => 3]]));
    $this->assertTrue($this->validates($chosen, ['layout' => 'extra_5', 'panels' => ['caption_5' => 'Autumn']]));
    $this->assertFalse($this->validates($chosen, ['layout' => 'extra_5', 'panels' => ['caption_4' => 'Autumn']]));

    // Cacheable until the provider's list or the limit changes.
    $this->assertInstanceOf(CacheableResponseInterface::class, $response);
    $tags = $response->getCacheableMetadata()->getCacheTags();
    $this->assertContains(PosterLayouts::TAG, $tags);
    $this->assertContains('config:surface.settings', $tags);
    $this->assertContains('url.query_args', $response->getCacheableMetadata()->getCacheContexts());

    // An ETag of the bytes, and 304 to a request that holds them.
    $request = Request::create('/surface-api/resource_test.poster/schema?choose=/panels=extra_5');
    $request->attributes->set('_route', ServedPaths::ROUTE);
    $tagged = $this->tagged($request, $response);
    $this->assertSame('"' . hash('sha256', (string) $response->getContent()) . '"', $tagged->headers->get('ETag'));
    $again = Request::create('/surface-api/resource_test.poster/schema?choose=/panels=extra_5', server: ['HTTP_IF_NONE_MATCH' => 'W/' . $tagged->headers->get('ETag')]);
    $again->attributes->set('_route', ServedPaths::ROUTE);
    $this->assertSame(304, $this->tagged($again, $this->served('GET', '/surface-api/resource_test.poster/schema?choose=/panels=extra_5'))->getStatusCode());
    $this->assertNotSame($tagged->headers->get('ETag'), $this->tagged($request, $this->served('GET', '/surface-api/resource_test.poster/schema'))->headers->get('ETag'));
  }

  /**
   * Runs the schema's tag subscriber over a response, as the kernel does.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The request.
   * @param \Symfony\Component\HttpFoundation\Response $response
   *   The controller's response.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The same, tagged.
   */
  protected function tagged(Request $request, Response $response): Response {
    $event = new ResponseEvent($this->container->get('http_kernel'), $request, HttpKernelInterface::MAIN_REQUEST, $response);
    (new SchemaTagSubscriber())->onResponse($event);
    return $event->getResponse();
  }

  /**
   * Tests nested choices: a key inside a variant, named with its parent's.
   *
   * Four layouts are spelled out; the nested one's detail, twenty-five
   * kinds, is deferred. `choose=/panels=nested&choose=/panels/detail=kind_7`
   * answers the nested layout's def with the seventh kind's def and
   * conditional in it, in either order, as the full schema with every kind
   * spelled out states them; the detail alone, its layout not named, is
   * refused naming the layout's pointer and its four values.
   */
  public function testNestedChoices(): void {
    $this->layouts(4, ['nested' => ['label' => 'Nested', 'schema' => static::nested(25)]]);
    $queries = [
      '?choose=/panels=nested&choose=/panels/detail=kind_7',
      '?choose=/panels/detail=kind_7&choose=/panels=nested',
    ];
    foreach ($queries as $query) {
      $chosen = $this->servedJson('GET', '/surface-api/resource_test.poster/schema' . $query);
      $nested = $chosen['$defs']['resource_test.poster.panels.nested'] ?? NULL;
      $this->assertIsArray($nested, $query . ': ' . json_encode($chosen));
      $detail = $nested['properties']['detail'];
      $this->assertArrayHasKey('fetch', $detail['x-surface']['deferred'], $query);
      $this->assertSame('http://localhost/surface-api/resource_test.poster/schema?choose=/panels=nested&choose=/panels/detail={value}', $detail['x-surface']['deferred']['fetch'], $query);
      $this->assertSame(['kind_7'], array_keys($detail['x-surface']['variants']), $query);
      $kinds = array_values(array_filter($nested['allOf'] ?? [], static fn (array $condition): bool => isset($condition['if']['properties']['kind'])));
      $this->assertCount(1, $kinds, $query);
      $this->assertSame('kind_7', $kinds[0]['if']['properties']['kind']['const'], $query);
    }
    $listed = $this->container->get('surface.site_list_budget')->withLimit(100, fn (): array => $this->servedJson('GET', '/surface-api/resource_test.poster/schema'));
    $def = substr((string) $kinds[0]['then']['properties']['detail']['$ref'], strlen('#/$defs/'));
    $this->assertSame($listed['$defs'][$def], $chosen['$defs'][$def]);
    $branch = array_values(array_filter($listed['$defs']['resource_test.poster.panels.nested']['allOf'], static fn (array $condition): bool => ($condition['if']['properties']['kind']['const'] ?? NULL) === 'kind_7'));
    $this->assertSame($branch, $kinds);

    $refused = $this->servedJson('GET', '/surface-api/resource_test.poster/schema?choose=/panels/detail=kind_7');
    $this->assertSame(404, $refused['status']);
    $this->assertSame('/panels', $refused['pointer']);
    $this->assertSame(['banner', 'grid', 'strip', 'nested'], $refused['values']);
    $this->assertStringContainsString('choose=/panels=<value>', $refused['detail']);
  }

  /**
   * Tests a choice that names nothing is refused, naming its pointer.
   */
  public function testAnUnknownChoiceIsRefused(): void {
    $this->layouts(25);
    $refused = $this->servedJson('GET', '/surface-api/resource_test.poster/schema?choose=/panels=mural');
    $this->assertSame(404, $refused['status']);
    $this->assertSame('application/problem+json', $this->served('GET', '/surface-api/resource_test.poster/schema?choose=/panels=mural')->headers->get('Content-Type'));
    $this->assertSame('/panels', $refused['pointer']);
    // Twenty-five values: too many to list here; the full schema lists them.
    $this->assertArrayNotHasKey('values', $refused);
    $this->assertSame('The key of resource_test.poster at /panels has no mural to choose; it has 25, which the full schema lists.', $refused['detail']);

    $this->assertSame('/nothing', $this->servedJson('GET', '/surface-api/resource_test.poster/schema?choose=/nothing=x')['pointer']);
    $list = $this->servedJson('GET', '/surface-api/resource_test.poster/schema?choose=/layout=grid');
    $this->assertSame([404, '/layout'], [$list['status'], $list['pointer']]);
    $this->assertStringContainsString('takes no list from the site', $list['detail']);
    $this->assertSame(400, $this->servedJson('GET', '/surface-api/resource_test.poster/schema?choose=panels')['status']);
    // A name with no slash is a top-level key, as the lookup reads one.
    $this->assertSame(['extra_5'], array_keys($this->servedJson('GET', '/surface-api/resource_test.poster/schema?choose=panels=extra_5')['properties']['panels']['x-surface']['variants']));
  }

  /**
   * Tests the fetch of one entry of a map of keys from the site.
   *
   * `choose=/lights=bench_7` answers the map with that bench's key, and
   * `choose=/benches=bench_3` with that bench's structure, as the map's def
   * states them under the limit; the map stays open, `deferred`, its
   * entries named.
   */
  public function testTheFetchOfOneEntry(): void {
    $this->benches(25);
    $chosen = $this->servedJson('GET', '/surface-api/resource_test.workshop/schema?choose=/lights=bench_7&choose=/benches=bench_3');
    $listed = $this->container->get('surface.site_list_budget')->withLimit(100, fn (): array => $this->servedJson('GET', '/surface-api/resource_test.workshop/schema'));
    $lights = $chosen['properties']['lights'];
    $this->assertSame(['bench_7'], array_keys($lights['properties']));
    $this->assertSame($listed['$defs']['resource_test.workshop.lights']['properties']['bench_7'], $lights['properties']['bench_7']);
    $this->assertArrayHasKey('fetch', $lights['x-surface']['deferred']);
    $this->assertCount(25, $lights['x-surface']['deferred']['entries']);
    $this->assertArrayNotHasKey('additionalProperties', $lights);
    $benches = $chosen['properties']['benches'];
    $this->assertSame(['bench_3'], array_keys($benches['properties']));
    $this->assertSame($listed['$defs']['resource_test.workshop.benches']['properties']['bench_3'], $benches['properties']['bench_3']);
    $reference = substr((string) ($benches['properties']['bench_3']['$ref'] ?? ''), strlen('#/$defs/'));
    $this->assertSame($listed['$defs'][$reference], $chosen['$defs'][$reference]);
    // Open for the rest: a bench not chosen is not refused here.
    $this->assertTrue($this->validates($chosen, ['lights' => ['bench_9' => TRUE]]));
    $this->assertFalse($this->validates($chosen, ['lights' => ['bench_7' => 'on']]));

    $refused = $this->servedJson('GET', '/surface-api/resource_test.workshop/schema?choose=/lights=drill');
    $this->assertSame([404, '/lights'], [$refused['status'], $refused['pointer']]);
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
          // An `x-surface` that held only hints is not written.
          if ($value === []) {
            unset($node[$key]);
            continue;
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
