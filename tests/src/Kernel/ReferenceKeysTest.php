<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Entity\ContentEntityInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\Reference\References;
use Drupal\data_surface\ResourceBuild\ResourceBlueprint;
use Drupal\Core\Render\Element;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\data_surface\Form\DataSurfaceOperationForm;
use Drupal\data_surface_examples\ConceptPanel;
use Drupal\data_surface_examples\ExamplePanels;
use Drupal\data_surface_examples_references\Resource\SponsorshipResource;
use Drupal\data_surface_examples_references\Sponsorship;
use Drupal\data_surface_tool\OperationInputs;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\tool\TypedData\MapInputDefinition;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests a key that refers to an entity: example 16's sponsorship.
 *
 * The three spellings build (`entity:<type>`, `entity:<type>:<bundle>`
 * and the bare `entity`, two parts narrowed by a refiner); both documents
 * of the configure operation are pinned whole under the limit and past it,
 * under tests/fixtures/references/ (written again with
 * DATA_SURFACE_REFERENCES_WRITE=1); the candidates are core's selection
 * handler's for the account, cached by what it read; the refusals; the
 * lookup and what it filters; the marker; the tool listing under the
 * limit and past it.
 *
 * @see docs/decisions.md#a-reference-is-an-id-with-a-list-the-site-decides-listed-or-looked-up
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReferenceKeysTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'text',
    'filter',
    'node',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_references',
  ];

  /**
   * Where both documents are pinned.
   */
  protected const FIXTURES = __DIR__ . '/../../fixtures/references/';

  /**
   * The tool.
   */
  protected const TOOL = 'data_surface:event.sponsorship:configure';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'system',
      'node',
      'filter',
      'data_surface',
      'data_surface_examples',
      'data_surface_examples_references',
    ]);
    // The article and page bundles are the module's optional config.
    $this->assertNotNull(NodeType::load('article'));
    $this->assertNotNull(NodeType::load('page'));
    // Accounts and a role of fixed names, which the documents list.
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $sponsor = User::create(['name' => 'sponsor', 'status' => 1, 'roles' => ['administrator']]);
    $sponsor->save();
    $this->setCurrentUser($sponsor);
    $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->writeNodes();
  }

  /**
   * Gets the ids of the three nodes, by name.
   *
   * @return array<string, int>
   *   The ids.
   */
  protected function nodes(): array {
    return $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->ids();
  }

  /**
   * Builds the sponsorship for the current account.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   It, built.
   */
  protected function built() {
    $resources = $this->container->get('data_surface.resources');
    return $resources->build(SponsorshipResource::class, $resources->operation(SponsorshipResource::class, 'configure'));
  }

  /**
   * Calls the tool and says what it answered.
   *
   * @param array $values
   *   The values.
   *
   * @return array
   *   `success`, and the violations' paths and messages.
   */
  protected function call(array $values): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance(self::TOOL);
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $result = $tool->getResult();
    $refused = [];
    foreach ($result->getContextValues()[OperationInputs::VIOLATIONS] ?? [] as $violation) {
      $refused[$violation['path']] = html_entity_decode(strip_tags((string) $violation['message']), ENT_QUOTES);
    }
    return ['success' => $result->isSuccess(), 'refused' => $refused];
  }

  /**
   * Tests the three spellings, and what each builds.
   */
  public function testTheThreeSpellingsBuild(): void {
    $built = $this->built();
    $venue = $built->getDefinition('venue');
    $this->assertSame('string', $venue->getDataType(), 'A config entity keeps its id as a string.');
    $this->assertSame(['entityTypeId' => 'event_venue'], $venue->getConstraints()[References::CONSTRAINT]);
    $contact = $built->getDefinition('contact');
    $this->assertSame('integer', $contact->getDataType(), "A user's id field is an integer.");
    $this->assertSame(['entityTypeId' => 'user'], $contact->getConstraints()[References::CONSTRAINT]);
    $page = $built->getDefinition('page');
    $this->assertSame('integer', $page->getDataType());
    $this->assertSame(['entityTypeId' => 'node', 'bundles' => ['article']], $page->getConstraints()[References::CONSTRAINT], 'The bundle is fixed: the selection handler is handed it as its target bundles.');

    // The bare `entity`: two parts attached at the key, the filter its
    // author wrote on it moved onto the entity type.
    $thing = $built->getDefinitions()->entry('thing');
    $this->assertNotNull($thing->attachment);
    $this->assertSame('event.sponsorship.thing', $thing->attachment->id);
    $child = $thing->attachment->child;
    $this->assertSame(['entity_type', 'id'], $child->getDefinitions()->names());
    $this->assertSame(['interface' => ContentEntityInterface::class], $child->getDefinition('entity_type')->getConstraints()[References::TYPE_CONSTRAINT]);
    $this->assertArrayNotHasKey(References::TYPE_CONSTRAINT, $thing->definition->getConstraints(), 'Taken off the key, so nothing checks it twice.');
    $this->assertSame('string', $child->getDefinition('id')->getDataType(), 'Entity types keep their ids as integers or strings: any id is a string.');
    // Its refiner narrows the id by the same constraint the spellings
    // build, so one mechanism serves all three.
    $refined = $child->refine(['entity_type' => 'node'], FALSE)->getDefinition('id');
    $this->assertSame(['entityTypeId' => 'node'], $refined->getConstraints()[References::CONSTRAINT]);
    $credit = $built->getDefinitions()->entry('credit')->attachment->child;
    $this->assertSame(['entityTypeId' => 'node', 'bundles' => ['article']], $credit->refine(['entity_type' => 'node'], FALSE)->getDefinition('id')->getConstraints()[References::CONSTRAINT], 'A bundle list narrows the id of each entity type it names.');

    // The spellings refused.
    $blueprint = (new ResourceBlueprint(new DataSurfaceBuilder(), $this->container->get('typed_data_manager')))->useReferences($this->container->get('data_surface.references'));
    foreach ([
      'entity:nope' => 'names an entity type this site does not have',
      'entity:' => 'names no entity type',
      'entity:node:article:x' => 'names no entity type',
    ] as $type => $message) {
      try {
        $blueprint->add('x', $type, 'X');
        $this->fail($type);
      }
      catch (\LogicException $e) {
        $this->assertStringContainsString($message, $e->getMessage());
      }
    }
    $this->assertSame('integer', $blueprint->add('missing_bundle', 'entity:node:gallery', 'Gallery')->getDataType(), 'A bundle the site does not have yet is no refusal: the key offers nothing until it does.');
    try {
      $blueprint->add('thing', 'entity', 'Thing');
      $this->fail('A blueprint with no registry attaches no parts.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('where nothing can attach its two parts', $e->getMessage());
    }
  }

  /**
   * Tests a key may refer to an entity type read from code, not installed.
   *
   * What the config schema generator builds for a module it reads before
   * it is installed: the id typed as the entity type's class says, and the
   * service itself still refusing a type the site does not have.
   */
  public function testAnEntityTypeReadFromCode(): void {
    $references = $this->container->get('data_surface.references');
    $types = $this->container->get('entity_type.manager')->getDefinitions();
    $content = clone $types['node'];
    $content->set('id', 'zz_content');
    $config = clone $types['event_venue'];
    $config->set('id', 'zz_config');
    $known = $references->knowing(['zz_content' => $content, 'zz_config' => $config]);
    $this->assertSame('integer', $known->definition('thing', 'entity:zz_content')->getDataType(), "Its class's id field is an integer.");
    $this->assertSame('string', $known->definition('thing', 'entity:zz_config')->getDataType());
    $this->assertSame(['entityTypeId' => 'zz_config'], $known->definition('thing', 'entity:zz_config')->getConstraints()[References::CONSTRAINT]);
    $this->expectExceptionMessage('names an entity type this site does not have');
    $references->definition('thing', 'entity:zz_config');
  }

  /**
   * Tests both documents, whole, under the limit and past it.
   */
  public function testBothDocumentsUnderAndPastTheLimit(): void {
    $resources = $this->container->get('data_surface.resources');
    $emitter = $this->container->get('data_surface.contract_emitter');
    $references = $this->container->get('data_surface.references');
    $operation = $this->container->get('data_surface.resource_registry')->getOperation(SponsorshipResource::class, 'configure');
    $documents = function () use ($resources, $emitter, $operation): array {
      $resources->forgetBuilds();
      $context = $resources->operation(SponsorshipResource::class, 'configure');
      $built = $resources->build(SponsorshipResource::class, $context);
      $values = array_replace($built->getDefaultValues(), $resources->storage(SponsorshipResource::class, $context, $built)->load($built));
      return [
        'emit' => $emitter->emit($built, $values, 'event.sponsorship', 'configure', $operation->label, TRUE, $operation->facts())->document,
        'full' => $emitter->emitFull($resources->buildStatic(SponsorshipResource::class, $context), 'event.sponsorship', 'configure', $operation->label, TRUE, $operation->facts())->document,
      ];
    };
    $all = ['under' => $documents(), 'past' => $references->withLimit(1, $documents)];
    foreach ($all as $where => $pair) {
      foreach ($pair as $kind => $document) {
        $json = json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION) . "\n";
        $file = self::FIXTURES . 'configure.' . $where . '.' . $kind . '.json';
        if (getenv('DATA_SURFACE_REFERENCES_WRITE')) {
          @mkdir(self::FIXTURES);
          file_put_contents($file, $json);
          continue;
        }
        $this->assertFileExists($file);
        $this->assertSame(file_get_contents($file), $json, sprintf('The %s document %s the limit; written again with DATA_SURFACE_REFERENCES_WRITE=1.', $kind, $where));
      }
    }
    if (getenv('DATA_SURFACE_REFERENCES_WRITE')) {
      $this->markTestSkipped('The reference documents were written.');
    }

    $nodes = $this->nodes();
    $under = $all['under']['full']['schema'];
    $past = $all['past']['full']['schema'];
    // Under the limit: a select of the candidates with their labels.
    $this->assertSame('select', $under['properties']['venue']['x-surface']['widget']);
    $this->assertSame([
      ['const' => 'harbour', 'title' => 'Harbour Centre'],
      ['const' => 'library', 'title' => 'Old Library'],
      ['const' => 'riverside', 'title' => 'Riverside Hall'],
    ], $under['properties']['venue']['oneOf']);
    $this->assertSame([
      ['const' => $nodes['draft'], 'title' => 'Draft sponsor terms'],
      ['const' => $nodes['article'], 'title' => 'Sponsor packages'],
      ['const' => NULL],
    ], $under['properties']['page']['oneOf'], 'Articles only, sorted by label: the page is another bundle.');
    // Past it: an id, looked up.
    $this->assertArrayNotHasKey('oneOf', $past['properties']['venue']);
    $this->assertSame('autocomplete', $past['properties']['venue']['x-surface']['widget']);
    $this->assertSame([
      ['constraint' => 'DataSurfaceValidReference', 'message' => 'Refers to an entity the account may reference'],
    ], $past['properties']['venue']['x-surface']['checkedOnServer']);
    // The marker, in both documents and either way.
    foreach ([$under, $past, $all['under']['emit']['schema'], $all['past']['emit']['schema']] as $schema) {
      $this->assertSame(['entityType' => 'event_venue'], $schema['properties']['venue']['x-surface']['reference']);
      $this->assertSame(['entityType' => 'node', 'bundles' => ['article']], $schema['properties']['page']['x-surface']['reference']);
    }
    // The bare key: its id refers to its sibling's entity type, and is
    // stated per entity type by a conditional, so a client narrows it with
    // no round trip.
    $thing = $under['$defs']['event.sponsorship.thing'];
    $this->assertSame(['by' => 'entity_type'], $thing['properties']['id']['x-surface']['reference']);
    $this->assertTrue($thing['properties']['id']['x-surface']['enumerated']);
    $node = array_values(array_filter($thing['allOf'], static fn (array $condition): bool => ($condition['if']['properties']['entity_type']['const'] ?? NULL) === 'node'));
    $this->assertCount(1, $node);
    $this->assertSame([
      ['const' => (string) $nodes['draft'], 'title' => 'Draft sponsor terms'],
      ['const' => (string) $nodes['article'], 'title' => 'Sponsor packages'],
      ['const' => (string) $nodes['page'], 'title' => 'Sponsor stand map'],
      ['const' => NULL],
    ], $node[0]['then']['properties']['id']['oneOf']);
    $this->assertSame(['event_venue', 'user_role', NULL], array_column($under['$defs']['event.sponsorship.setting']['properties']['entity_type']['oneOf'], 'const'), 'Config entities among the three it names: node is no config entity.');
  }

  /**
   * Tests the candidates are the account's, cached by what was read.
   */
  public function testTheCandidatesAreTheAccounts(): void {
    $options = $this->options();
    $page = $this->built()->getDefinition('page');
    $set = $options->resolve($page);
    $nodes = $this->nodes();
    $this->assertSame([$nodes['draft'], $nodes['article']], array_keys($set->options));
    $this->assertContains('node_list', $set->getCacheTags());
    $this->assertContains('config:data_surface.settings', $set->getCacheTags(), 'Whether it is listed at all is the limit\'s.');
    $this->assertSame(['user.permissions', 'user.node_grants:view'], $set->getCacheContexts(), 'What core\'s node selection reads.');
    $venue = $options->resolve($this->built()->getDefinition('venue'));
    $this->assertContains('config:event_venue_list', $venue->getCacheTags(), "A config entity type's list tag.");

    // An account that may not see unpublished content is not offered the
    // draft, and is refused it.
    $this->setUpCurrentUser([], ['access content', 'set event sponsorship']);
    $this->container->get('data_surface.resources')->forgetBuilds();
    $this->assertSame([$nodes['article']], array_keys($this->options()->resolve($this->built()->getDefinition('page'))->options));
    $this->assertSame([
      'success' => FALSE,
      'refused' => ['/page' => sprintf('This entity (node: %d) cannot be referenced.', $nodes['draft'])],
    ], $this->call(['page' => $nodes['draft']]));
    $this->assertSame(['success' => TRUE, 'refused' => []], $this->call(['page' => $nodes['article']]));
  }

  /**
   * Tests what is refused, and in which words.
   */
  public function testTheRefusals(): void {
    $nodes = $this->nodes();
    $this->assertSame(['success' => TRUE, 'refused' => []], $this->call([
      'venue' => 'library',
      'contact' => 1,
      'page' => $nodes['article'],
      'thing' => ['entity_type' => 'node', 'id' => (string) $nodes['article']],
      'credit' => ['entity_type' => 'user', 'id' => '1'],
    ]));
    $this->assertSame([
      'success' => FALSE,
      'refused' => ['/page' => sprintf('This entity (node: %d) cannot be referenced.', $nodes['page'])],
    ], $this->call(['page' => $nodes['page']]), 'A page where an article is asked for.');
    $this->assertSame(['success' => FALSE, 'refused' => ['/page' => 'This entity (node: 999) cannot be referenced.']], $this->call(['page' => 999]), 'One that names nothing, in the same words: a refusal never says what exists.');
    $this->assertSame([
      'success' => FALSE,
      'refused' => ['/venue' => 'This entity (event_venue: concert_hall) cannot be referenced.'],
    ], $this->call(['venue' => 'concert_hall']));
    $refused = $this->call(['setting' => ['entity_type' => 'node', 'id' => (string) $nodes['article']]]);
    $this->assertFalse($refused['success']);
    $this->assertSame('The entity type node cannot be referenced here.', $refused['refused']['/setting/entity_type'], 'Node is no config entity.');
    $this->assertSame([
      'success' => FALSE,
      'refused' => [
        '/credit/id' => sprintf('This entity (node: %d) cannot be referenced. This applies when Credited on: Entity type is node (Content).', $nodes['page']),
      ],
    ], $this->call(['credit' => ['entity_type' => 'node', 'id' => (string) $nodes['page']]]), 'The bundle list holds the id of each entity type it names.');
    $this->assertSame([
      'success' => FALSE,
      'refused' => ['/thing/id' => 'ID is required when Featured beside: Entity type is node (Content).'],
    ], $this->call(['thing' => ['entity_type' => 'node', 'id' => NULL]]), 'An entity type chosen names an id.');
  }

  /**
   * Tests the lookup: the key's candidates by label, for the account.
   */
  public function testTheLookup(): void {
    $references = $this->container->get('data_surface.references');
    $built = $this->built();
    $nodes = $this->nodes();
    $page = $references->definitionAt($built, ['page']);
    $this->assertSame(['entityType' => 'node', 'bundles' => ['article']], References::target($page));
    $this->assertSame([
      ['id' => $nodes['draft'], 'label' => 'Draft sponsor terms'],
      ['id' => $nodes['article'], 'label' => 'Sponsor packages'],
    ], $references->lookup('node', ['article'], 'Sponsor', 10));
    $this->assertSame([['id' => $nodes['article'], 'label' => 'Sponsor packages']], $references->lookup('node', ['article'], 'pack', 10));
    $this->assertSame([['id' => 'riverside', 'label' => 'Riverside Hall']], $references->lookup('event_venue', [], 'riv', 10));
    $this->assertCount(1, $references->lookup('event_venue', [], '', 1), 'At most the limit asked for.');

    // A bare key's id, of an entity type its own entity type allows, as
    // the key takes it: a string.
    $id = $references->definitionAt($built, ['thing', 'id'], 'node');
    $this->assertSame(['entityType' => 'node', 'bundles' => []], References::target($id));
    $this->assertSame([['id' => (string) $nodes['page'], 'label' => 'Sponsor stand map']], $references->lookup('node', [], 'stand', 10, $id->getDataType()));
    $this->assertNotNull($references->definitionAt($built, ['thing'], 'node'), 'The bare key names its id.');
    $this->assertNull($references->definitionAt($built, ['setting'], 'node'), 'Node is no config entity.');
    $this->assertNull($references->definitionAt($built, ['thing', 'id']), 'A bare key needs the entity type.');
    $this->assertNull($references->definitionAt($built, ['nothing']));

    // An account that may not see unpublished content finds no draft.
    $this->setUpCurrentUser([], ['access content', 'set event sponsorship']);
    $this->assertSame([['id' => $nodes['article'], 'label' => 'Sponsor packages']], $references->lookup('node', ['article'], 'Sponsor', 10));
  }

  /**
   * Tests the tool listing: an enum under the limit, the lookup past it.
   */
  public function testTheToolListing(): void {
    $listed = function (): array {
      $inputs = $this->container->get('data_surface_tool.operation_inputs');
      $registry = $this->container->get('data_surface.resource_registry');
      $this->container->get('data_surface.resources')->forgetBuilds();
      $values = $inputs->inputs($registry->getDefinition(SponsorshipResource::class), $registry->getOperation(SponsorshipResource::class, 'configure'))[OperationInputs::VALUES];
      assert($values instanceof MapInputDefinition);
      $properties = $values->getPropertyDefinitions();
      $thing = $properties['thing'];
      assert($thing instanceof MapInputDefinition);
      return [
        'venue' => [
          (string) $properties['venue']->getDescription(),
          $properties['venue']->getConstraints()['Choice']['choices'] ?? NULL,
        ],
        'thing' => [(string) $thing->getPropertyDefinitions()['id']->getDescription(), NULL],
      ];
    };
    $this->assertSame([
      'venue' => [
        "Where the sponsor's stand is. Required; a value already stored stands when left out.",
        ['harbour', 'library', 'riverside'],
      ],
      'thing' => [
        'The id of an entity of the entity type chosen. Send the id of an entity of the entity type chosen. Depends on thing.entity_type.',
        NULL,
      ],
    ], $listed(), 'Under the limit, the enum; the site serves no API, so no lookup is named.');
    $past = $this->container->get('data_surface.references')->withLimit(1, $listed);
    $this->assertSame([
      "Where the sponsor's stand is. Too many to list (more than 1): send the id of one. Required; a value already stored stands when left out.",
      NULL,
    ], $past['venue']);

    // With the API served, the listing names the lookup.
    $this->container->get('module_installer')->install(['data_surface_react']);
    $past = $this->container->get('data_surface.references')->withLimit(1, $listed);
    $this->assertSame([
      "Where the sponsor's stand is. Too many to list (more than 1): send the id of one; GET /surface-api/event.sponsorship:lookup?key=/venue&q={text} looks one up by its label. Required; a value already stored stands when left out.",
      NULL,
    ], $past['venue']);
    $this->assertSame('The id of an entity of the entity type chosen. Send the id of an entity of the entity type chosen; GET /surface-api/event.sponsorship:lookup?key=/thing/id&type={entity type}&q={text} looks one up by its label. Depends on thing.entity_type.', $past['thing'][0]);
  }

  /**
   * Tests the page: each key, both documents, the lookups and the calls.
   */
  public function testThePageShowsEachKey(): void {
    $this->container->get('module_installer')->install(['data_surface_react']);
    $this->container->get('router.builder')->rebuild();
    $route = $this->container->get('router.route_provider')->getRouteByName('data_surface_examples.step16');
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, 'data_surface_examples.step16');
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form = $this->container->get('form_builder')->getForm(DataSurfaceOperationForm::class)[DataSurfaceOperationForm::SURFACE_KEY];
    $this->assertSame('select', $form['venue']['#type']);
    $panel = $form[DataSurfaceOperationForm::PANEL_KEY];
    $this->assertSame([ExamplePanels::CONCEPT, ExamplePanels::FILES, ExamplePanels::CONTRACT, ExamplePanels::TODAY], array_values(array_intersect(Element::children($panel, TRUE), [
      ExamplePanels::CONCEPT,
      ExamplePanels::FILES,
      ExamplePanels::CONTRACT,
      ExamplePanels::TODAY,
    ])));
    $concept = $panel[ExamplePanels::CONCEPT];
    $this->assertSame(ConceptPanel::DOCS['event.sponsorship'], array_map(static fn (array $item): string => strip_tags((string) $item['#markup']), $concept['docs']['#items']));
    foreach (ConceptPanel::DOCS['event.sponsorship'] as $page) {
      $this->assertFileExists(dirname(__DIR__, 3) . '/' . strtok($page, '#'));
    }
    $this->assertSame([
      ['venue', 'select', '3'],
      ['contact', 'select', '3'],
      ['page', 'select', '2'],
      ['thing', 'fieldset', '—'],
    ], array_map(static fn (array $row): array => [$row[0], $row[2], $row[3]], array_slice($concept['keys']['#rows'], 0, 4)));
    $past = json_decode((string) $concept['past']['content']['#code'], TRUE);
    $this->assertSame('autocomplete', $past['venue']['x-surface']['widget']);
    $this->assertArrayNotHasKey('oneOf', $past['contact']);
    $listing = json_decode((string) $concept['listing']['content']['#code'], TRUE);
    $this->assertSame([1, 2], $listing['now']['contact']['enum'], 'Derived as the anonymous account: the active users, admin and the sponsor.');
    $this->assertStringContainsString('GET /surface-api/event.sponsorship:lookup?key=/contact&q={text}', $listing['past the limit']['contact']['description']);
    $lookups = (string) $concept['lookup']['content']['#code'];
    $this->assertStringContainsString("GET /surface-api/event.sponsorship:lookup?key=/venue&q=riv&limit=10\n200 {\"key\":\"/venue\",\"entityType\":\"event_venue\",\"bundles\":[],\"items\":[{\"id\":\"riverside\",\"label\":\"Riverside Hall\"}]}", $lookups);
    $this->assertStringContainsString("GET /surface-api/event.sponsorship:lookup?key=/setting&type=node&q=Sponsor\n404 ", $lookups, 'Node is no config entity.');
    $this->assertSame([
      'event.sponsorship:valid',
      'event.sponsorship:wrong_bundle',
      'event.sponsorship:not_referenceable',
      'event.sponsorship:not_config',
    ], array_values(array_filter(Element::children($concept['calls']), static fn (string $key): bool => str_starts_with($key, 'event.sponsorship:'))));
    $this->assertSame(50, $this->container->get('data_surface.references')->limit(), 'The limit held for the page is put back.');
  }

  /**
   * Tests the OpenAPI document describes the lookup.
   */
  public function testTheOpenApiDocumentDescribesTheLookup(): void {
    $this->container->get('module_installer')->install(['data_surface_openapi']);
    $document = $this->container->get('data_surface_openapi.document')->build(Request::create('https://example.com/surface-api/openapi.json'))->document;
    $lookup = $document['paths']['/surface-api/event.sponsorship:lookup']['get'];
    $this->assertSame('event.sponsorship:lookup', $lookup['operationId']);
    $this->assertSame('read', $lookup['x-surface-kind']);
    $this->assertSame('set event sponsorship', $lookup['x-drupal-permission']);
    $this->assertSame(['key', 'q', 'type', 'limit'], array_column($lookup['parameters'], 'name'));
    $this->assertSame([
      '/venue',
      '/contact',
      '/page',
      '/thing',
      '/thing/id',
      '/setting',
      '/setting/id',
      '/credit',
      '/credit/id',
    ], $lookup['parameters'][0]['schema']['enum'], 'Every reference key, and a bare key, which names its id.');
    $this->assertSame(['key', 'entityType', 'bundles', 'items'], $lookup['responses']['200']['content']['application/json']['schema']['required']);
    $this->assertSame(['/surface-api/registration.step2:lookup'], array_values(array_filter(array_keys($document['paths']), static fn (string $path): bool => str_ends_with($path, ':lookup') && $path !== '/surface-api/event.sponsorship:lookup')), 'Only a resource with a reference key has a lookup: example 2\'s venue is one.');
    $this->assertSame(['/venue'], $document['paths']['/surface-api/registration.step2:lookup']['get']['parameters'][0]['schema']['enum']);
  }

  /**
   * Tests the example's panel: its paragraph, and the account of a call.
   */
  public function testThePanel(): void {
    $panel = $this->container->get('data_surface_examples.concept_panel');
    $this->assertStringContainsString('<code>entity:node:article</code> an article', (string) $panel->paragraph('event.sponsorship'));
    $account = ConceptPanel::accountOf('event_sponsorship_editor');
    $this->assertNotNull($account);
    $this->assertTrue($account->hasPermission('set event sponsorship'));
    $this->assertFalse($account->hasPermission('bypass node access'));
    $this->assertInstanceOf(MapDataDefinition::class, $this->built()->getDefinition('thing'));
  }

}
