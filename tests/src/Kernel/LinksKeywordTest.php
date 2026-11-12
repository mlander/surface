<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface_examples_references\Resource\SponsorshipResource;
use Drupal\surface_examples_references\Sponsorship;
use Drupal\surface_resource_test\Variants\PosterLayouts;
use Drupal\surface_resource_test\Resource\Poster\PosterResource;
use Drupal\surface_resource_test\Resource\Workshop\WorkshopResource;
use Drupal\surface_resource_test\Keys\WorkshopBenches;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Tests a reference and a deferred list are the dialect's check and links.
 *
 * A key that refers to one entity type states it as its check's options,
 * the plugin's own, and carries a `lookup` link, listed or not; an id whose
 * entity type its sibling names carries a `lookup` link whose `source`
 * reads that sibling; a deferred list carries a `choose` link, `{value}`
 * the deciding sibling's value for variants and an entry's id for a map of
 * keys from the site. A link is a site's: a key inside a def carries it at
 * each site's narrowing, never in the def several sites share, and a key
 * in a collection's item carries none. An operation `GET` does not serve
 * at its path has no lookup link, nor does a document without the HTTP
 * door; the check keeps its options either way. A reference reads back as
 * the key it was, links and all: the sponsorship's three keys that name
 * their entity type, read alone (its structures of any entity type are
 * read as SchemaReaderTest reads them).
 *
 * @see docs/decisions.md#a-reference-and-a-deferred-list-are-the-dialects-check-options-and-links
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
#[IgnoreDeprecations]
class LinksKeywordTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_references',
    'surface_reference_test',
    'surface_resource_test',
  ];

  /**
   * The sponsorship's lookup path.
   */
  protected const LOOKUP = '/surface-api/event.sponsorship:lookup';

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
      'surface',
      'surface_examples',
      'surface_examples_references',
      'surface_resource_test',
    ]);
    $this->assertNotNull(NodeType::load('article'));
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $sponsor = User::create(['name' => 'sponsor', 'status' => 1, 'roles' => ['administrator']]);
    $sponsor->save();
    $this->setCurrentUser($sponsor);
    $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->writeNodes();
  }

  /**
   * Emits the sponsorship's two documents, with the widget hints.
   *
   * @param string|null $operation
   *   The operation the documents are for, or NULL for none.
   *
   * @return array<string, \Drupal\surface\Contract\ServedContract>
   *   `emit` and `emitFull`.
   */
  protected function sponsorship(?string $operation = 'configure'): array {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $resources->forgetBuilds();
    $context = $resources->operation(SponsorshipResource::class, 'configure');
    $built = $resources->build(SponsorshipResource::class, $context);
    return [
      'emit' => $emitter->emit($built, $built->getDefaultValues(), 'event.sponsorship', $operation, widgets: TRUE),
      'emitFull' => $emitter->emitFull($resources->buildStatic(SponsorshipResource::class, $context), 'event.sponsorship', $operation, widgets: TRUE),
    ];
  }

  /**
   * Asserts a document loads in the dialect.
   *
   * @param \Drupal\surface\Contract\ServedContract $contract
   *   The document.
   * @param string $which
   *   Which it is, for the message and its id.
   */
  protected function assertLoads(ServedContract $contract, string $which): void {
    $id = 'surface:links/' . str_replace(' ', '/', $which);
    $this->assertInstanceOf(Validator::class, new Validator(Registry::withTypes()->add($contract->schema()->toDocument(), $id)), $which . ': loads in the dialect.');
  }

  /**
   * Tests a reference key's check options and lookup link, either way.
   */
  public function testReferenceKeysCarryTheirCheckAndLookup(): void {
    $references = $this->container->get('surface.references');
    $all = ['under' => $this->sponsorship(), 'past' => $references->withLimit(1, fn (): array => $this->sponsorship())];
    $check = static fn (array $options): array => ['drupal:SurfaceValidReference' => $options];
    foreach ($all as $where => $pair) {
      foreach ($pair as $kind => $contract) {
        $which = $where . ' ' . $kind;
        $schema = json_decode((string) json_encode($contract->document['schema']), TRUE);
        $venue = $schema['properties']['venue'];
        $this->assertSame($check(['entityTypeId' => 'event_venue']), $venue['portable-check'], $which);
        $this->assertSame([['rel' => 'lookup', 'href' => self::LOOKUP . '?key=/venue{&q,limit}']], $venue['x-portable-links'], $which);
        $this->assertSame(['portable-check', 'x-portable-messages', 'x-portable-ui', 'x-portable-links'], array_slice(array_keys($venue), -4), $which . ': after x-portable-ui, and no x-surface.');
        $this->assertSame($check(['entityTypeId' => 'user']), $schema['properties']['contact']['portable-check'], $which);
        $this->assertSame([['rel' => 'lookup', 'href' => self::LOOKUP . '?key=/contact{&q,limit}']], $schema['properties']['contact']['x-portable-links'], $which);
        $this->assertSame($check(['entityTypeId' => 'node', 'bundles' => ['article']]), $schema['properties']['page']['portable-check'], $which);
        $this->assertSame([['rel' => 'lookup', 'href' => self::LOOKUP . '?key=/page{&q,limit}']], $schema['properties']['page']['x-portable-links'], $which);
        $this->assertSame($where === 'under', isset($venue['oneOf']), $which . ': listed under the limit, looked up past it; the link either way.');

        // The bare id: no check, its entity type read from its sibling; its
        // link at the site, where the def is used, never in the def.
        foreach (['thing', 'setting', 'credit'] as $key) {
          $id = $schema['$defs']['event.sponsorship.' . $key]['properties']['id'];
          $this->assertArrayNotHasKey('x-portable-links', $id, $which . ' ' . $key . ': a def holds no link.');
          $at = $schema['properties'][$key]['allOf'][1]['properties']['id'];
          if ($kind === 'emit') {
            $this->assertFalse($at, $which . ' ' . $key . ': no entity type chosen, so no id, and no link.');
          }
          else {
            $link = [
              'rel' => 'lookup',
              'href' => self::LOOKUP . '?key=/' . $key . '/id&type={type}{&q,limit}',
              'source' => ['type' => '1/entity_type'],
            ];
            $this->assertSame(['x-portable-links' => [$link]], $at, $which . ' ' . $key . ': the narrowing carries the link alone.');
          }
          $this->assertArrayNotHasKey('portable-check', $id, $which . ' ' . $key);
          $this->assertArrayNotHasKey('reference', $id['x-surface'], $which . ' ' . $key);
          if ($kind === 'emitFull') {
            $this->assertSame(['dependsOn' => ['entity_type'], 'enumerated' => TRUE], $id['x-surface'], $which . ' ' . $key);
          }
        }
        $this->assertStringNotContainsString('"reference":', (string) json_encode($schema), $which);
        $this->assertLoads($contract, $which);
      }
    }
  }

  /**
   * Tests without an operation, or the HTTP door, there is no link.
   */
  public function testWithoutTheLookupTheCheckStaysAndNoLinkIsWritten(): void {
    $none = $this->sponsorship(NULL)['emitFull']->document['schema'];
    $this->assertSame(['drupal:SurfaceValidReference' => ['entityTypeId' => 'event_venue']], $none['properties']['venue']['portable-check']);
    $this->assertStringNotContainsString('x-portable-links', (string) json_encode($none), 'A document for no operation names no lookup.');

    $this->disableModules(['surface_http']);
    $this->assertFalse($this->container->has('Drupal\surface\ResourceBuild\ServedPathsInterface'));
    foreach ($this->sponsorship() as $kind => $contract) {
      $schema = $contract->document['schema'];
      $this->assertSame(['drupal:SurfaceValidReference' => ['entityTypeId' => 'event_venue']], $schema['properties']['venue']['portable-check'], $kind);
      $this->assertSame(['drupal:SurfaceValidReference' => ['entityTypeId' => 'node', 'bundles' => ['article']]], $schema['properties']['page']['portable-check'], $kind);
      $this->assertStringNotContainsString('x-portable-links', (string) json_encode($schema), $kind . ': no lookup is served.');
      $this->assertLoads($contract, 'without http ' . $kind);
    }
  }

  /**
   * Tests a deferred list's `choose` link, variants and a map.
   */
  public function testDeferredListsCarryTheirChooseLink(): void {
    $layouts = [];
    for ($n = 1; $n <= 22; $n++) {
      $layouts['extra_' . $n] = [
        'label' => 'Extra ' . $n,
        'schema' => [
          'type' => 'object',
          'properties' => ['caption_' . $n => ['type' => 'string', 'title' => 'Caption']],
          'additionalProperties' => FALSE,
        ],
      ];
    }
    $this->container->get('state')->set(PosterLayouts::STATE, $layouts);
    Cache::invalidateTags([PosterLayouts::TAG]);
    $benches = [];
    for ($n = 1; $n <= 25; $n++) {
      $benches['bench_' . $n] = ['label' => 'Bench ' . $n];
    }
    $this->config(WorkshopBenches::CONFIG)->set('benches', $benches)->save();
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');

    $poster = $resources->buildOperation(PosterResource::class, 'configure');
    $documents = [
      'emit' => $emitter->emit($poster, array_replace($poster->getDefaultValues(), ['layout' => '']), 'resource_test.poster', 'configure', widgets: TRUE),
      'emitFull' => $emitter->emitFull($poster, 'resource_test.poster', 'configure', widgets: TRUE),
    ];
    foreach ($documents as $kind => $contract) {
      $panels = json_decode((string) json_encode($contract->document['schema']), TRUE)['properties']['panels'];
      $this->assertSame([['rel' => 'choose', 'href' => '?choose=/panels={value}', 'source' => ['value' => '1/layout']]], $panels['x-portable-links'], $kind . ': `{value}` the deciding sibling\'s value.');
      $this->assertTrue($panels['x-surface']['deferred'], $kind);
      $this->assertSame('layout', $panels['x-surface']['by'], $kind);
      $this->assertSame(['x-portable-ui', 'x-portable-links', 'x-surface'], array_slice(array_keys($panels), -3), $kind);
      $this->assertLoads($contract, 'poster ' . $kind);
    }

    $workshop = $resources->buildOperation(WorkshopResource::class, 'configure');
    $full = $emitter->emitFull($workshop, 'resource_test.workshop', 'configure');
    $lights = json_decode((string) json_encode($full->document['schema']), TRUE)['properties']['lights'];
    $this->assertSame([['rel' => 'choose', 'href' => '?choose=/lights={value}']], $lights['x-portable-links'], 'An entry\'s id, which the entries list: no source.');
    $this->assertSame(['entries'], array_keys($lights['x-surface']['deferred']));
    $this->assertCount(25, $lights['x-surface']['deferred']['entries']);
    $this->assertSame(['id' => 'bench_1', 'label' => 'Bench 1'], $lights['x-surface']['deferred']['entries'][0]);
    $this->assertArrayNotHasKey('x-portable-ui', $lights, 'A link is no hint: it stays where the hints are left out.');
    $this->assertLoads($full, 'workshop emitFull');
  }

  /**
   * Tests a reference key reads back as the key it was, links and all.
   *
   * Emitted for the operation, so each key carries its link; the link is
   * noted, not read, and the key read back is emitted for the same
   * operation, which writes it again.
   */
  public function testTheReferenceReadsBack(): void {
    $references = $this->container->get('surface.references');
    $resources = $this->container->get('surface.resources');
    $reader = $this->container->get('surface.schema_reader');
    $emitter = $this->container->get('surface.contract_emitter');
    foreach (['under' => 50, 'past' => 1] as $where => $limit) {
      $references->withLimit($limit, function () use ($where, $resources, $reader, $emitter): void {
        $first = $this->sponsorship()['emitFull']->document['schema'];
        $schema = json_decode((string) json_encode($first), TRUE);
        $keys = ['venue', 'contact', 'page'];
        $schema['properties'] = array_intersect_key($schema['properties'], array_flip($keys));
        $schema['required'] = array_values(array_intersect($schema['required'] ?? [], $keys));
        unset($schema['$defs'], $schema['allOf']);
        $read = $resources->buildStatic($reader->structure($schema, 'test.sponsorship_read'), new OperationContext('configure'));
        $again = json_decode((string) json_encode($emitter->emitFull($read, 'event.sponsorship', 'configure', widgets: TRUE)->document['schema']), TRUE);
        foreach ($keys as $key) {
          $this->assertNotEmpty($schema['properties'][$key]['x-portable-links'], $where . ' ' . $key . ': the link takes part.');
          $this->assertSame($schema['properties'][$key], $again['properties'][$key], $where . ' ' . $key);
        }
        $this->assertSame('Not read: a link names where a client asks.', $reader->notes($schema)['/properties/venue/x-portable-links/0'] ?? NULL, $where . ': the link is noted.');
        $this->assertSame(['entityTypeId' => 'node', 'bundles' => ['article']], $read->getDefinition('page')->getConstraints()['SurfaceValidReference'], $where . ': as its author declares it.');
      });
    }
  }

  /**
   * Lists every lookup link of a document, by the JSON Pointer it is at.
   *
   * @param mixed $schema
   *   The schema, decoded as arrays.
   * @param string $at
   *   Its pointer.
   *
   * @return array<string, string>
   *   Each lookup link's href, by the pointer of the node carrying it.
   */
  protected static function lookups(mixed $schema, string $at = ''): array {
    if (!is_array($schema)) {
      return [];
    }
    $found = [];
    foreach ($schema['x-portable-links'] ?? [] as $link) {
      if (($link['rel'] ?? NULL) === 'lookup') {
        $found[$at] = $link['href'];
      }
    }
    foreach ($schema as $key => $value) {
      if ($key !== 'x-portable-links') {
        $found += static::lookups($value, $at . '/' . $key);
      }
    }
    return $found;
  }

  /**
   * Emits the roster's two documents for an operation.
   *
   * @param string $operation
   *   The operation.
   *
   * @return array<string, array>
   *   `emit` and `emitFull`, each its schema decoded as arrays.
   */
  protected function roster(string $operation): array {
    $resources = $this->container->get('surface.resources');
    $emitter = $this->container->get('surface.contract_emitter');
    $resources->forgetBuilds();
    $class = 'Drupal\surface_reference_test\Resource\RosterResource';
    $context = $resources->operation($class, $operation);
    $built = $resources->build($class, $context);
    $decoded = static fn (ServedContract $contract): array => json_decode((string) json_encode($contract->document['schema']), TRUE);
    return [
      'emit' => $decoded($emitter->emit($built, $built->getDefaultValues(), 'reference_test.roster', $operation, widgets: TRUE)),
      'emitFull' => $decoded($emitter->emitFull($resources->buildStatic($class, $context), 'reference_test.roster', $operation, widgets: TRUE)),
    ];
  }

  /**
   * Tests a link is a site's: a def shared by two keys and a list holds none.
   *
   * One structure that refers to an account is attached at `organizer` and
   * `speaker` and listed at `helpers`: one def, no `@2`; each attached site
   * carries its own lookup link at its narrowing, with its own pointer,
   * and the list's items, which the lookup does not reach, carry none.
   */
  public function testLinkIsTheSitesNeverTheDefs(): void {
    $lookup = '/surface-api/reference_test.roster:lookup';
    foreach ($this->roster('configure') as $kind => $schema) {
      $this->assertSame(['reference_test.author'], array_keys($schema['$defs']), $kind . ': one def, not split by its sites.');
      $this->assertArrayNotHasKey('x-portable-links', $schema['$defs']['reference_test.author']['properties']['author'], $kind . ': a def holds no link.');
      $this->assertSame(['drupal:SurfaceValidReference' => ['entityTypeId' => 'user']], $schema['$defs']['reference_test.author']['properties']['author']['portable-check'], $kind . ': the check keeps its options in the def.');
      $this->assertSame([
        '/properties/organizer/allOf/1/properties/author' => $lookup . '?key=/organizer/author{&q,limit}',
        '/properties/speaker/allOf/1/properties/author' => $lookup . '?key=/speaker/author{&q,limit}',
      ], static::lookups($schema), $kind . ': one link per reached site, its own pointer; none in a list item.');
      $this->assertSame('#/$defs/reference_test.author', $schema['properties']['helpers']['items']['$ref'] ?? $schema['properties']['helpers']['items']['allOf'][0]['$ref'] ?? NULL, $kind);
    }
  }

  /**
   * Tests an operation `GET` does not serve at its path has no lookup link.
   *
   * The lookup answers for the operation `GET` serves at the path, here
   * `configure`; `review` is served at `:review`, so its keys name no
   * lookup, and its check keeps its options.
   */
  public function testNoLookupLinkWhereGetDoesNotServeTheOperation(): void {
    $paths = $this->container->get('Drupal\surface\ResourceBuild\ServedPathsInterface');
    $this->assertSame('/surface-api/reference_test.roster:lookup', $paths->lookupPath('reference_test.roster', 'configure'));
    $this->assertNull($paths->lookupPath('reference_test.roster', 'review'));
    foreach ($this->roster('review') as $kind => $schema) {
      $this->assertSame([], static::lookups($schema), $kind . ': no lookup answers for it.');
      $this->assertSame(['drupal:SurfaceValidReference' => ['entityTypeId' => 'user']], $schema['$defs']['reference_test.author']['properties']['author']['portable-check'], $kind);
    }
  }

}
