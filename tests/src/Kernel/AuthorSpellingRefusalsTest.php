<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\ResourceBuild\ResourceBlueprint;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface_resource_test\Compute\BadgeCode;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the author spellings a blueprint refuses rather than reads in part.
 *
 * Each case is a spelling the slice 32.9 review found read in part: some
 * of what the author wrote was dropped with no word. Each is now refused
 * naming what to write, or read whole.
 *
 * @see docs/decisions.md#slice-329-the-whole-reviews-fixes-2026-11-18
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AuthorSpellingRefusalsTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_property_test',
    'surface_resource_test',
  ];

  /**
   * Gets a resource's input blueprint over a builder of its own.
   *
   * @param \Drupal\surface\SurfaceBuilder|null $builder
   *   The builder, or NULL for a new one.
   * @param string|null $declaring_class
   *   The declaring id, or NULL for none.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint.
   */
  protected function blueprint(?SurfaceBuilder $builder = NULL, ?string $declaring_class = NULL): ResourceBlueprint {
    return new ResourceBlueprint($builder ?? new SurfaceBuilder(), $this->container->get('typed_data_manager'), FALSE, $this->container->get('surface.resource_registry'), $declaring_class);
  }

  /**
   * Asserts a declaration is refused with a message holding each part.
   *
   * @param callable $declare
   *   The declaration.
   * @param string[] $parts
   *   What the message holds.
   * @param class-string<\Throwable> $class
   *   What is thrown.
   */
  protected function assertRefused(callable $declare, array $parts, string $class = \LogicException::class): void {
    try {
      $declare();
    }
    catch (\Throwable $e) {
      $this->assertInstanceOf($class, $e, $e->getMessage());
      foreach ($parts as $part) {
        $this->assertStringContainsString($part, $e->getMessage());
      }
      return;
    }
    $this->fail(sprintf('Not refused; the message would hold: %s', implode(' | ', $parts)));
  }

  /**
   * Tests compute() takes its label from the schema alone.
   *
   * The review's spelling handed a label beside the schema, which the key
   * dropped. compute() takes no label: PHP refuses the string where the
   * sources are, and the schema's title is the key's label.
   */
  public function testComputeTakesNoLabelBesideTheSchema(): void {
    $inputs = $this->blueprint();
    $inputs->add('first', Schema::string());
    // The review's call, made by reflection: the type checker refuses it
    // as PHP does.
    $review = ['code', Schema::string(), 'Code', ['first'], BadgeCode::class];
    $compute = new \ReflectionMethod($inputs, 'compute');
    $this->assertRefused(fn () => $compute->invokeArgs($inputs, $review), ['must be of type array, string given'], \TypeError::class);

    $builder = new SurfaceBuilder();
    $inputs = $this->blueprint($builder);
    $inputs->add('first', Schema::string());
    $inputs->compute('code', Schema::string()->title('Code'), ['first'], BadgeCode::class);
    $this->assertSame('Code', $builder->heldSchema('code')?->get('title'));
  }

  /**
   * Tests reads() refuses every keyword but the type, required() and a $ref.
   *
   * A port is read by its type alone; a bound, an option list, a title or a
   * default handed with it was dropped. The key wired to the port holds
   * its own rules.
   */
  public function testReadsRefusesKeywordsBesideTheType(): void {
    $bound = Schema::integer()->minimum(3)->maximum(5)->required();
    $this->assertRefused(fn () => $this->blueprint()->reads('cap', $bound), [
      'reads("cap")',
      '"minimum"',
      '"maximum"',
    ]);
    $this->assertRefused(fn () => $this->blueprint()->reads('p', Schema::enum(['a', 'b'])), ['reads("p")', '"enum"']);
    $titled = Schema::string()->title('T')->default('x');
    $this->assertRefused(fn () => $this->blueprint()->reads('p', $titled), ['"title"', '"default"']);

    $inputs = $this->blueprint();
    $inputs->reads('cap', Schema::integer()->required());
    $inputs->reads('stamp', Schema::of('portable:timestamp'));
    $inputs->reads('any', Schema::any());
    $this->assertSame(['cap', 'stamp', 'any'], array_keys($inputs->ports()));
    $this->assertTrue($inputs->ports()['cap']->required);
  }

  /**
   * Tests options whose every value PHP made an integer state their type.
   *
   * PHP turns a numeric-string array key into an integer, so
   * Schema::options(['1' => 'One']) offers the integer 1, and "1" was then
   * refused at the key. With no type stated the author's meaning is
   * unknown, so the spelling is refused naming both types; a stated type
   * is read as stated.
   */
  public function testOptionsOfIntegerKeysStateTheirType(): void {
    $numbers = Schema::options(['1' => 'One', '2' => 'Two'])->title('N');
    $this->assertRefused(fn () => $this->blueprint()->add('k', $numbers), [
      '/properties/k:',
      "->type('string')",
      "->type('integer')",
    ], \InvalidArgumentException::class);
    $days = Schema::list(Schema::options([1 => 'Monday', 2 => 'Tuesday']))->title('Days');
    $this->assertRefused(fn () => $this->blueprint()->add('days', $days), [
      '/properties/days/items:',
      "->type('string')",
    ], \InvalidArgumentException::class);

    $builder = new SurfaceBuilder();
    $inputs = $this->blueprint($builder);
    $inputs->add('text', Schema::options(['1' => 'One', '2' => 'Two'])->type('string')->title('N'));
    $inputs->add('number', Schema::options([1 => 'One', 2 => 'Two'])->type('integer')->title('N'));
    $inputs->add('mixed', Schema::options(['1' => 'One', 'b' => 'Bee'])->title('N'));
    $this->assertContains('string', (array) $builder->heldSchema('text')?->get('type'));
    $this->assertContains('integer', (array) $builder->heldSchema('number')?->get('type'));
    $this->assertNotNull($builder->heldSchema('mixed'));
  }

  /**
   * Tests an author's optional list of objects may be left out.
   *
   * The mark required() decides, as for attachList(): the reader no longer
   * marks an author's list as one its storage always holds.
   */
  public function testOptionalListOfObjectsMayBeLeftOut(): void {
    $builder = new SurfaceBuilder();
    $this->blueprint($builder)
      ->add('objects', Schema::list(Schema::object()->property('a', Schema::string()))->title('Objects'))
      ->add('needed', Schema::list(Schema::object()->property('a', Schema::string()))->title('Needed')->required());
    $surface = $builder->seal();
    $codes = static function (iterable $violations): array {
      $codes = [];
      foreach ($violations as $violation) {
        $codes[] = $violation->key . ' ' . $violation->code;
      }
      return $codes;
    };
    $this->assertSame([], $codes($this->pipeline()->validate($surface, ['needed' => [['a' => 'x']]])));
    $this->assertSame(['needed required'], $codes($this->pipeline()->validate($surface, ['objects' => [['a' => 'x']]])));
  }

  /**
   * Tests an author's `x-surface` is refused, naming `x-surface-settings`.
   *
   * `x-surface` is what a served document says of a key; an author's was
   * dropped with no word. A setting no keyword says is
   * `x-surface-settings`.
   */
  public function testAuthorsExtensionIsRefused(): void {
    $served = Schema::string()->annotation('x-surface', ['format' => 'color']);
    $this->assertRefused(fn () => $this->blueprint()->add('k', $served), [
      'add("k")',
      '/properties/k/x-surface',
      'x-surface-settings',
    ], \InvalidArgumentException::class);
    $this->assertRefused(fn () => $this->blueprint()->add('where', Schema::object()->property('city', $served)), [
      '/properties/where/properties/city/x-surface',
      'x-surface-settings',
    ], \InvalidArgumentException::class);

    $builder = new SurfaceBuilder();
    $this->blueprint($builder)->add('k', Schema::string()->annotation('x-surface-settings', ['format' => 'color']));
    $this->assertTrue($builder->has('k'));
  }

}
