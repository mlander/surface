<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Contract\Schemas;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Validation\PortableValidator;
use Drupal\surface_values_test\HandBuiltSurfaces;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;
use PortableSchema\Registry;
use PortableSchema\Validator;

/**
 * Tests a document compiled alone is held as over the site's registry.
 *
 * What is walked: every declaration of every example module WALKED names,
 * in each operation that needs no subject and is no default read, or
 * alone where it declares none (ExampleSurfacesTrait::examples()), the
 * test surfaces of surface_values_test, and HandBuiltSurfaces::surface(),
 * all built for an administrator. For each: its defaults, and value sets
 * the document refuses (refused()). Each value set's validation document
 * is held twice by PortableValidator::held(): once compiled as the
 * validator compiles it, once compiled as it was before
 * reachesOutside() (a clone of `surface.portable_registry` with the
 * document added). The refusals, their places, codes, params and
 * messages, or what was thrown, are the same.
 *
 * @see docs/decisions.md#a-document-that-reaches-nothing-else-is-compiled-alone
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class CompiledAloneParityTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use UserCreationTrait;

  /**
   * The example modules whose declarations are walked, SecretsParityTest's.
   */
  protected const WALKED = [
    'surface_examples',
    'surface_examples_address',
    'surface_examples_block',
    'surface_examples_classic',
    'surface_examples_compliance',
    'surface_examples_component_block',
    'surface_examples_entity',
    'surface_examples_extras',
    'surface_examples_field',
    'surface_examples_first',
    'surface_examples_keys',
    'surface_examples_node_type',
    'surface_examples_node_type_tool',
    'surface_examples_performance',
    'surface_examples_plugins',
    'surface_examples_react',
    'surface_examples_references',
    'surface_examples_retitle',
    'surface_examples_workflow',
  ];

  /**
   * The top keys of a data surface given refused values, at most.
   */
  protected const KEYS = 4;

  /**
   * The flags PortableValidator encodes a document with for its hash.
   */
  protected const ENCODING = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;

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
    'address',
    'surface_examples_address',
    'surface_examples_classic',
    'surface_examples_entity',
    'surface_examples_plugins',
    'surface_examples_extras',
    'surface_examples_field',
    'surface_examples_node_type',
    'surface_examples_node_type_tool',
    'surface_examples_performance',
    'surface_examples_react',
    'surface_resource_test',
    'surface_values_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->setUpExamples();
    $this->installConfig(['field', 'surface_examples_keys', 'surface_examples_entity', 'surface_resource_test']);
  }

  /**
   * Tests both compiles refuse the same, with the same messages.
   */
  public function testCompiledAloneHoldsAsTheSiteRegistry(): void {
    $validator = $this->container->get('surface.portable_validator');
    $this->assertInstanceOf(PortableValidator::class, $validator);
    $emitter = $this->container->get('surface.contract_emitter');
    $pipeline = $this->pipeline();
    $surfaces = [];
    foreach ($this->examples(FALSE, [...self::WALKED, 'surface_values_test']) as [$id, $operation, $surface]) {
      $surfaces[$id . ($operation === NULL ? '' : '--' . $operation)] = $surface;
    }
    $surfaces[HandBuiltSurfaces::ID] = HandBuiltSurfaces::surface();
    $counts = ['surfaces' => 0, 'value sets' => 0, 'alone' => 0, 'refusals' => 0];
    $differences = [];
    foreach ($surfaces as $name => $surface) {
      $counts['surfaces']++;
      foreach (['defaults' => $surface->getDefaultValues()] + static::refused($surface) as $set => $values) {
        $schema = $emitter->emit($surface, $values, 'surface.validate')->schema();
        $held = [];
        [$hash, $overSite] = $this->compiledOverSite($schema);
        foreach (['alone' => FALSE, 'site registry' => TRUE] as $way => $site) {
          $this->setCompiled($validator, $site ? [$hash => $overSite] : []);
          $provider = $validator->provider($surface, $values, $pipeline);
          try {
            $said = static fn ($found): array => [$found->path, $found->code, $found->params, (string) $found->message];
            $held[$way] = array_map($said, $validator->held($schema, $provider->document(), $provider));
          }
          catch (\Throwable $thrown) {
            $held[$way] = ['thrown' => get_class($thrown) . ': ' . $thrown->getMessage()];
          }
          // The document was held by the compile made for this way. A
          // refiner's own check may compile other documents beside it.
          $kept = $this->compiledOf($validator)[$hash] ?? NULL;
          $this->assertNotNull($kept, "$name, $set, $way");
          if ($site) {
            $this->assertSame($overSite, $kept, "$name, $set: the document was compiled again");
          }
          elseif ($kept[3]->ids() === [$kept[1]]) {
            $counts['alone']++;
          }
        }
        $counts['value sets']++;
        $counts['refusals'] += isset($held['alone']['thrown']) ? 0 : count($held['alone']);
        if ($held['alone'] !== $held['site registry']) {
          $differences["$name, $set"] = $held;
        }
      }
    }
    $this->assertSame([], $differences);
    // The walk reached the examples, each was compiled alone, and values
    // were refused.
    $this->assertGreaterThan(40, $counts['surfaces']);
    $this->assertSame($counts['value sets'], $counts['alone']);
    $this->assertGreaterThan($counts['value sets'], $counts['refusals']);
  }

  /**
   * Builds value sets a data surface's document refuses.
   *
   * For each of the first KEYS top keys that hold a default: a value of
   * another type, and one past any bound (a long string, a large
   * negative number); and one set with each of those keys of another
   * type.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   *
   * @return array<string, array>
   *   The values, by a name for the set.
   */
  protected static function refused(SurfaceInterface $surface): array {
    $defaults = $surface->getDefaultValues();
    $sets = [];
    $all = $defaults;
    foreach (array_slice($defaults, 0, self::KEYS, TRUE) as $key => $default) {
      [$other, $past] = match (TRUE) {
        is_string($default) => [12345, str_repeat('x', 3000)],
        is_int($default), is_float($default) => ['not a number', -1.0E12],
        is_bool($default) => ['maybe', NULL],
        is_array($default) => ['x', NULL],
        default => [['x' => [1]], NULL],
      };
      $sets["$key of another type"] = [$key => $other] + $defaults;
      $all[$key] = $other;
      if ($past !== NULL) {
        $sets["$key past its bounds"] = [$key => $past] + $defaults;
      }
    }
    if ($sets !== []) {
      $sets['each of another type'] = $all;
    }
    return $sets;
  }

  /**
   * Compiles a document as the validator did before it could compile alone.
   *
   * @param \PortableSchema\Builder\Schema $schema
   *   The document.
   *
   * @return array{0: string, 1: array}
   *   The document's hash, and what PortableValidator keeps for it: the
   *   validator over a clone of the site's registry with the document
   *   added, and the rest as compiled() gives it.
   */
  protected function compiledOverSite(Schema $schema): array {
    $encoded = (string) json_encode(Schemas::arrayed($schema), self::ENCODING);
    $hash = hash('xxh128', $encoded);
    $decoded = Schemas::decoded($schema);
    $decoded = is_array($decoded) ? $decoded : [];
    $id = is_string($decoded['$id'] ?? NULL) ? $decoded['$id'] : 'urn:surface:validate:' . $hash;
    $registry = clone $this->container->get('surface.portable_registry');
    $this->assertInstanceOf(Registry::class, $registry);
    $registry->add(json_decode($encoded), $id);
    $names = (new \ReflectionMethod(PortableValidator::class, 'checkNames'))->invoke(NULL, $decoded);
    return [$hash, [new Validator($registry), $id, $names, $registry, $decoded]];
  }

  /**
   * Sets what a validator keeps compiled.
   *
   * @param \Drupal\surface\Validation\PortableValidator $validator
   *   The validator.
   * @param array<string, array> $compiled
   *   What it keeps, by the document's hash.
   */
  protected function setCompiled(PortableValidator $validator, array $compiled): void {
    (new \ReflectionProperty(PortableValidator::class, 'compiled'))->setValue($validator, $compiled);
  }

  /**
   * Gets what a validator keeps compiled.
   *
   * @param \Drupal\surface\Validation\PortableValidator $validator
   *   The validator.
   *
   * @return array<string, array>
   *   What it keeps, by the document's hash.
   */
  protected function compiledOf(PortableValidator $validator): array {
    return (new \ReflectionProperty(PortableValidator::class, 'compiled'))->getValue($validator);
  }

}
