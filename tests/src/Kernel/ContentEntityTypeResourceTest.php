<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\node\Entity\Node;
use Drupal\node\NodeInterface;
use Drupal\surface\Contract\Choices;
use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Contract\Schemas;
use Drupal\surface\Contract\SiteListBudget;
use Drupal\surface\Pipeline\SurfaceStorageInterface;
use Drupal\surface\ResourceBuild\NamesNothingException;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\ResourceBuild\ResourcesInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Tool\OperationInputs;
use Drupal\surface_examples_entity\Resource\ContentEntityResources;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\UserInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;
use PortableSchema\Builder\Schema;

/**
 * Tests the resource per entity type against the resources per bundle.
 *
 * Both come from one deriver and hold the same field structures, so the
 * entity type's resource narrowed by a bundle is the bundle's resource:
 * for every bundle of every entity type with a form display on the test
 * site, the variant the bundle chooses, as the document states it at its
 * site, is the bundle's root less its identity, byte for byte, in the full
 * schema and the schema for empty values, of the add, the edit and the
 * read, and the read of one that exists answers the same fields. The
 * bundle offered on add are those the account may create; an unknown or a
 * refused bundle is refused at `bundle`; the edit holds the bundle to the
 * entity's; past the site list limit the variants are deferred and the
 * schema call with a bundle states its fields; and the tools and HTTP
 * paths stand beside the bundle's own. An entity type with a string id,
 * given a form display, is derived beside node: its id is the caller's to
 * name on create, and taken once.
 *
 * Decision: see
 * docs/decisions.md#two-entity-resources-from-one-deriver-static-per-bundle-runtime-per-entity-type.
 *
 * @see \Drupal\surface_examples_entity\Resource\ContentEntityResources
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ContentEntityTypeResourceTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * The node type's resource.
   */
  protected const RESOURCE = 'entity.content:node';

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_http', 'surface_openapi', 'surface_string_id_test'];

  /**
   * An entity type whose id is a string, which nothing assigns.
   *
   * Core's `entity_test_string_id` is one too, but core's test module
   * answers every field's access from State, which a static access
   * answer may not read, so a test module of this module's declares one.
   */
  protected const NAMED = 'surface_string_id';

  /**
   * An account that may do anything.
   */
  protected UserInterface $admin;

  /**
   * An account that may create and edit basic pages and nothing else.
   */
  protected UserInterface $editor;

  /**
   * How many lookup links the bundles' own resources carried (rootFields()).
   */
  protected int $linked = 0;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->installEntitySchema(self::NAMED);
    // A form display, so the deriver derives its resources.
    EntityFormDisplay::create([
      'targetEntityType' => self::NAMED,
      'bundle' => self::NAMED,
      'mode' => 'default',
      'status' => TRUE,
    ])->setComponent('name', ['type' => 'string_textfield', 'weight' => 0])->save();
    // User 1, so neither account below is.
    $this->setUpCurrentUser(admin: TRUE);
    $this->admin = $this->createUser([], NULL, TRUE);
    $this->editor = $this->createUser([
      'access content',
      'create page content',
      'edit any page content',
      'use text format basic',
    ]);
    $this->setCurrentUser($this->admin);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\surface\ResourceBuild\ResourcesInterface
   *   The service.
   */
  protected function resources(): ResourcesInterface {
    return $this->container->get('surface.resources');
  }

  /**
   * Lists the bundles every content entity resource is derived for.
   *
   * @return array<string, string[]>
   *   The bundles, by entity type id.
   */
  protected function bundles(): array {
    return array_map('array_keys', $this->container->get('surface_examples_entity.field_item_derivation')->bundles());
  }

  /**
   * Tests every bundle's variant is its own resource, in both documents.
   */
  public function testEachBundleNarrowsTheEntityTypeToItsOwnResource(): void {
    $bundles = $this->bundles();
    $this->assertSame(['node' => ['kitchen', 'page'], self::NAMED => [self::NAMED]], $bundles, 'The test site derives two node bundles, article having no form display, and the string id\'s one.');
    $emitter = $this->container->get('surface.contract_emitter');
    $compared = 0;
    foreach ([$this->admin, $this->editor] as $account) {
      $this->setCurrentUser($account);
      foreach ($bundles as $entity_type_id => $type_bundles) {
        $type = ContentEntityResources::BASE . ':' . $entity_type_id;
        foreach ($type_bundles as $bundle) {
          $own = $type . '.' . $bundle;
          // The full schema, built for the account knowing no subject.
          foreach (['create', 'update', 'read'] as $operation) {
            $context = $operation === 'create' ? $this->resources()->operation($own, 'create') : new OperationContext($operation);
            $wide = $operation === 'create' ? $this->resources()->operation($type, 'create') : new OperationContext($operation);
            $root = $emitter->emitFull($this->resources()->buildStatic($own, $context), $own, $operation)->document['schema'];
            $whole = $emitter->emitFull($this->resources()->buildStatic($type, $wide), $type, $operation)->document['schema'];
            $this->assertSame(static::encode($this->rootFields($root, $own, $operation)), static::encode($this->variantAtSite($whole, $entity_type_id, $bundle)), sprintf('%s narrowed by %s is %s, the full schema of %s, for %s.', $type, $bundle, $own, $operation, $account->getAccountName()));
            $compared++;
          }
          // The schema for empty values: a new one of the bundle.
          $built = $this->resources()->build($own, $this->resources()->operation($own, 'create'));
          $root = $emitter->emit($built, $built->getDefaultValues(), $own, 'create')->document['schema'];
          $whole_built = $this->resources()->build($type, $this->resources()->operation($type, 'create'));
          $values = array_replace($whole_built->getDefaultValues(), ['bundle' => $bundle]);
          $whole = $emitter->emit($whole_built, $values, $type, 'create')->document['schema'];
          if ($account === $this->editor && in_array($bundle, ['kitchen', self::NAMED], TRUE)) {
            // A bundle the editor may not create is not offered, and its
            // variant is not chosen by a value the key refuses.
            $this->assertNotContains('kitchen', array_column($whole['properties']['bundle']['oneOf'] ?? [], 'const'));
            continue;
          }
          $this->assertSame(static::encode($this->rootFields($root, $own, 'create')), static::encode($this->variantAtSite($whole, $entity_type_id, $bundle)), sprintf('%s narrowed by %s is %s, the schema for empty values, for %s.', $type, $bundle, $own, $account->getAccountName()));
          $compared++;
        }
      }
    }
    $this->assertSame(22, $compared);
    $this->assertGreaterThan(0, $this->linked, 'A bundle\'s own resource links its reference keys to its lookup.');

    // And for one that exists, its stored values.
    $this->setCurrentUser($this->admin);
    $node = $this->page('Stored');
    [$own_surface, $own_storage] = $this->operation('entity.content:node.page', 'update', ['id' => $node->id()]);
    [$whole_surface, $whole_storage] = $this->operation(self::RESOURCE, 'update', ['id' => $node->id()]);
    $root = $emitter->emit($own_surface, array_replace($own_surface->getDefaultValues(), $own_storage->load($own_surface)), 'entity.content:node.page', 'update')->document;
    $whole = $emitter->emit($whole_surface, array_replace($whole_surface->getDefaultValues(), $whole_storage->load($whole_surface)), self::RESOURCE, 'update')->document;
    $this->assertSame(static::encode($this->rootFields($root['schema'], 'entity.content:node.page', 'update')), static::encode($this->variantAtSite($whole['schema'], 'node', 'page')));
    $identity = ['entity_type' => TRUE, 'bundle' => TRUE, 'id' => TRUE];
    $this->assertSame(array_diff_key($root['values'], $identity), $whole['values'][ContentEntityResources::FIELDS], 'The same stored values, under fields.');
    $this->assertSame(array_intersect_key($root['values'], $identity), array_intersect_key($whole['values'], $identity));

    // The read: the entity type's narrowed by the page's bundle is the
    // page's own read, its schema and what it answers, for an account
    // that may only view.
    // Decision: see docs/decisions.md#a-read-is-an-operation-of-the-resource.
    $this->setCurrentUser($this->createUser(['access content']));
    [$own_surface, $own_storage] = $this->operation('entity.content:node.page', 'read', ['id' => $node->id()]);
    [$whole_surface, $whole_storage] = $this->operation(self::RESOURCE, 'read', ['id' => $node->id()]);
    $root = $emitter->emit($own_surface, array_replace($own_surface->getDefaultValues(), $own_storage->load($own_surface)), 'entity.content:node.page', 'read')->document;
    $whole = $emitter->emit($whole_surface, array_replace($whole_surface->getDefaultValues(), $whole_storage->load($whole_surface)), self::RESOURCE, 'read')->document;
    $this->assertSame(static::encode($this->rootFields($root['schema'], 'entity.content:node.page', 'read')), static::encode($this->variantAtSite($whole['schema'], 'node', 'page')));
    $this->assertSame(array_diff_key($root['values'], $identity), $whole['values'][ContentEntityResources::FIELDS]);
    $read = function (string $resource) use ($node): array {
      $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:' . $resource . ':read');
      $tool->setInputValue('id', (string) $node->id());
      $tool->execute();
      return $tool->getResult()->getContextValues()[OperationInputs::VALUES];
    };
    $this->assertSame($read('entity.content:node.page'), $read(self::RESOURCE)[ContentEntityResources::FIELDS], 'The two read tools answer the same fields.');
  }

  /**
   * Tests the bundles offered on add are those the account may create.
   */
  public function testTheBundlesOfferedAreThoseTheAccountMayCreate(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $offered = function (UserInterface $account) use ($emitter): array {
      $this->setCurrentUser($account);
      $built = $this->resources()->buildStatic(self::RESOURCE, $this->resources()->operation(self::RESOURCE, 'create'));
      $bundle = $emitter->emitFull($built, self::RESOURCE, 'create')->document['schema']['properties']['bundle'];
      return isset($bundle['const']) ? [$bundle['const']] : array_values(array_filter(array_column($bundle['oneOf'] ?? [], 'const'), 'is_string'));
    };
    $this->assertSame(['kitchen', 'page'], $offered($this->admin));
    $this->assertSame(['page'], $offered($this->editor));

    // An account that may create none is refused the add, before any
    // bundle is chosen; the edit is the entity's own access.
    $reader = $this->createUser(['access content']);
    $this->assertFalse($this->resources()->access(self::RESOURCE, $this->resources()->operation(self::RESOURCE, 'create'), $reader)->isAllowed());
    $this->assertTrue($this->resources()->access(self::RESOURCE, $this->resources()->operation(self::RESOURCE, 'create'), $this->editor)->isAllowed());
  }

  /**
   * Tests a missing, unknown or refused bundle is refused at `bundle`.
   */
  public function testBundleNotOfferedIsRefusedAtItsKey(): void {
    $this->setCurrentUser($this->editor);
    [$surface, $storage] = $this->operation(self::RESOURCE, 'create');
    // A title is a field of either bundle, so only the bundle is wrong.
    $fields = ['title' => ['value' => 'Hello']];
    foreach ([[], ['bundle' => 'nope'], ['bundle' => 'kitchen'], ['bundle' => 'article']] as $values) {
      $violations = $this->submit($surface, $storage, $values + [ContentEntityResources::FIELDS => $fields], TRUE);
      $this->assertArrayHasKey('bundle', $violations, json_encode($values) . ': ' . json_encode($violations));
    }
    $page = $fields + ['field_description' => ['value' => 'A page.']];
    $chosen = ['bundle' => 'page', ContentEntityResources::FIELDS => $page];
    $this->assertSame([], $this->submit($surface, $storage, $chosen, TRUE));
    $this->assertSame(0, $this->nodeCount());
  }

  /**
   * Tests the add writes a node of the bundle chosen, and the edit changes it.
   */
  public function testAddCreatesTheBundleChosenAndEditKeepsIt(): void {
    $this->setCurrentUser($this->admin);
    [$surface, $storage] = $this->operation(self::RESOURCE, 'create');
    $this->assertSame([], $this->submit($surface, $storage, [
      'bundle' => 'page',
      ContentEntityResources::FIELDS => [
        'title' => ['value' => 'Added'],
        'field_description' => ['value' => 'Through the entity type.'],
      ],
    ]));
    $node = $this->newest();
    $this->assertSame('page', $node->bundle());
    $this->assertSame(['Added', 'Through the entity type.'], [$node->label(), $node->get('field_description')->value]);

    // A field the entity refuses is filed under fields.
    [$surface, $storage] = $this->operation(self::RESOURCE, 'create');
    $untitled = [ContentEntityResources::FIELDS => ['title' => ['value' => 'No description']]];
    $violations = $this->submit($surface, $storage, ['bundle' => 'page'] + $untitled);
    $this->assertNotEmpty(preg_grep('/^fields\.field_description(\.|$)/', array_keys($violations)), json_encode($violations));

    [$surface, $storage] = $this->operation(self::RESOURCE, 'update', ['id' => $node->id()]);
    $this->assertSame([], $this->submit($surface, $storage, [ContentEntityResources::FIELDS => ['title' => ['value' => 'Edited']]]));
    $this->assertSame('Edited', $this->newest()->label());
    $this->assertSame('page', $this->newest()->bundle());

    // The bundle is the entity's: another is refused, and nothing written.
    $moved = [ContentEntityResources::FIELDS => ['title' => ['value' => 'Moved']]];
    $violations = $this->submit($surface, $storage, ['bundle' => 'kitchen'] + $moved);
    $this->assertArrayHasKey('bundle', $violations);
    $this->assertSame('Edited', $this->newest()->label());
    $full = $this->container->get('surface.contract_emitter')->emitFull($this->resources()->buildStatic(self::RESOURCE, $this->resources()->operation(self::RESOURCE, 'update', ['id' => $node->id()])), self::RESOURCE, 'update')->document['schema'];
    $this->assertSame('page', $full['properties']['bundle']['const'] ?? NULL);
  }

  /**
   * Tests past the site list limit the variants are deferred, and fetched.
   */
  public function testPastTheLimitTheFieldsAreDeferredAndFetchedPerBundle(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $budget = $this->container->get('surface.site_list_budget');
    $own = $emitter->emitFull($this->resources()->buildStatic('entity.content:node.kitchen', $this->resources()->operation('entity.content:node.kitchen', 'create')), 'entity.content:node.kitchen', 'create')->document['schema'];
    [$full, $chosen] = $budget->withLimit(1, function () use ($emitter): array {
      $built = $this->resources()->buildStatic(self::RESOURCE, $this->resources()->operation(self::RESOURCE, 'create'));
      return [
        $emitter->emitFull($built, self::RESOURCE, 'create')->document['schema'],
        $emitter->emitFull($built, self::RESOURCE, 'create', choices: Choices::resolve($built, [
          ['/' . ContentEntityResources::FIELDS, 'kitchen'],
        ]))->document['schema'],
      ];
    });
    $fields = $full['properties'][ContentEntityResources::FIELDS];
    $this->assertArrayHasKey('deferred', $fields['x-surface'] ?? [], 'The variants are left out for their count.');
    $this->assertSame(['kitchen', 'page'], array_values(array_filter(array_column($full['properties']['bundle']['oneOf'], 'const'), 'is_string')), 'The bundles are named.');
    $this->assertSame([], preg_grep('/^(field\.item|entity\.content):/', array_keys($full['$defs'] ?? [])), 'Nothing of the fields is stated.');
    $this->assertSame(static::encode($this->rootFields($own, 'entity.content:node.kitchen', 'create')), static::encode($this->variantAtSite($chosen, 'node', 'kitchen')), 'The schema call with a bundle states its fields.');
    $this->assertInstanceOf(SiteListBudget::class, $budget);
  }

  /**
   * Tests the tools and the HTTP paths stand beside the bundles' own.
   */
  public function testEveryDoorOpensBesideTheBundles(): void {
    $ids = array_keys($this->container->get('plugin.manager.tool')->getDefinitions());
    foreach (['entity.content:node', 'entity.content:node.page', 'entity.content:node.kitchen'] as $resource) {
      foreach (['create', 'update', 'read'] as $suffix) {
        $this->assertContains('surface:' . $resource . ':' . $suffix, $ids);
      }
    }
    // A bundle's fields are no resource: no tool.
    $this->assertEmpty(preg_grep('/^surface:entity\.content:node\.[a-z_]+\.fields:/', $ids));

    $this->container->get('router.builder')->rebuild();
    $document = $this->container->get('surface.openapi_document')->build(Request::create('https://example.com/surface-api/openapi.json'))->document;
    $paths = json_decode((string) json_encode($document['paths']), TRUE);
    $this->assertArrayHasKey('post', $paths['/surface-api/entity.content:node'] ?? []);
    $this->assertArrayHasKey('patch', $paths['/surface-api/entity.content:node/{id}'] ?? []);
    $this->assertArrayHasKey('post', $paths['/surface-api/entity.content:node.page'] ?? []);
  }

  /**
   * Tests the create tool writes a node of the bundle it is handed.
   */
  public function testTheCreateToolTakesTheBundle(): void {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:' . self::RESOURCE . ':create');
    $tool->setInputValue(OperationInputs::VALUES, [
      'bundle' => 'page',
      ContentEntityResources::FIELDS => ['title' => ['value' => 'By the tool'], 'field_description' => ['value' => 'x']],
    ]);
    $tool->execute();
    $result = $tool->getResult();
    $this->assertTrue($result->isSuccess(), PlainTextOutput::renderFromHtml((string) $result->getMessage()));
    $node = $this->newest();
    $this->assertSame(['page', 'By the tool'], [$node->bundle(), $node->label()]);
    $this->assertSame((int) $node->id(), $result->getContextValues()['id']);
  }

  /**
   * Tests an entity type whose ids are strings takes the id on create.
   *
   * Read per entity type from its id field: a string with no default is
   * the caller's to name. The create requires it at every door, a second
   * create under the same id is refused at `id`, and the update and the
   * read are asked by it. Node, a serial integer, is computed as before.
   */
  public function testCallerNamedIdIsTakenOnCreate(): void {
    $derivation = $this->container->get('surface_examples_entity.field_item_derivation');
    $this->assertTrue($derivation->callerNamesId(self::NAMED));
    $this->assertFalse($derivation->callerNamesId('node'));
    $this->setCurrentUser($this->admin);
    $tools = $this->container->get('plugin.manager.tool');
    $own = ContentEntityResources::BASE . ':' . self::NAMED . '.' . self::NAMED;
    $whole = ContentEntityResources::BASE . ':' . self::NAMED;
    foreach ([$own, $whole] as $resource) {
      $operations = $this->container->get('surface.resource_registry')->getOperations($resource);
      $this->assertTrue($operations['create']->idempotent(), $resource . ': a create whose caller names the thing is idempotent.');
      $this->assertFalse($this->container->get('surface.resource_registry')->getOperations('entity.content:node.page')['create']->idempotent(), 'A node create is not.');
      $context = $this->resources()->operation($resource, 'create');
      $this->assertArrayNotHasKey('id', $context->known, $resource . ': the create does not know the id.');
      $built = $this->resources()->buildStatic($resource, $context);
      $schema = $this->container->get('surface.contract_emitter')->emitFull($built, $resource, 'create')->document['schema'];
      $this->assertContains('id', $schema['required'], $resource);
      $this->assertSame('string', array_values(array_diff((array) $schema['properties']['id']['type'], ['null']))[0]);

      // The create tool requires the id, and writes under it.
      $create = $tools->getDefinition('surface:' . $resource . ':create');
      $values = $create->getInputDefinition(OperationInputs::VALUES)->getPropertyDefinitions();
      $this->assertTrue($values['id']->isRequired(), $resource . ': the create tool requires the id.');
    }

    [$surface, $storage] = $this->operation($own, 'create');
    $this->assertArrayHasKey('id', $this->submit($surface, $storage, ['name' => ['value' => 'Nameless']], TRUE), 'No id, no create.');
    $this->assertSame([], $this->submit($surface, $storage, ['id' => 'first', 'name' => ['value' => 'First']]));
    $entity = $this->container->get('entity_type.manager')->getStorage(self::NAMED)->loadUnchanged('first');
    $this->assertSame('First', $entity?->label());

    // A second create under the same id names something, and is refused.
    $again = [
      $own => ['id' => 'first', 'name' => ['value' => 'Again']],
      $whole => [
        'id' => 'first',
        'bundle' => self::NAMED,
        ContentEntityResources::FIELDS => ['name' => ['value' => 'Again']],
      ],
    ];
    foreach ($again as $resource => $values) {
      [$surface, $storage] = $this->operation($resource, 'create');
      $refused = iterator_to_array($this->pipeline()->submit($surface, $values, $storage)->violations, FALSE);
      $this->assertCount(1, $refused, $resource);
      $this->assertSame(['id', 'check.drupal:SurfaceUnique.taken'], [$refused[0]->key, $refused[0]->code], $resource);
      $this->assertStringContainsString('with the id first already exists', PlainTextOutput::renderFromHtml((string) $refused[0]->message));
    }
    $this->assertSame('First', $this->container->get('entity_type.manager')->getStorage(self::NAMED)->loadUnchanged('first')?->label());

    // Read and update by that id, at both resources.
    [$surface, $storage] = $this->operation($own, 'update', ['id' => 'first']);
    $this->assertSame([], $this->submit($surface, $storage, ['name' => ['value' => 'Edited']]));
    $read = $tools->createInstance('surface:' . $whole . ':read');
    $read->setInputValue('id', 'first');
    $read->execute();
    $this->assertTrue($read->getResult()->isSuccess(), PlainTextOutput::renderFromHtml((string) $read->getResult()->getMessage()));
    $answered = $read->getResult()->getContextValues()[OperationInputs::VALUES];
    $this->assertSame(self::NAMED, $answered['bundle']);
    $this->assertSame('Edited', $answered[ContentEntityResources::FIELDS]['name']['value']);
    [$surface, $storage] = $this->operation($whole, 'update', ['id' => 'first']);
    $this->assertSame([], $this->submit($surface, $storage, [ContentEntityResources::FIELDS => ['name' => ['value' => 'Again edited']]]));
    $this->assertSame('Again edited', $this->container->get('entity_type.manager')->getStorage(self::NAMED)->loadUnchanged('first')?->label());
    $this->expectException(NamesNothingException::class);
    $this->resources()->operation($own, 'update', ['id' => 'nowhere']);
  }

  /**
   * Builds an operation and its storage.
   *
   * @param string $resource
   *   The resource.
   * @param string $operation
   *   The operation id.
   * @param array $arguments
   *   Its arguments.
   *
   * @return array{0: \Drupal\surface\SurfaceInterface, 1: \Drupal\surface\Pipeline\SurfaceStorageInterface}
   *   The built resource and its storage.
   */
  protected function operation(string $resource, string $operation, array $arguments = []): array {
    $context = $this->resources()->operation($resource, $operation, $arguments);
    $surface = $this->resources()->build($resource, $context);
    return [$surface, $this->resources()->storage($resource, $context, $surface)];
  }

  /**
   * Submits values through the pipeline.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The built resource.
   * @param \Drupal\surface\Pipeline\SurfaceStorageInterface $storage
   *   The storage.
   * @param array $values
   *   The values.
   * @param bool $dry_run
   *   Whether to stop after prepare.
   *
   * @return array<string, string>
   *   The violations, by full path; empty when it was accepted.
   */
  protected function submit(SurfaceInterface $surface, SurfaceStorageInterface $storage, array $values, bool $dry_run = FALSE): array {
    $result = $this->pipeline()->submit($surface, $values, $storage, $dry_run);
    $violations = [];
    foreach ($result->violations as $violation) {
      $violations[$violation->fullPath()] = PlainTextOutput::renderFromHtml((string) $violation->message);
    }
    return $violations;
  }

  /**
   * Creates a basic page.
   *
   * @param string $title
   *   Its title.
   *
   * @return \Drupal\node\NodeInterface
   *   The page, saved.
   */
  protected function page(string $title): NodeInterface {
    $node = Node::create(['type' => 'page', 'title' => $title, 'field_description' => $title . ' description']);
    $node->save();
    return $node;
  }

  /**
   * Gets the newest node.
   *
   * @return \Drupal\node\NodeInterface
   *   The node, loaded from storage.
   */
  protected function newest(): NodeInterface {
    $storage = $this->container->get('entity_type.manager')->getStorage('node');
    $ids = $storage->getQuery()->accessCheck(FALSE)->sort('nid', 'DESC')->range(0, 1)->execute();
    $node = $ids === [] ? NULL : $storage->loadUnchanged(reset($ids));
    $this->assertInstanceOf(NodeInterface::class, $node);
    return $node;
  }

  /**
   * Counts the nodes.
   *
   * @return int
   *   How many there are.
   */
  protected function nodeCount(): int {
    return (int) $this->container->get('entity_type.manager')->getStorage('node')->getQuery()->accessCheck(FALSE)->count()->execute();
  }

  /**
   * Reads a bundle's root as the fields it holds, less its identity.
   *
   * Its lookup links are asserted and taken off: the bundle's own resource
   * links each reference key the lookup reaches to its lookup, where the
   * lookup's path takes no parameter, and the entity type's variant for
   * the bundle, which the lookup does not reach, links none
   * (variantAtSite()).
   *
   * @param array $schema
   *   The bundle resource's schema.
   * @param string $resource
   *   The bundle resource.
   * @param string $operation
   *   The operation the schema is for.
   *
   * @return array
   *   Its properties, the required ones, whether others are allowed, its
   *   conditionals and its defs, each less the identity keys, with no
   *   lookup link (withoutLookups()).
   */
  protected function rootFields(array $schema, string $resource, string $operation): array {
    $links = [];
    $schema = static::withoutLookups(Schemas::decoded(SchemaResolver::restored(Schema::from($schema))), '', $links);
    $path = $this->container->get('Drupal\surface\ResourceBuild\ServedPathsInterface')->lookupPath($resource, $operation);
    foreach ($links as $at => $href) {
      $this->assertTrue($path !== NULL && !str_contains($path, '{'), $resource . ' ' . $operation . ' ' . $at . ': a link only where the lookup\'s path is whole.');
      $key = str_replace('/properties/', '/', str_replace('/allOf/1/', '/', $at));
      $this->assertSame($path . '?key=' . $key . '{&q,limit}', $href, $resource . ' ' . $operation . ' ' . $at);
    }
    $this->linked += count($links);
    $identity = ['entity_type' => TRUE, 'bundle' => TRUE, 'id' => TRUE];
    return [
      'properties' => array_diff_key($schema['properties'], $identity),
      'required' => array_values(array_diff($schema['required'] ?? [], array_keys($identity))),
      'additionalProperties' => $schema['additionalProperties'] ?? NULL,
      'allOf' => $schema['allOf'] ?? [],
      '$defs' => $schema['$defs'] ?? [],
    ];
  }

  /**
   * Reads the variant a bundle chooses as its document states it there.
   *
   * The variant's def, with what the conditional that chooses it states
   * at its site laid over each key it names: a field its access locks or
   * leaves out for this account, or holds to what is stored, is said on
   * the variant's site, since the def is the structure as declared, and
   * a marker said there is moved to the owning object's `within`, which
   * SchemaResolver::restored() puts back first. Each key the site names
   * keeps what its def says and takes what the site says over it, the
   * site's composition in place of the def's reference, as a bundle's
   * root writes it.
   *
   * @param array $schema
   *   The entity type resource's schema.
   * @param string $entity_type_id
   *   The entity type id.
   * @param string $bundle
   *   The bundle.
   *
   * @return array
   *   What rootFields() reads of a bundle's root, read off the variant;
   *   the defs, those the bundle's root names, from the whole document.
   */
  protected function variantAtSite(array $schema, string $entity_type_id, string $bundle): array {
    $schema = Schemas::decoded(SchemaResolver::restored(Schema::from($schema)));
    $name = $entity_type_id . '.' . $bundle . ContentEntityResources::FIELDS_SUFFIX;
    $def = $schema['$defs'][ContentEntityResources::BASE . ':' . $name] ?? NULL;
    \assert(is_array($def), $name);
    $this->assertStringNotContainsString('x-portable-links', (string) json_encode($def), $name . ': the lookup does not reach into a variant.');
    $site = [];
    foreach ($schema['allOf'] ?? [] as $branch) {
      if (($branch['if']['properties']['bundle']['const'] ?? NULL) === $bundle) {
        $narrowing = $branch['then']['properties'][ContentEntityResources::FIELDS]['allOf'][1] ?? [];
        $site = is_array($narrowing) ? $narrowing['properties'] ?? [] : [];
      }
    }
    $properties = [];
    foreach ($def['properties'] as $key => $property) {
      if (isset($site[$key]['allOf'])) {
        // The site refers to the def itself, beside what it narrows.
        unset($property['$ref']);
      }
      $properties[$key] = isset($site[$key]) ? array_replace($property, $site[$key]) : $property;
    }
    $defs = array_diff_key($schema['$defs'], [ContentEntityResources::BASE . ':' . $name => TRUE]);
    $prefix = 'field.item:' . $name;
    $prefix = substr($prefix, 0, -strlen(ContentEntityResources::FIELDS_SUFFIX)) . '.';
    return [
      'properties' => $properties,
      'required' => $def['required'] ?? [],
      'additionalProperties' => $def['additionalProperties'] ?? NULL,
      'allOf' => $def['allOf'] ?? [],
      '$defs' => array_filter($defs, static fn (string $id): bool => str_starts_with($id, $prefix), ARRAY_FILTER_USE_KEY),
    ];
  }

  /**
   * Writes a schema part as the bytes a client reads.
   *
   * @param array $part
   *   The part.
   *
   * @return string
   *   Its JSON.
   */
  protected static function encode(array $part): string {
    return (string) json_encode($part, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
  }

  /**
   * Takes a schema's lookup links off, and a narrowing that said only them.
   *
   * @param array $schema
   *   The schema, or a node of it.
   * @param string $at
   *   Its JSON Pointer.
   * @param array<string, mixed> $links
   *   Collects each lookup link's href, by the pointer of its node.
   *
   * @return array
   *   The same, with no lookup link; a site whose narrowing carried links
   *   alone is its `$ref` again, where the `allOf` stood.
   */
  protected static function withoutLookups(array $schema, string $at, array &$links): array {
    if (is_array($schema['x-portable-links'] ?? NULL)) {
      $kept = [];
      foreach ($schema['x-portable-links'] as $link) {
        if (($link['rel'] ?? NULL) === 'lookup') {
          $links[$at] = $link['href'];
          continue;
        }
        $kept[] = $link;
      }
      if ($kept === []) {
        unset($schema['x-portable-links']);
      }
      else {
        $schema['x-portable-links'] = $kept;
      }
    }
    foreach ($schema as $keyword => $value) {
      if (is_array($value)) {
        $schema[$keyword] = static::withoutLookups($value, $at . '/' . $keyword, $links);
      }
    }
    $narrowing = $schema['allOf'][1] ?? NULL;
    $said = static fn (mixed $property): bool => $property !== [];
    $links_alone = is_array($narrowing) && array_diff(array_keys($narrowing), ['type', 'properties']) === [] && array_filter((array) ($narrowing['properties'] ?? []), $said) === [];
    if (isset($schema['allOf'][0]['$ref']) && count($schema['allOf']) === 2 && $links_alone) {
      $collapsed = [];
      foreach ($schema as $keyword => $value) {
        if ($keyword === 'allOf') {
          $collapsed['$ref'] = $value[0]['$ref'];
          continue;
        }
        $collapsed[$keyword] = $value;
      }
      $schema = $collapsed;
    }
    return $schema;
  }

}
