<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\ResourceBuild\Resources;
use Drupal\data_surface\SettledCacheability;
use Drupal\data_surface_examples\Resource\SpotlightResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a variant the site lists is built when a door reads it.
 *
 * Example 12's spotlight: the components a theme provides are its props'
 * and its slots' variants. The build seals them by value, label and
 * access, and builds each when a door reads it: the full schema the ones
 * it states (every one within the site's list limit, none past it), the
 * schema for a chosen component that one, the catalog all of them. What
 * each door answers is what it answered when the build built them all,
 * which the never-stricter, every-door and round trip tests hold.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class LazyVariantsTest extends DataSurfaceKernelTestBase {

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
  ];

  /**
   * The keys of the spotlight whose variants the site lists.
   */
  protected const LISTED = ['props', 'slots'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
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
   * Builds the spotlight for no account, nothing built before it.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The build.
   */
  protected function spotlight(): DataSurfaceInterface {
    $this->container->get('cache.data_surface_build')->deleteAll();
    $this->resources()->forgetBuilds();
    return $this->resources()->buildDeclared(SpotlightResource::class, $this->resources()->operation(SpotlightResource::class, 'configure'));
  }

  /**
   * Lists which variants at the listed keys are built, by key and value.
   *
   * @param \Drupal\data_surface\DataSurfaceInterface $built
   *   The build.
   *
   * @return array<string, array<string, bool>>
   *   Whether each is built.
   */
  protected function settled(DataSurfaceInterface $built): array {
    $settled = [];
    foreach (self::LISTED as $key) {
      foreach ($built->getDefinitions()->entry($key)->variants->variants as $value => $variant) {
        $this->assertTrue($variant->isDeferred(), $key . ' ' . $value . ' is listed by the site.');
        $settled[$key][(string) $value] = $variant->isSettled();
      }
    }
    return $settled;
  }

  /**
   * Tests the build builds no variant the site lists.
   */
  public function testTheBuildBuildsNoVariantTheSiteLists(): void {
    $built = $this->spotlight();
    $settled = $this->settled($built);
    $this->assertCount(4, $settled['props'], 'The four components the examples ship.');
    $this->assertSame([], array_filter([...array_values($settled['props']), ...array_values($settled['slots'])]));
    // What chooses them is spelled out without building any.
    $this->assertCount(4, $built->getDefinitions()->entry('props')->variants->variantIds());
    $this->assertSame(0, $this->resources()->buildCounts()['settled']);
  }

  /**
   * Tests the full schema builds what it states, and only that.
   */
  public function testTheFullSchemaBuildsWhatItStates(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $budget = $this->container->get('data_surface.site_list_budget');
    $built = $this->spotlight();
    // Past the limit: each listed key is open, deferred, its values named.
    $budget->withLimit(1, fn () => $emitter->emitFull($built, 'event.spotlight', 'configure', 'Configure'));
    $settled = $this->settled($built);
    $this->assertSame([], array_filter([...array_values($settled['props']), ...array_values($settled['slots'])]), 'Past the limit, nothing is built.');
    // Within it: every variant is stated, so every one is built.
    $emitter->emitFull($built, 'event.spotlight', 'configure', 'Configure');
    foreach ($this->settled($built) as $key => $variants) {
      $this->assertSame(array_fill_keys(array_keys($variants), TRUE), $variants, $key);
    }
  }

  /**
   * Tests the schema for a chosen component builds that one, past the limit.
   */
  public function testChosenComponentBuildsItAlone(): void {
    $emitter = $this->container->get('data_surface.contract_emitter');
    $budget = $this->container->get('data_surface.site_list_budget');
    $built = $this->spotlight();
    $values = ['component' => 'data_surface_examples:card'] + $built->getDefaultValues();
    $budget->withLimit(1, fn () => $emitter->emit($built, $values, 'event.spotlight', 'configure', 'Configure'));
    foreach ($this->settled($built) as $key => $variants) {
      $this->assertSame(['data_surface_examples:card'], array_keys(array_filter($variants)), $key);
    }
  }

  /**
   * Tests what a variant read is what everything read from it depends on.
   */
  public function testVariantReadAddsWhatItRead(): void {
    $built = $this->spotlight();
    $before = $built->getCacheTags();
    $this->assertEquals(CacheableMetadata::createFromObject($built), SettledCacheability::of($built), 'Nothing read, nothing added.');
    $card = $built->getDefinitions()->entry('props')->variants->variant('data_surface_examples:card')->child;
    $read = SettledCacheability::of($built)->getCacheTags();
    foreach ([...$before, ...$card->getCacheTags()] as $tag) {
      $this->assertContains($tag, $read);
    }
    $this->assertSame($before, $built->getCacheTags(), 'What the build itself read stays what it was sealed with.');
    $this->assertSame($card, $built->getDefinitions()->entry('props')->variants->variant('data_surface_examples:card')->child, 'Built once, then kept.');
  }

}
