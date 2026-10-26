<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\data_surface\Contract\ContractBaselines;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface\ResourceBuild\OperationDefinition;
use Drupal\data_surface\ResourceBuild\ResourceRegistry;
use Drupal\data_surface\ResourceBuild\Resources;
use Drupal\data_surface\Storage\StorageLayoutInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a build for no account is kept across requests, and read back whole.
 *
 * Every shipped operation's sealed build is built, kept in the build
 * cache, and read back in what stands for a later request (the request's
 * memo forgotten): the two emit the same full schema and the same schema
 * for a sample of values, with every variant the site lists stated, so
 * each variant left to be built when read is built from what was stored.
 * A kept build is dropped by every tag it was read from; one that holds
 * what cannot be stored is refused by name.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class BuildCacheTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_examples_workflow',
    'data_surface_first',
    'block',
    'path_alias',
    'data_surface_component_block',
    'data_surface_block',
    'data_surface_examples_keys',
    'text',
    'filter',
    'node',
    'data_surface_examples_references',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('path_alias');
    $this->installSchema('node', ['node_access']);
    $this->installConfig([
      'data_surface_examples',
      'data_surface_first',
      'node',
      'filter',
      'data_surface_examples_references',
    ]);
    $this->container->get('theme_installer')->install(['stark']);
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['uid' => 1, 'name' => 'admin', 'status' => 1])->save();
    Role::create(['id' => 'administrator', 'label' => 'Administrator', 'is_admin' => TRUE])->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets the build step.
   *
   * @return \Drupal\data_surface\ResourceBuild\Resources
   *   The service.
   */
  protected function resources(): Resources {
    $resources = $this->container->get('data_surface.resources');
    assert($resources instanceof Resources);
    return $resources;
  }

  /**
   * Writes what a build emits: its full schema, and its schema for a sample.
   *
   * The sample is what it starts from, so every key's refiners run.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The build.
   * @param string $id
   *   Its resource's id.
   * @param string $operation
   *   The operation it was built for.
   *
   * @return string
   *   Both documents, as JSON.
   */
  protected function emitted(DataSurfaceInterface $built, string $id, string $operation): string {
    $emitter = $this->container->get('data_surface.contract_emitter');
    return (string) json_encode([
      $emitter->emitFull($built, $id, $operation, $operation)->document,
      $emitter->emit($built, $built->getDefaultValues(), $id, $operation, $operation)->document,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
  }

  /**
   * Tests every shipped operation's build reads back equal to the one kept.
   */
  public function testEverySealedBuildReadsBackEqual(): void {
    $registry = $this->container->get('data_surface.resource_registry');
    $resources = $this->resources();
    $bin = $this->container->get('cache.data_surface_build');
    $budget = $this->container->get('data_surface.site_list_budget');
    // What a request registers by building, which a later one has not.
    $read = new \ReflectionProperty(ResourceRegistry::class, 'read');
    $checked = [];
    foreach ($registry->getDefinitions() as $name => $definition) {
      $operations = $registry->getOperations((string) $name);
      $contexts = $operations === []
        ? [ContractBaselines::ALONE => new OperationContext(ContractBaselines::ALONE)]
        : array_map(fn (OperationDefinition $operation): OperationContext => $operation->needsNothing() ? $resources->operation((string) $name, $operation->id) : new OperationContext($operation->id), $operations);
      foreach ($contexts as $operation => $context) {
        $bin->deleteAll();
        $resources->forgetBuilds();
        try {
          $fresh = $resources->buildDeclared((string) $name, $context);
        }
        catch (\LogicException) {
          // A refusal is the catalog's to list, and is never kept.
          continue;
        }
        // With the site's limit, and with every list the site decides
        // stated, which builds every variant.
        $all = fn (DataSurfaceInterface $built): string => $budget->withLimit(1000, fn (): string => $this->emitted($built, $definition->id, (string) $operation));
        $documents = [$this->emitted($fresh, $definition->id, (string) $operation), $all($fresh)];
        // A later request: nothing built, and nothing a build read from a
        // schema registered, so each variant is built from what was stored
        // and its structure registered again from its owner's list.
        $cached = $resources->buildCounts()['cached'];
        $resources->forgetBuilds();
        $read->setValue($registry, []);
        $woken = $resources->buildDeclared((string) $name, $context);
        $label = $definition->id . ':' . $operation;
        $this->assertSame($cached + 1, $resources->buildCounts()['cached'], $label . ' is read back from the build cache.');
        $this->assertNotSame($fresh, $woken, $label);
        $this->assertSame($fresh->getCacheTags(), $woken->getCacheTags(), $label);
        $this->assertSame($documents[0], $this->emitted($woken, $definition->id, (string) $operation), $label);
        $this->assertSame($documents[1], $all($woken), $label . ', every variant stated.');
        $checked[] = $label;
      }
    }
    $this->assertContains('event.spotlight:configure', $checked);
    $this->assertContains('block.placement:create', $checked);
    $this->assertContains('registration.step3:configure', $checked);
    $this->assertContains('first.newsletter:configure', $checked);
    $this->assertGreaterThan(40, count($checked));
  }

  /**
   * Tests the memo answers within a request, and the bin across them.
   */
  public function testTheMemoStandsInFrontOfTheBin(): void {
    $resources = $this->resources();
    $context = $resources->operation('first.newsletter', 'configure');
    $first = $resources->buildDeclared('first.newsletter', $context);
    $counts = $resources->buildCounts();
    $this->assertSame($first, $resources->buildDeclared('first.newsletter', $context));
    $this->assertSame($counts['reused'] + 1, $resources->buildCounts()['reused']);
    $this->assertSame($counts['cached'], $resources->buildCounts()['cached'], 'The memo answered: the bin was not read.');
    // Only a build for no account is kept across requests.
    $resources->forgetBuilds();
    $counts = $resources->buildCounts();
    $resources->build('first.newsletter', $context);
    $resources->buildStatic('first.newsletter', $context);
    $after = $resources->buildCounts();
    $this->assertSame([$counts['built'] + 2, $counts['cached']], [$after['built'], $after['cached']]);
  }

  /**
   * Tests a kept build carries, and is dropped by, every tag it was read from.
   */
  public function testKeptBuildIsDroppedByEveryTagItWasReadFrom(): void {
    $resources = $this->resources();
    $context = $resources->operation('event.spotlight', 'configure');
    $built = $resources->buildDeclared('event.spotlight', $context);
    $key = (new \ReflectionMethod(Resources::class, 'memoKey'))->invoke($resources, 'event.spotlight', $context, TRUE, TRUE);
    $cid = (new \ReflectionMethod(Resources::class, 'cacheId'))->invoke($resources, 'event.spotlight', $key);
    $kept = $this->container->get('cache.data_surface_build')->get($cid);
    $this->assertNotFalse($kept, 'The spotlight is kept.');
    $tags = $kept->tags;
    $registry = $this->container->get('data_surface.resource_registry');
    $expected = [
      // What the build read: the providers of what the site lists, the
      // site's defaults.
      ...$built->getCacheTags(),
      // What discovery found.
      ...$registry->getIndexCacheTags(),
      ...$registry->getDefinition('event.spotlight')->cacheTags,
      'config:core.extension',
      'config:data_surface.settings',
      'config:data_surface.defaults',
    ];
    foreach ($expected as $tag) {
      $this->assertContains($tag, $tags);
    }
    $dropping = [
      'config:core.extension',
      'config:data_surface.settings',
      'config:data_surface.defaults',
      ...$built->getCacheTags(),
    ];
    foreach ($dropping as $tag) {
      $resources->forgetBuilds();
      $resources->buildDeclared('event.spotlight', $context);
      $counts = $resources->buildCounts();
      Cache::invalidateTags([$tag]);
      $resources->forgetBuilds();
      $resources->buildDeclared('event.spotlight', $context);
      $after = $resources->buildCounts();
      $this->assertSame([$counts['built'] + 1, $counts['cached']], [$after['built'], $after['cached']], $tag . ' drops it.');
    }
  }

  /**
   * Tests a build holding what cannot be stored is refused, by name.
   */
  public function testWhatCannotBeStoredIsRefused(): void {
    $layout = new class() implements StorageLayoutInterface {

      /**
       * {@inheritdoc}
       */
      public function toStorage(array $values): array {
        return $values;
      }

      /**
       * {@inheritdoc}
       */
      public function fromStorage(array $settings): array {
        return $settings;
      }

    };
    $surface = new DataSurface(new DefinitionMap([]), thirdPartyLayouts: ['data_surface_test' => $layout]);
    $keep = new \ReflectionMethod(Resources::class, 'keep');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessageMatches('/^The first\.newsletter resource, built for its "configure" operation, cannot be kept in the build cache: Serialization of \'[^\']+@anonymous\' is not allowed\./');
    $keep->invoke($this->resources(), 'declared:test', 'first.newsletter', new OperationContext('configure'), $surface, []);
  }

}
