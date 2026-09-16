<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\MemoryStorage;
use Drupal\Core\Form\ToConfig;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\DataSurface;
use Drupal\data_surface\DefinitionMap;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceBuilderInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Pipeline\PreparedValues;
use Drupal\data_surface\Pipeline\TargetViolationsException;
use Drupal\data_surface\Target\CompositeTarget;
use Drupal\data_surface\Target\ConfigEntityTarget;
use Drupal\data_surface\Target\ConfigObjectTarget;
use Drupal\data_surface\Target\SchemaViolations;
use Drupal\data_surface\Target\StateTarget;
use Drupal\data_surface_test\RecordingTarget;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\EventDispatcher\EventDispatcher;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the config object, config entity and composite targets.
 *
 * The three destinations the pipeline needs before any real form can go
 * through it: a simple config object, a config entity, and several of
 * those at once. What each one has to prove is the same: it reads its
 * own storage back in surface shape, it shapes values without writing,
 * it folds the config schema's opinion into the same violation shape the
 * surface uses, and it writes only when told to.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class TargetsTest extends DataSurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'config_test',
    'data_surface',
    'data_surface_target_test',
  ];

  /**
   * The state key the state target writes to.
   */
  protected const STATE_KEY = 'data_surface.targets';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installConfig(['system']);
    // A real site's system.site carries a site identifier and a name,
    // and the schema says so. The config shipped with the module leaves
    // both empty, which is not much like the thing under test.
    $this->config('system.site')
      ->set('uuid', $this->container->get('uuid')->generate())
      ->set('name', 'Drupal')
      ->set('slogan', '')
      ->save();
  }

  /**
   * Gets the typed configuration manager.
   *
   * @return \Drupal\Core\Config\TypedConfigManagerInterface
   *   The typed configuration manager.
   */
  protected function typedConfig() {
    return $this->container->get('config.typed');
  }

  /**
   * Builds the surface the config object target is tested against.
   *
   * A site name and a slogan, both of which the config schema describes
   * as labels, so the storage has an opinion about them that the surface
   * itself never states.
   */
  protected function siteSurface(): DataSurfaceInterface {
    $definitions = [
      'name' => DataDefinition::create('string')->setLabel('Site name')->setRequired(TRUE),
      'slogan' => DataDefinition::create('string')->setLabel('Slogan'),
    ];
    DefinitionMetadata::setDefaultValue($definitions['name'], 'Drupal');
    DefinitionMetadata::setDefaultValue($definitions['slogan'], '');
    return new DataSurface(DefinitionMap::fromArrays(definitions: $definitions));
  }

  /**
   * Builds a config object target pointed at the active system.site.
   *
   * @param array $to_config
   *   Callables turning surface values into stored values.
   *
   * @return \Drupal\data_surface\Target\ConfigObjectTarget
   *   The target.
   */
  protected function siteTarget(array $to_config = []): ConfigObjectTarget {
    return new ConfigObjectTarget(
      $this->config('system.site'),
      ['name', 'slogan'],
      $this->typedConfig(),
      $to_config,
    );
  }

  /**
   * Tests reading, preparing and writing one simple config object.
   */
  public function testConfigObjectRoundTrip(): void {
    $surface = $this->siteSurface();
    $target = $this->siteTarget();

    $this->assertSame(['name' => 'Drupal', 'slogan' => ''], $target->load($surface));

    $result = $this->pipeline()->submit($surface, ['name' => 'Surface site', 'slogan' => 'One way in'], $target);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $this->assertInstanceOf(Config::class, $result->prepared->artifact);
    $this->assertSame([], $result->prepared->dependencies);

    $stored = $this->container->get('config.factory')->get('system.site');
    $this->assertSame('Surface site', $stored->get('name'));
    $this->assertSame('One way in', $stored->get('slogan'));
    // Everything the surface says nothing about is still there.
    $this->assertSame('/user/login', $stored->get('page.front'));

    // And a second target reads back exactly what the first wrote.
    $this->assertSame(
      ['name' => 'Surface site', 'slogan' => 'One way in'],
      $this->siteTarget()->load($surface),
    );
  }

  /**
   * Tests a to_config callable, including the one that clears a key.
   */
  public function testConfigObjectCallables(): void {
    $surface = $this->siteSurface();
    $shout = static fn (mixed $value): mixed => is_string($value) && $value !== ''
      ? strtoupper($value)
      : ToConfig::DeleteKey;

    $this->pipeline()->submit(
      $surface,
      ['name' => 'Surface site', 'slogan' => 'one way in'],
      $this->siteTarget(['slogan' => $shout]),
    );
    $this->assertSame('ONE WAY IN', $this->container->get('config.factory')->get('system.site')->get('slogan'));

    // An empty slogan asks for the key to go away rather than to be
    // stored as an empty string.
    $this->pipeline()->submit(
      $surface,
      ['name' => 'Surface site', 'slogan' => ''],
      $this->siteTarget(['slogan' => $shout]),
    );
    $raw = $this->container->get('config.factory')->get('system.site')->getRawData();
    $this->assertArrayNotHasKey('slogan', $raw);
    $this->assertArrayHasKey('name', $raw);

    // A callable may also decline to say anything at all.
    $this->pipeline()->submit(
      $surface,
      ['name' => 'Surface site', 'slogan' => 'ignored'],
      $this->siteTarget(['slogan' => static fn (): ToConfig => ToConfig::NoOp]),
    );
    $this->assertArrayNotHasKey('slogan', $this->container->get('config.factory')->get('system.site')->getRawData());
  }

  /**
   * Tests the config schema refusing a value the surface allowed.
   */
  public function testConfigObjectSchemaViolations(): void {
    $surface = $this->siteSurface();
    $target = $this->siteTarget();
    // A site name is a label, and a label may not span lines. The
    // surface says nothing about that: it is the storage's own opinion,
    // and it arrives through the target.
    $values = $this->pipeline()->accept($surface, ['name' => "Two\nlines", 'slogan' => 'Fine']);
    $this->assertCount(0, $this->pipeline()->validate($surface, $values));

    try {
      $this->pipeline()->prepare($surface, $values, $target);
      $this->fail('Expected a TargetViolationsException.');
    }
    catch (TargetViolationsException $e) {
      $violations = $e->getViolations();
      $this->assertSame(['name'], $violations->keys());
      $this->assertSame('', $violations->byKey('name')[0]->path);
      $this->assertStringContainsString('multiple lines', (string) $violations->byKey('name')[0]->message);
    }

    // Nothing was written: prepare only shapes.
    $this->assertSame('Drupal', $this->container->get('config.factory')->get('system.site')->get('name'));
  }

  /**
   * Tests that shaping does not dirty the config object it was handed.
   *
   * The config object a target is given is nearly always the one the
   * config factory has cached and the rest of the request is reading, so
   * a rehearsal that set values on it would be visible site-wide and any
   * later unrelated save of it would persist a submission nobody
   * accepted.
   */
  public function testConfigObjectDryRunLeavesTheCallersConfigAlone(): void {
    $surface = $this->siteSurface();
    $config = $this->config('system.site');
    $target = new ConfigObjectTarget($config, ['name', 'slogan'], $this->typedConfig());

    $result = $this->pipeline()->submit($surface, ['name' => 'Rehearsed', 'slogan' => 'Not yet'], $target, TRUE);

    $this->assertTrue($result->isValid());
    $this->assertFalse($result->committed);
    $this->assertSame('Rehearsed', $result->prepared->artifact->get('name'));
    // The caller's object, and the active storage behind it, are as they
    // were.
    $this->assertSame('Drupal', $config->get('name'));
    $this->assertSame('Drupal', $this->container->get('config.factory')->get('system.site')->get('name'));
  }

  /**
   * Tests a foreign violation not blocking a write it has nothing to do with.
   *
   * The probed bug: system.site's front page is a required path, so a
   * config object holding an empty one refused every submission of the
   * site name, naming a key the surface never declared.
   */
  public function testConfigObjectIgnoresForeignViolations(): void {
    $surface = $this->siteSurface();
    // The site's notification address is not something this surface
    // declares, and it is not valid.
    $this->config('system.site')->set('mail', 'not an address')->save();

    $result = $this->pipeline()->submit($surface, ['name' => 'Unaffected', 'slogan' => ''], $this->siteTarget());

    $this->assertCount(0, $result->violations);
    $this->assertTrue($result->committed);
    $this->assertSame('Unaffected', $this->container->get('config.factory')->get('system.site')->get('name'));

    // Asked for the whole picture, the schema still has its say.
    $everything = SchemaViolations::collect(
      $this->typedConfig(),
      'system.site',
      $this->container->get('config.factory')->get('system.site')->getRawData(),
      ['name' => 'name', 'slogan' => 'slogan'],
      FALSE,
    );
    $this->assertContains('mail', $everything->keys());
  }

  /**
   * Tests a dry run into a config object built on a memory storage.
   *
   * The one genuine per-object storage swap the config system offers: a
   * Config holds the storage it was given, so this commit is a real
   * write that the active storage never hears about. The rehearsal gets
   * its own event dispatcher too, so the save it performs does not reach
   * the listeners the live config factory keeps.
   */
  public function testConfigObjectDryRunIntoMemoryStorage(): void {
    $surface = $this->siteSurface();
    $active = $this->container->get('config.storage');
    $storage = new MemoryStorage();
    $config = new Config('system.site', $storage, new EventDispatcher(), $this->typedConfig());
    $config->initWithData($active->read('system.site'));
    $target = new ConfigObjectTarget($config, ['name', 'slogan'], $this->typedConfig());

    $result = $this->pipeline()->submit($surface, ['name' => 'Rehearsal', 'slogan' => 'Not for real'], $target);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $this->assertSame('Rehearsal', $storage->read('system.site')['name']);
    // The active storage was never touched, and neither was the view of
    // it the rest of the site reads.
    $this->assertSame('Drupal', $active->read('system.site')['name']);
    $this->assertSame('Drupal', $this->container->get('config.factory')->get('system.site')->get('name'));
  }

  /**
   * Builds the surface the config entity target is tested against.
   *
   * Three of the test entity's own properties, all of which the entity
   * type exports and the config schema describes.
   */
  protected function entitySurface(): DataSurfaceInterface {
    return $this->entityBuilder()->seal();
  }

  /**
   * Builds the same surface with a third party's setting mounted on it.
   *
   * The mount is what turns into a 'third_party_settings' map holding
   * one map per provider, which is the shape the entity already stores
   * third party settings in.
   */
  protected function mountedSurface(): DataSurfaceInterface {
    $builder = $this->entityBuilder();
    $builder->setThirdPartyDefinition(
      'data_surface_target_test',
      'note',
      DataDefinition::create('string')->setLabel('Note'),
      'nothing yet',
    );
    return $builder->seal();
  }

  /**
   * Builds the unsealed surface both entity surfaces start from.
   *
   * @return \Drupal\data_surface\DataSurfaceBuilderInterface
   *   The builder.
   */
  protected function entityBuilder(): DataSurfaceBuilderInterface {
    $builder = new DataSurfaceBuilder([
      'label' => DataDefinition::create('string')->setLabel('Label')->setRequired(TRUE),
      'weight' => DataDefinition::create('integer')->setLabel('Weight')->setRequired(TRUE),
      'style' => DataDefinition::create('string')->setLabel('Style'),
    ]);
    $builder->setDefault('label', 'Example');
    $builder->setDefault('weight', 0);
    $builder->setDefault('style', '');
    return $builder;
  }

  /**
   * Creates the config entity the entity target is tested against.
   *
   * @param string $id
   *   The entity identifier.
   *
   * @return \Drupal\Core\Config\Entity\ConfigEntityInterface
   *   The unsaved entity.
   */
  protected function testEntity(string $id) {
    return $this->container->get('entity_type.manager')
      ->getStorage('config_test')
      ->create(['id' => $id, 'label' => 'Placeholder']);
  }

  /**
   * Tests creating and then updating one config entity.
   */
  public function testConfigEntityCreateAndUpdate(): void {
    $surface = $this->entitySurface();
    $entity = $this->testEntity('surface_one');
    $map = ['label', 'weight', 'style'];
    $target = new ConfigEntityTarget($entity, $map, $this->typedConfig());

    // What the target reads off a brand new entity is what the pipeline
    // merges the input over, which is why the placeholder label is not
    // the surface default here.
    $this->assertSame(
      ['label' => 'Placeholder', 'weight' => 0, 'style' => NULL],
      $target->load($surface),
    );

    $result = $this->pipeline()->submit($surface, [
      'label' => 'First label',
      'weight' => '3',
      'style' => 'wide',
    ], $target);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    // The artifact is a copy, and the copy is what was saved: the entity
    // the caller handed over is never written to.
    $this->assertNotSame($entity, $result->prepared->artifact);
    $this->assertSame('First label', $result->prepared->artifact->label());
    $this->assertSame('Placeholder', $entity->label());

    $storage = $this->container->get('entity_type.manager')->getStorage('config_test');
    $storage->resetCache();
    $saved = $storage->load('surface_one');
    $this->assertSame('First label', $saved->label());
    $this->assertSame(3, $saved->get('weight'));
    $this->assertSame('wide', $saved->get('style'));

    // A partial update over the saved entity leaves the rest alone.
    $update_target = new ConfigEntityTarget($saved, $map, $this->typedConfig());
    $updated = $this->pipeline()->submit($surface, ['weight' => '9'], $update_target);
    $this->assertTrue($updated->isValid());
    $storage->resetCache();
    $reloaded = $storage->load('surface_one');
    $this->assertSame(9, $reloaded->get('weight'));
    $this->assertSame('First label', $reloaded->label());
    $this->assertSame('wide', $reloaded->get('style'));
  }

  /**
   * Tests the third party mount reaching the entity, and coming back.
   *
   * The provider is a module of its own for a reason worth writing down:
   * core expects a third party to describe its own settings, at
   * CONFIG_NAME.third_party.PROVIDER, and a mapping nothing describes
   * fails the supported keys check on the way in and the schema check on
   * the way to storage. So the mount is only as legal as the schema its
   * provider ships.
   */
  public function testConfigEntityThirdPartySettings(): void {
    $surface = $this->mountedSurface();
    $entity = $this->testEntity('surface_two');
    $map = ['label', 'weight', 'style', 'third_party_settings'];
    $target = new ConfigEntityTarget($entity, $map, $this->typedConfig());

    $result = $this->pipeline()->submit($surface, [
      'label' => 'Second label',
      'weight' => '1',
      'third_party_settings' => ['data_surface_target_test' => ['note' => 'Written by a third party']],
    ], $target);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    // Storing a third party's settings is what makes the entity depend
    // on that third party, and the target hands the answer back rather
    // than leaving the caller to ask the entity again.
    $this->assertSame(['module' => ['data_surface_target_test']], $result->prepared->dependencies);

    $storage = $this->container->get('entity_type.manager')->getStorage('config_test');
    $storage->resetCache();
    $saved = $storage->load('surface_two');
    $this->assertSame('Written by a third party', $saved->getThirdPartySetting('data_surface_target_test', 'note'));

    // Loading the saved entity gives the surface shape back, mount and
    // all, so a partial update leaves the third party's settings alone.
    $update_target = new ConfigEntityTarget($saved, $map, $this->typedConfig());
    $this->assertSame([
      'label' => 'Second label',
      'weight' => 1,
      'style' => NULL,
      'third_party_settings' => ['data_surface_target_test' => ['note' => 'Written by a third party']],
    ], $update_target->load($surface));

    $this->pipeline()->submit($surface, ['weight' => '5'], $update_target);
    $storage->resetCache();
    $reloaded = $storage->load('surface_two');
    $this->assertSame(5, $reloaded->get('weight'));
    $this->assertSame('Written by a third party', $reloaded->getThirdPartySetting('data_surface_target_test', 'note'));
  }

  /**
   * Tests the config schema refusing a value bound for an entity.
   */
  public function testConfigEntitySchemaViolations(): void {
    $surface = $this->entitySurface();
    $entity = $this->testEntity('surface_three');
    $map = ['label', 'weight', 'style'];
    $target = new ConfigEntityTarget($entity, $map, $this->typedConfig());
    $values = $this->pipeline()->accept($surface, ['label' => "Two\nlines", 'weight' => '0']);
    $this->assertCount(0, $this->pipeline()->validate($surface, $values));

    try {
      $this->pipeline()->prepare($surface, $values, $target);
      $this->fail('Expected a TargetViolationsException.');
    }
    catch (TargetViolationsException $e) {
      $this->assertSame(['label'], $e->getViolations()->keys());
      $this->assertSame('', $e->getViolations()->byKey('label')[0]->path);
    }

    // Preparing wrote nothing: the artifact is the unsaved entity.
    $this->assertTrue($entity->isNew());

    // The same values go through once the target is told not to ask the
    // schema, which is the escape hatch for storage nobody has described
    // yet.
    $lenient = new ConfigEntityTarget($entity, $map, $this->typedConfig(), FALSE);
    $prepared = $this->pipeline()->prepare($surface, $values, $lenient);
    $this->assertInstanceOf(PreparedValues::class, $prepared);
    $this->assertNotSame($entity, $prepared->artifact);
    $this->assertSame("Two\nlines", $prepared->artifact->label());
  }

  /**
   * Tests that nothing a dry run shapes reaches the caller's entity.
   *
   * The probed bug this is the regression test for: preparing set the
   * values on the entity the caller handed over, so a rehearsal was
   * visible to everything else holding that entity, and the next
   * unrelated save of it persisted a submission nobody accepted.
   */
  public function testConfigEntityDryRunLeavesTheCallerEntityAlone(): void {
    $surface = $this->entitySurface();
    $entity = $this->testEntity('surface_dry_run');
    $entity->set('weight', 7);
    $target = new ConfigEntityTarget($entity, ['label', 'weight', 'style'], $this->typedConfig());

    $result = $this->pipeline()->submit($surface, [
      'label' => 'Rehearsed',
      'weight' => '42',
      'style' => 'wide',
    ], $target, TRUE);

    $this->assertTrue($result->isValid());
    $this->assertFalse($result->committed);
    // The artifact holds the submission.
    $this->assertSame('Rehearsed', $result->prepared->artifact->label());
    $this->assertSame(42, $result->prepared->artifact->get('weight'));
    // The caller's entity holds exactly what it held before.
    $this->assertSame('Placeholder', $entity->label());
    $this->assertSame(7, $entity->get('weight'));
    $this->assertNull($entity->get('style'));

    // And saving that entity later stores what the caller put in it,
    // not the rehearsal.
    $entity->save();
    $storage = $this->container->get('entity_type.manager')->getStorage('config_test');
    $storage->resetCache();
    $this->assertSame('Placeholder', $storage->load('surface_dry_run')->label());
  }

  /**
   * Tests that a schema refusal leaves the caller's entity alone too.
   */
  public function testConfigEntitySchemaRefusalLeavesTheCallersEntityAlone(): void {
    $surface = $this->entitySurface();
    $entity = $this->testEntity('surface_refused');
    $target = new ConfigEntityTarget($entity, ['label', 'weight', 'style'], $this->typedConfig());
    $values = $this->pipeline()->accept($surface, ['label' => "Two\nlines", 'style' => 'wide']);

    try {
      $this->pipeline()->prepare($surface, $values, $target);
      $this->fail('Expected a TargetViolationsException.');
    }
    catch (TargetViolationsException) {
      // The refusal is asserted elsewhere; what matters here is what it
      // left behind.
    }

    $this->assertSame('Placeholder', $entity->label());
    $this->assertNull($entity->get('style'));
  }

  /**
   * Tests a foreign violation not blocking a write it has nothing to do with.
   *
   * A config schema validates the whole object, so an entity carrying a
   * violation under a key the surface never declared used to refuse a
   * submission that had nothing to do with it, naming a key the caller
   * cannot see. Only the paths this target writes are reported.
   */
  public function testConfigEntityIgnoresForeignViolations(): void {
    // A surface that says nothing about the label, so neither the map
    // nor the surface claims that path.
    $builder = new DataSurfaceBuilder([
      'style' => DataDefinition::create('string')->setLabel('Style'),
    ]);
    $builder->setDefault('style', '');
    $surface = $builder->seal();
    $entity = $this->testEntity('surface_foreign');
    // The entity's label is a label, and a label may not span lines.
    $entity->set('label', "Two\nlines");
    $target = new ConfigEntityTarget($entity, ['style'], $this->typedConfig());

    $result = $this->pipeline()->submit($surface, ['style' => 'wide'], $target);

    $this->assertCount(0, $result->violations);
    $this->assertTrue($result->committed);

    // Asked for the whole picture, the schema still has its say.
    $everything = SchemaViolations::collect(
      $this->typedConfig(),
      $entity->getConfigDependencyName(),
      $result->prepared->artifact->toArray(),
      ['style' => 'style'],
      FALSE,
    );
    $this->assertContains('label', $everything->keys());
  }

  /**
   * Tests the map form that names a setter the entity already offers.
   *
   * A string rather than a closure, and the same spelling core's config
   * actions use, so a key a surface writes is a key a recipe can write.
   */
  public function testConfigEntityMethodMap(): void {
    // The config test entity keeps one property behind a setter, and
    // that setter carries core's own #[ActionMethod].
    $builder = new DataSurfaceBuilder([
      'protected_property' => DataDefinition::create('string')
        ->setLabel('Protected property')
        ->setRequired(TRUE),
    ]);
    $builder->setDefault('protected_property', 'unset');
    $surface = $builder->seal();
    $entity = $this->testEntity('surface_method');
    $target = new ConfigEntityTarget(
      $entity,
      ['protected_property' => [ConfigEntityTarget::METHOD => 'setProtectedProperty']],
      $this->typedConfig(),
    );

    $result = $this->pipeline()->submit($surface, ['protected_property' => 'Through a setter'], $target);

    $this->assertTrue($result->committed);
    $storage = $this->container->get('entity_type.manager')->getStorage('config_test');
    $storage->resetCache();
    $this->assertSame('Through a setter', $storage->load('surface_method')->getProtectedProperty());

    // A method the entity does not have is a mistake worth naming.
    $broken = new ConfigEntityTarget(
      $this->testEntity('surface_method_broken'),
      ['protected_property' => [ConfigEntityTarget::METHOD => 'setNothing']],
      $this->typedConfig(),
    );
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('setNothing');
    $broken->prepare($surface, ['protected_property' => 'Nowhere']);
  }

  /**
   * Tests that a load() after a commit reads what was written.
   */
  public function testConfigEntityReadsBackAfterCommit(): void {
    $surface = $this->entitySurface();
    $target = new ConfigEntityTarget($this->testEntity('surface_read_back'), ['label', 'weight', 'style'], $this->typedConfig());

    $this->pipeline()->submit($surface, ['label' => 'Written', 'weight' => '6'], $target);

    $this->assertSame('Written', $target->load($surface)['label']);
    $this->assertSame(6, $target->load($surface)['weight']);
  }

  /**
   * Tests one value set reaching two destinations, in order.
   */
  public function testCompositeTarget(): void {
    $surface = $this->siteSurface();
    $state = $this->container->get('state');
    $composite = new CompositeTarget([
      [$this->siteTarget(), ['name']],
      [new StateTarget($state, self::STATE_KEY), ['slogan']],
    ]);

    // Loading is the two children's answers side by side, each child
    // speaking only for the keys it owns.
    $state->set(self::STATE_KEY, ['slogan' => 'From state']);
    $this->assertSame(['name' => 'Drupal', 'slogan' => 'From state'], $composite->load($surface));

    $result = $this->pipeline()->submit($surface, ['name' => 'Both places', 'slogan' => 'Said once'], $composite);

    $this->assertTrue($result->isValid());
    $this->assertTrue($result->committed);
    $this->assertSame('Both places', $this->container->get('config.factory')->get('system.site')->get('name'));
    // The state child was given only the key it was listed with.
    $this->assertSame(['slogan' => 'Said once'], $state->get(self::STATE_KEY));

    // The artifact is one prepared set per child, in the listed order.
    $this->assertCount(2, $result->prepared->artifact);
    $this->assertInstanceOf(Config::class, $result->prepared->artifact[0]->artifact);
    $this->assertSame(['slogan' => 'Said once'], $result->prepared->artifact[1]->artifact);
  }

  /**
   * Tests that a surface key no child stores is refused.
   *
   * The silent failure this replaces: the key was accepted, validated,
   * reported committed, and never written anywhere.
   */
  public function testCompositeRefusesUnclaimedKeys(): void {
    $composite = new CompositeTarget([
      [new StateTarget($this->container->get('state'), self::STATE_KEY), ['name']],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('No child of this composite target stores the slogan surface key');
    $composite->prepare($this->siteSurface(), ['name' => 'Alone', 'slogan' => 'Nowhere']);
  }

  /**
   * Tests that a surface key two children store is refused.
   *
   * The other silent failure: the key was written twice, to two
   * destinations that disagree the moment either is edited on its own.
   */
  public function testCompositeRefusesDoubleClaimedKeys(): void {
    $state = $this->container->get('state');
    $composite = new CompositeTarget([
      [$this->siteTarget(), ['name', 'slogan']],
      [new StateTarget($state, self::STATE_KEY), ['slogan']],
    ]);

    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage('More than one child of this composite target stores the slogan surface key');
    $composite->load($this->siteSurface());
  }

  /**
   * Tests that a key a child names and the surface does not is allowed.
   *
   * A destination may legitimately be told where a key would go before
   * any surface mounts one there, which is how the node type demo names
   * the third party settings mount nothing has used yet.
   */
  public function testCompositeAllowsUndeclaredKeys(): void {
    $composite = new CompositeTarget([
      [$this->siteTarget(), ['name', 'slogan', 'third_party_settings']],
    ]);

    $prepared = $composite->prepare($this->siteSurface(), ['name' => 'Fine', 'slogan' => '']);
    $this->assertCount(1, $prepared->artifact);
  }

  /**
   * Tests a composite commit happening in the order the children were listed.
   */
  public function testCompositeCommitOrder(): void {
    $surface = $this->siteSurface();
    /** @var \ArrayObject<int, string> $log */
    $log = new \ArrayObject();
    $first = $this->recordingTarget('first', $log);
    $second = $this->recordingTarget('second', $log);
    $composite = new CompositeTarget([[$first, ['name']], [$second, ['slogan']]]);

    $prepared = $composite->prepare($surface, ['name' => 'Ordered', 'slogan' => '']);
    $this->assertSame(['first prepare', 'second prepare'], $log->getArrayCopy());
    // Every child's dependencies end up in one list.
    $this->assertSame(['module' => ['first', 'second']], $prepared->dependencies);

    $composite->commit($prepared);
    $this->assertSame(
      ['first prepare', 'second prepare', 'first commit', 'second commit'],
      $log->getArrayCopy(),
    );
    // Each child was given exactly the keys it was listed with.
    $this->assertSame(['name' => 'Ordered'], $first->received);
    $this->assertSame(['slogan' => ''], $second->received);
  }

  /**
   * Tests the one child shorthand, which owns every key.
   */
  public function testCompositeBareChildOwnsEverything(): void {
    /** @var \ArrayObject<int, string> $log */
    $log = new \ArrayObject();
    $only = $this->recordingTarget('only', $log);

    $composite = new CompositeTarget([$only]);
    $composite->prepare($this->siteSurface(), ['name' => 'Everything', 'slogan' => 'Mine']);

    $this->assertSame(['name' => 'Everything', 'slogan' => 'Mine'], $only->received);
  }

  /**
   * Builds a target that writes nothing and records what it was asked.
   *
   * @param string $name
   *   The name the target records itself under.
   * @param \ArrayObject<int, string> $log
   *   The shared log every recording target appends to.
   *
   * @return \Drupal\data_surface_test\RecordingTarget
   *   The target.
   */
  protected function recordingTarget(string $name, \ArrayObject $log): RecordingTarget {
    return new RecordingTarget($name, $log);
  }

}
