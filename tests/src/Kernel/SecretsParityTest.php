<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\Cache;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\Attachment;
use Drupal\surface\Contract\Secrets;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Pipeline\Outbound;
use Drupal\surface\ResourceBuild\Location;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface_resource_test\Resource\Poster\PosterResource;
use Drupal\surface_resource_test\Variants\PosterLayouts;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests a served document says secret where the definitions say it.
 *
 * What is walked: every declaration of every example module WALKED names
 * (each example module a kernel test can install; LEFT_OUT names the rest
 * and why), in each operation that needs no subject and is no default
 * read, or alone where it declares none (ExampleSurfacesTrait::examples()),
 * built for an administrator. For each, every value set
 * (ExampleValueSetsTrait::valueSets()) and every place the full document
 * and the document for those values hold that the values reach:
 * Secrets::at() over the document agrees with
 * DefinitionMetadata::isSecret() of the definition
 * Location::definitionAt() finds there. A place where definitionAt()
 * finds no definition is counted, not compared. Secrets::within() over
 * the full document and over the document for each value set equals
 * Outbound::secrets() of the data surface. Each disagreement is one of
 * the families FAMILIES names, and the test asserts the disagreements
 * are exactly the ones EXPECTED lists. The shipped examples hold no
 * member of either family; testEachFamilyHasMember() builds one of
 * each, so the rule is shown and not only its absence.
 *
 * @see docs/decisions.md#a-shape-is-a-schema-secrecy-is-read-from-a-schema
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class SecretsParityTest extends SurfaceKernelTestBase {

  use ExampleSurfacesTrait;
  use ExampleValueSetsTrait;
  use UserCreationTrait;

  /**
   * The families a document and the definitions may disagree in, and why.
   */
  public const FAMILIES = [
    'another variant' => 'A key with variants: the chosen variant declares the key plain, another variant of the same name declares it secret, and the document says secret in any branch.',
    'not in the document' => 'A deferred variant, or one a document narrowed to the chosen variant leaves out: the definition is secret in its own data surface, and the document does not carry it. Kept secrets, outbound projection and storage read built schemas for this reason, never a served document.',
  ];

  /**
   * The disagreements the shipped examples hold, by family, then by place.
   *
   * None: no shipped example declares a key plain in one variant and
   * secret in another of the same name or defers a variant, and the
   * schema for any answers still carries every variant. A change that
   * adds one lists it here, in its family.
   */
  protected const EXPECTED = [
    'another variant' => [],
    'not in the document' => [],
  ];

  /**
   * The example modules whose declarations are walked.
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
   * The example modules not walked, and why.
   *
   * None: every example module installs in a kernel test. One that cannot
   * is named here with the reason.
   */
  protected const LEFT_OUT = [];

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
   * Builds every declaration WALKED names.
   *
   * @return \Generator<int, array{0: string, 1: string|null, 2: \Drupal\surface\SurfaceInterface, 3: int}>
   *   As ExampleSurfacesTrait::examples() answers.
   */
  protected function walked(): \Generator {
    yield from $this->examples(FALSE, self::WALKED);
  }

  /**
   * Gets the JSON pointer of a path through a served document.
   *
   * @param string[] $path
   *   The keys from the root.
   *
   * @return string
   *   The pointer.
   */
  protected static function pointerOf(array $path): string {
    $pointer = '';
    foreach ($path as $segment) {
      $pointer = Location::append($pointer, $segment);
    }
    return $pointer;
  }

  /**
   * Reads a served document as a Schema.
   *
   * @param array $document
   *   The document's schema, as the emitter serves it.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, as JSON reads it.
   */
  protected static function schemaOf(array $document): Schema {
    return Schema::from(json_decode((string) json_encode($document, JSON_THROW_ON_ERROR)));
  }

  /**
   * Names the family a disagreement at a place is in.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   The values the place is reached under.
   * @param string $pointer
   *   The place.
   * @param bool $document
   *   What the document says.
   *
   * @return string
   *   A key of FAMILIES, or 'none' where neither explains it.
   */
  protected static function family(SurfaceInterface $surface, array $values, string $pointer, bool $document): string {
    $segments = explode('/', substr($pointer, 1));
    $frame = $surface;
    $held = $values;
    foreach ($segments as $i => $segment) {
      $entry = $frame->entries()->entry($segment);
      if ($entry === NULL) {
        return 'none';
      }
      if ($entry->variants !== NULL && isset($segments[$i + 1])) {
        $key = $segments[$i + 1];
        foreach ($entry->variants->variants as $variant) {
          $other = $variant->child->entries()->entry($key);
          if ($document && $other !== NULL && DefinitionMetadata::isSecret($other->definition())) {
            return 'another variant';
          }
        }
        if (!$document) {
          return 'not in the document';
        }
      }
      $child = $entry->childFor($held);
      if ($child === NULL) {
        return 'none';
      }
      $frame = $child;
      $held = is_array($held[$segment] ?? NULL) ? $held[$segment] : [];
    }
    return 'none';
  }

  /**
   * Tests at() agrees with the definition at every place, but the families.
   *
   * The counts are literals: a walk that reached nothing would agree
   * everywhere, and the literals stop it from passing. A change that adds
   * an example, a key or a value set moves them.
   */
  public function testTheDocumentSaysSecretWhereTheDefinitionDoes(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $found = ['full' => [], 'narrowed' => []];
    $counts = ['surfaces' => 0, 'places' => 0, 'no definition' => 0, 'secret' => 0, 'agree' => 0];
    foreach ($this->walked() as [$id, $operation, $surface]) {
      $counts['surfaces']++;
      $full = $emitter->emitFull($surface, $id, $operation)->document['schema'];
      foreach ($this->valueSets($surface) as $values) {
        $narrowed = $emitter->emit($surface, $values, $id, $operation)->document['schema'];
        foreach (['full' => $full, 'narrowed' => $narrowed] as $which => $served) {
          $document = static::schemaOf($served);
          $paths = [];
          foreach (static::walk($served, $served) as [$path]) {
            $paths[implode('/', $path)] = $path;
          }
          foreach ($paths as $path) {
            $pointer = static::pointerOf($path);
            $location = Location::definitionAt($surface, $values, $pointer);
            if ($location?->definition() === NULL) {
              $counts['no definition']++;
              continue;
            }
            $counts['places']++;
            $said = Secrets::at($document, $pointer);
            $secret = DefinitionMetadata::isSecret($location->definition());
            $counts['secret'] += (int) $secret;
            if ($said === $secret) {
              $counts['agree']++;
              continue;
            }
            $found[$which][static::family($surface, $values, $pointer, $said)]["$id:$operation $pointer"] = $said ? 'document secret' : 'definition secret';
          }
        }
      }
    }
    $this->assertSame(['surfaces' => 53, 'places' => 3686, 'no definition' => 15312, 'secret' => 12, 'agree' => 3686], $counts, 'Every place the documents hold that the values reach.');
    foreach ($found as $which => $disagreements) {
      $families = array_fill_keys(array_keys(self::FAMILIES), []);
      foreach ($disagreements as $family => $places) {
        $this->assertArrayHasKey($family, self::FAMILIES, sprintf('%s: %s disagree in no family: %s', $which, count($places), implode(', ', array_keys($places))));
        ksort($places);
        $families[$family] = $places;
      }
      $this->assertSame(self::EXPECTED, $families, "The disagreements over the $which documents are the ones each family lists.");
    }
  }

  /**
   * Tests within() is the tree Outbound::secrets() finds, but the families.
   *
   * Over the full document of each walked declaration, and over the
   * document for each of its value sets. A disagreement over a document
   * for some values is in a family where a key with variants holds it:
   * 'another variant' where the document says more than the surface, 'not
   * in the document' where it says less.
   */
  public function testTheTreeIsWhatOutboundFinds(): void {
    $emitter = $this->container->get('surface.contract_emitter');
    $found = [];
    $narrowed = array_fill_keys(array_keys(self::FAMILIES), []);
    $compared = ['full' => 0, 'narrowed' => 0];
    foreach ($this->walked() as [$id, $operation, $surface]) {
      $compared['full']++;
      $outbound = Outbound::secrets($surface);
      $within = Secrets::within(static::schemaOf($emitter->emitFull($surface, $id, $operation)->document['schema']));
      if ($within !== $outbound) {
        $found["$id:$operation"] = ['document' => $within, 'outbound' => $outbound];
      }
      foreach ($this->valueSets($surface) as $set => $values) {
        $compared['narrowed']++;
        $within = Secrets::within(static::schemaOf($emitter->emit($surface, $values, $id, $operation)->document['schema']));
        if ($within !== $outbound) {
          $narrowed[static::treeFamily($within, $outbound)]["$id:$operation [$set]"] = [
            'document' => $within,
            'outbound' => $outbound,
          ];
        }
      }
    }
    $this->assertSame(['full' => 53, 'narrowed' => 249], $compared, 'Every walked declaration, and the document for each value set.');
    $this->assertSame([], $found, 'The full document carries every variant.');
    $this->assertSame(self::EXPECTED, array_map('array_keys', $narrowed), 'The documents for some values disagree only in the families, as EXPECTED lists.');
  }

  /**
   * Names the family a tree that differs from Outbound::secrets() is in.
   *
   * @param true|array $document
   *   What within() found in a document.
   * @param true|array $outbound
   *   What Outbound::secrets() found.
   *
   * @return string
   *   'another variant' where the document says a place secret that the
   *   surface does not, 'not in the document' otherwise.
   */
  protected static function treeFamily(true|array $document, true|array $outbound): string {
    return static::holdsMore($document, $outbound) ? 'another variant' : 'not in the document';
  }

  /**
   * Answers whether one secrets tree says a place secret another does not.
   *
   * @param true|array $a
   *   A tree.
   * @param true|array $b
   *   Another.
   *
   * @return bool
   *   TRUE where $a holds a secret place $b does not.
   */
  protected static function holdsMore(true|array $a, true|array $b): bool {
    if ($a === TRUE || $b === TRUE) {
      return $a === TRUE && $b !== TRUE;
    }
    foreach ($a as $key => $member) {
      if (!array_key_exists($key, $b) || static::holdsMore($member, $b[$key])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Tests each family has a member a test builds, so the rule is shown.
   *
   * 'another variant': a key plain in the chosen variant and secret in
   * another of the same name. 'not in the document': a deferred variant
   * that declares a secret, which the full document does not carry once
   * the site lists more variants than a document names whole.
   */
  public function testEachFamilyHasMember(): void {
    $emitter = $this->container->get('surface.contract_emitter');

    $plain = (new SurfaceBuilder())->setDefinition('key', DataDefinition::create('string')->setLabel('Key'))->seal();
    $hidden = DataDefinition::create('string')->setLabel('Key');
    DefinitionMetadata::setSecret($hidden);
    $secret = (new SurfaceBuilder())->setDefinition('key', $hidden)->seal();
    $builder = new SurfaceBuilder();
    $builder->setDefinition('kind', DataDefinition::create('string')->setLabel('Kind'))->setDefault('kind', 'a');
    $builder->setDefinition('settings', MapDataDefinition::create()->setLabel('Settings'));
    $builder->attachVariants('settings', 'kind', ['a' => new Attachment($plain), 'b' => new Attachment($secret)]);
    $surface = $builder->seal();
    $document = static::schemaOf($emitter->emitFull($surface, 'test.variants', 'configure')->document['schema']);
    $values = ['kind' => 'a', 'settings' => ['key' => 'x']];
    $this->assertTrue(Secrets::at($document, '/settings/key'), 'The document says secret in any branch.');
    $this->assertFalse(DefinitionMetadata::isSecret(Location::definitionAt($surface, $values, '/settings/key')->definition()), 'The chosen variant says plain.');
    $this->assertSame('another variant', static::family($surface, $values, '/settings/key', TRUE));
    $this->assertSame(['settings' => ['key' => TRUE]], Secrets::within($document));
    $this->assertSame(Outbound::secrets($surface), Secrets::within($document));

    // The poster lists more layouts than a document names whole, and one
    // more, the vault, that declares a secret; each layout is deferred.
    $layouts = [];
    $defs = [];
    for ($i = 0; $i < 500; $i++) {
      $layouts['shelf_' . $i] = ['label' => 'Shelf ' . $i, 'schema' => ['$ref' => '#/$defs/poster.shelf.' . $i]];
      $defs['poster.shelf.' . $i] = [
        'type' => 'object',
        'properties' => ['heading' => ['type' => 'string']],
        'additionalProperties' => FALSE,
      ];
    }
    $layouts['vault'] = [
      'label' => 'Vault',
      'schema' => [
        'type' => 'object',
        'properties' => ['pin' => ['type' => 'string', 'writeOnly' => TRUE]],
        'additionalProperties' => FALSE,
      ],
    ];
    $this->container->get('state')->set(PosterLayouts::STATE, $layouts);
    $this->container->get('state')->set(PosterLayouts::DEFS, $defs);
    $this->container->get('state')->set(PosterLayouts::LAZY, FALSE);
    Cache::invalidateTags([PosterLayouts::TAG]);
    $this->container->get('surface.resources')->forgetBuilds();
    $poster = $this->container->get('surface.resources')->buildOperation(PosterResource::class, 'configure');
    $values = ['layout' => 'vault', 'panels' => ['pin' => '1234']];
    $full = static::schemaOf($emitter->emitFull($poster, 'resource_test.poster', 'configure')->document['schema']);
    $this->assertFalse(Secrets::at($full, '/panels/pin'), 'The full document names the layouts alone.');
    $this->assertTrue(DefinitionMetadata::isSecret(Location::definitionAt($poster, $values, '/panels/pin')->definition()), 'The vault, built, says secret.');
    $this->assertSame('not in the document', static::family($poster, $values, '/panels/pin', FALSE));
    $this->assertSame(['panels' => ['pin' => TRUE]], Outbound::secrets($poster));
    $this->assertSame('not in the document', static::treeFamily(Secrets::within($full), Outbound::secrets($poster)));
  }

}
