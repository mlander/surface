<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Component\Plugin\Factory\FactoryInterface;
use Drupal\Component\Plugin\PluginManagerBase;
use Drupal\Core\Cache\MemoryBackend;
use Drupal\Core\Plugin\Factory\ContainerFactory;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\Validation\ConstraintManager;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Options\SurfaceOptions;
use Drupal\surface\Options\SurfaceOptionsResolverInterface;
use Drupal\surface\Options\SurfaceOptionsResolverManager;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Tests that reading every example's definitions asks no service.
 *
 * Every entry of every example surface (its keys, its outputs, and each
 * variants' shell) is read with DefinitionSchema::of() while:
 * - the container is one that records every service id asked of it
 *   (`\Drupal::setContainer()`, restored after): the ids asked are
 *   exactly the ones core's own typed data calls ask (ASKED);
 * - counting decorators stand in for the options resolver manager and
 *   the constraint plugin manager, and the options service is one built
 *   on them: no resolver is made or asked, no constraint is made, and no
 *   plugin definition is read.
 * The read renders no text the definitions hold: the texts with a
 *   rendering afterwards are the ones the build rendered before it
 *   (RENDERED_AT_BUILD). Projecting the same nodes through the same
 *   decorators and the recording container moves every counter, so each
 *   count of zero is a read that asked nothing.
 *
 * Decision: see
 * docs/decisions.md#a-definition-is-read-by-a-pure-function-the-projection-holds-the-request.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DefinitionReadServiceFreeTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
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
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_compliance',
    'surface_examples_workflow',
    'surface_examples_first',
    'block',
    'path_alias',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_keys',
    'text',
    'filter',
    'node',
    'surface_examples_references',
    'surface_examples_retitle',
  ];

  /**
   * The definitions read, and the texts they hold.
   */
  protected const READ = [168, 380];

  /**
   * The service ids the read asks of the container, in the order first asked.
   *
   * Each is asked by core, not by the module:
   * - `typed_data_manager`: DataDefinition::getConstraints() asks it for
   *   the default constraints of the definition's data type
   *   (TypedDataManager::getDefaultConstraints()), through
   *   `\Drupal::typedDataManager()`.
   */
  protected const ASKED = ['typed_data_manager'];

  /**
   * The texts the build renders before anything reads a node.
   *
   * The label of the catering example's `details` group: two declarations
   * of one group are compared by their rendered labels when the
   * arrangement is built (ArrangementGroup), not by the read.
   */
  protected const RENDERED_AT_BUILD = ['Details', 'Details'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
  }

  /**
   * Tests the read asks no service and renders no text.
   */
  public function testTheReadAsksNoService(): void {
    $definitions = [];
    foreach ($this->examples() as [, , $surface]) {
      foreach ([...$surface->getDefinitions()->entries(), ...$surface->getOutputDefinitions()->entries()] as $entry) {
        $definitions[] = $entry->definition;
        if ($entry->variants !== NULL) {
          $definitions[] = $entry->variants->shell;
        }
      }
    }
    $texts = [];
    $seen = [];
    foreach ($definitions as $definition) {
      static::texts($definition, $texts, $seen);
    }

    $before = array_keys(array_filter($texts, static fn (TranslatableMarkup $text): bool => static::rendering($text) !== NULL));
    $counted = [
      'constraint create()' => 0,
      'constraint definitions' => 0,
      'resolver made' => 0,
      'resolver definitions' => 0,
    ];
    $constraints = $this->countingConstraints($counted);
    $resolvers = $this->countingResolvers($counted);
    $options = new SurfaceOptions($resolvers, $constraints, $this->container->get('logger.channel.surface'));
    $this->container->set('validation.constraint', $constraints);
    $this->container->set('plugin.manager.surface_options_resolver', $resolvers);
    $this->container->set('surface.options', $options);

    $container = \Drupal::getContainer();
    $asked = [];
    $recording = static::recording($container, $asked);
    $nodes = [];
    \Drupal::setContainer($recording);
    try {
      foreach ($definitions as $definition) {
        $nodes[] = DefinitionSchema::of($definition);
      }
    }
    finally {
      \Drupal::setContainer($container);
    }
    $this->assertSame(self::ASKED, array_values(array_unique($asked)), 'The read asks the container only for what the typed data calls of core ask.');
    $this->assertSame(array_fill_keys(array_keys($counted), 0), $counted, 'The read made, asked and read nothing of either plugin manager.');
    $rendered = array_filter($texts, static fn (TranslatableMarkup $text): bool => static::rendering($text) !== NULL);
    $this->assertSame($before, array_keys($rendered), 'The read rendered no text the definitions hold.');
    $this->assertSame(self::RENDERED_AT_BUILD, array_values(array_map(static fn (TranslatableMarkup $text): string => $text->getUntranslatedString(), $rendered)), 'What the build itself rendered before the read.');
    $this->assertSame(self::READ, [count($nodes), count($texts)], 'Every entry is read, and the texts are found.');

    // The projection asks the decorators and the container, so a count
    // of zero is a read that asked nothing.
    $asked = [];
    \Drupal::setContainer($recording);
    try {
      $projection = new DefinitionSchema(\Drupal::service('surface.options'), $constraints);
      foreach ($nodes as $index => $node) {
        $projection->published($node, $definitions[$index]);
      }
    }
    finally {
      \Drupal::setContainer($container);
    }
    foreach (array_keys($counted) as $counter) {
      $this->assertGreaterThan(0, $counted[$counter], "The projection moves the counter: $counter.");
    }
    $this->assertContains('surface.options', $asked, 'The recording container records what is asked of it.');
  }

  /**
   * Builds a container that records every service id asked of it.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $inner
   *   The container it hands every call on to.
   * @param list<string> $asked
   *   Gains each id given to get() and has(), in order.
   *
   * @return \Symfony\Component\DependencyInjection\ContainerInterface
   *   The container.
   */
  protected static function recording(ContainerInterface $inner, array &$asked): ContainerInterface {
    $recording = new class($inner) implements ContainerInterface {

      /**
       * The ids asked, in order.
       *
       * @var list<string>
       */
      public array $asked = [];

      /**
       * Constructs the recorder.
       *
       * @param \Symfony\Component\DependencyInjection\ContainerInterface $inner
       *   The container it hands every call on to.
       */
      public function __construct(private readonly ContainerInterface $inner) {}

      /**
       * {@inheritdoc}
       */
      public function set(string $id, ?object $service): void {
        $this->inner->set($id, $service);
      }

      /**
       * {@inheritdoc}
       */
      public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object {
        $this->asked[] = $id;
        return $this->inner->get($id, $invalidBehavior);
      }

      /**
       * {@inheritdoc}
       */
      public function has(string $id): bool {
        $this->asked[] = $id;
        return $this->inner->has($id);
      }

      /**
       * {@inheritdoc}
       */
      public function initialized(string $id): bool {
        return $this->inner->initialized($id);
      }

      /**
       * {@inheritdoc}
       */
      public function getParameter(string $name): array|bool|string|int|float|\UnitEnum|null {
        return $this->inner->getParameter($name);
      }

      /**
       * {@inheritdoc}
       */
      public function hasParameter(string $name): bool {
        return $this->inner->hasParameter($name);
      }

      /**
       * {@inheritdoc}
       */
      public function setParameter(string $name, array|bool|string|int|float|\UnitEnum|null $value): void {
        $this->inner->setParameter($name, $value);
      }

    };
    $recording->asked = &$asked;
    return $recording;
  }

  /**
   * Builds a constraint manager that counts what it is asked.
   *
   * @param array<string, int> $counted
   *   The counts, by what is counted.
   *
   * @return \Drupal\Core\Validation\ConstraintManager
   *   The manager.
   */
  protected function countingConstraints(array &$counted): ConstraintManager {
    $manager = new class($this->container->get('container.namespaces'), $this->container->get('cache.discovery'), $this->container->get('module_handler')) extends ConstraintManager {

      /**
       * The counts, by what is counted.
       *
       * @var array<string, int>
       */
      public array $counted = [];

      /**
       * {@inheritdoc}
       */
      public function create($name, $options) {
        $this->counted['constraint create()']++;
        return parent::create($name, $options);
      }

      /**
       * {@inheritdoc}
       */
      public function getDefinitions() {
        $this->counted['constraint definitions']++;
        return parent::getDefinitions();
      }

    };
    $manager->counted = &$counted;
    return $manager;
  }

  /**
   * Builds an options resolver manager that counts what it is asked.
   *
   * SurfaceOptionsResolverManager is final, so the counters stand in for
   * its two collaborators: its plugin factory, which makes every resolver,
   * and its cache backend, which a first read of its definitions asks. A
   * fresh manager has read nothing, so a read of its definitions counts.
   *
   * @param array<string, int> $counted
   *   The counts, by what is counted.
   *
   * @return \Drupal\surface\Options\SurfaceOptionsResolverManager
   *   The manager.
   */
  protected function countingResolvers(array &$counted): SurfaceOptionsResolverManager {
    $cache = new class($this->container->get('datetime.time')) extends MemoryBackend {

      /**
       * The counts, by what is counted.
       *
       * @var array<string, int>
       */
      public array $counted = [];

      /**
       * Counts a read, then reads.
       *
       * @param string $cid
       *   The cache id.
       * @param bool $allow_invalid
       *   Whether an invalid item is returned.
       *
       * @return object|false
       *   The item, or FALSE.
       */
      public function get($cid, $allow_invalid = FALSE) {
        $this->counted['resolver definitions']++;
        return parent::get($cid, $allow_invalid);
      }

    };
    $cache->counted = &$counted;
    $manager = new SurfaceOptionsResolverManager($this->container->get('container.namespaces'), $cache, $this->container->get('module_handler'));
    $factory = new class(new ContainerFactory($manager, SurfaceOptionsResolverInterface::class)) implements FactoryInterface {

      /**
       * The counts, by what is counted.
       *
       * @var array<string, int>
       */
      public array $counted = [];

      /**
       * Constructs the counter.
       *
       * @param \Drupal\Component\Plugin\Factory\FactoryInterface $inner
       *   The factory it hands every call on to.
       */
      public function __construct(private readonly FactoryInterface $inner) {}

      /**
       * {@inheritdoc}
       */
      public function createInstance($plugin_id, array $configuration = []) {
        $this->counted['resolver made']++;
        return $this->inner->createInstance($plugin_id, $configuration);
      }

    };
    $factory->counted = &$counted;
    (new \ReflectionProperty(PluginManagerBase::class, 'factory'))->setValue($manager, $factory);
    return $manager;
  }

  /**
   * Collects the texts a definition holds, at every depth.
   *
   * Its label and description, and every TranslatableMarkup in what its
   * array holds (constraint options, settings, the module's metadata),
   * its properties' and its item's, and their arguments.
   *
   * @param mixed $value
   *   A definition, or a value one holds.
   * @param array<int, \Drupal\Core\StringTranslation\TranslatableMarkup> $texts
   *   The texts so far, by object id.
   * @param array<int, true> $seen
   *   The objects walked, by object id.
   */
  protected static function texts(mixed $value, array &$texts, array &$seen): void {
    if (is_array($value)) {
      foreach ($value as $item) {
        static::texts($item, $texts, $seen);
      }
      return;
    }
    if (!is_object($value) || isset($seen[spl_object_id($value)])) {
      return;
    }
    $seen[spl_object_id($value)] = TRUE;
    if ($value instanceof TranslatableMarkup) {
      $texts[spl_object_id($value)] = $value;
      static::texts($value->getArguments(), $texts, $seen);
      return;
    }
    if ($value instanceof DataDefinitionInterface) {
      $own = $value instanceof DataDefinition ? $value->toArray() : [
        $value->getLabel(),
        $value->getDescription(),
        $value->getSettings(),
        $value->getConstraints(),
      ];
      static::texts($own, $texts, $seen);
      if ($value instanceof ListDataDefinitionInterface) {
        static::texts($value->getItemDefinition(), $texts, $seen);
      }
      elseif ($value instanceof ComplexDataDefinitionInterface) {
        static::texts($value->getPropertyDefinitions(), $texts, $seen);
      }
      return;
    }
    static::texts(get_object_vars($value), $texts, $seen);
  }

  /**
   * Reads the rendering a text keeps, if it has one.
   *
   * @param \Drupal\Core\StringTranslation\TranslatableMarkup $text
   *   The text.
   *
   * @return string|null
   *   What it rendered to, or NULL when it has not been rendered.
   */
  protected static function rendering(TranslatableMarkup $text): ?string {
    $kept = (new \ReflectionProperty(TranslatableMarkup::class, 'translatedMarkup'))->getValue($text);
    return is_string($kept) ? $kept : NULL;
  }

}
