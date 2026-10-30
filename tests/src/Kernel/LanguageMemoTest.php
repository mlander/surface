<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\Resources;
use Drupal\surface\SettledCacheability;
use Drupal\surface_examples\Resource\SpotlightResource;
use Drupal\language\Entity\ConfigurableLanguage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the request's memo keeps builds on a site with core's language module.
 *
 * The language module puts the interface language's cache context on every
 * configuration object, so every build that reads configuration carries
 * it. The memo and the build cache are keyed by that language and the
 * configuration override language, so such a build is kept all the same,
 * and a structure read from a schema is built once however many slots
 * hold it, never once per site.
 *
 * Decision: see
 * docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class LanguageMemoTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'language',
    'serialization',
    'tool',
    'surface',
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('configurable_language');
    $this->installConfig(['language', 'surface_examples']);
    ConfigurableLanguage::createFromLangcode('fr')->save();
    $this->setUpCurrentUser(admin: TRUE);
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
   * Tests builds varying by the interface language are kept and reused.
   */
  public function testBuildsVaryingByLanguageAreKept(): void {
    $resources = $this->resources();
    $context = $resources->operation(SpotlightResource::class, 'configure');
    $built = $resources->build(SpotlightResource::class, $context);
    $this->assertContains('languages:language_interface', $built->getCacheContexts(), 'Configuration carries the language context here.');

    // Within the site's list limit the full schema states every variant,
    // and a def many slots hold is built once.
    $before = $resources->buildCounts()['reused'];
    $this->container->get('surface.contract_emitter')->emitFull($built, 'event.spotlight', 'configure', 'Configure');
    $this->assertContains('languages:language_interface', SettledCacheability::of($built)->getCacheContexts());
    $this->assertGreaterThan($before, $resources->buildCounts()['reused'], 'Read structures are answered from the memo.');
    $this->assertSame($built, $resources->build(SpotlightResource::class, $context), 'The build itself is kept.');

    // The build for no account is kept in the build cache.
    $resources->buildDeclared(SpotlightResource::class, $context);
    $resources->forgetBuilds();
    $cached = $resources->buildCounts()['cached'];
    $resources->buildDeclared(SpotlightResource::class, $context);
    $this->assertSame($cached + 1, $resources->buildCounts()['cached']);

    // Another configuration language is another build.
    $this->container->get('language_manager')->setConfigOverrideLanguage(ConfigurableLanguage::load('fr'));
    $this->assertNotSame($built, $resources->build(SpotlightResource::class, $context));
  }

}
