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
   * @param string|null $owner
   *   The declaring id, or NULL for none.
   *
   * @return \Drupal\surface\ResourceBuild\ResourceBlueprint
   *   The blueprint.
   */
  protected function blueprint(?SurfaceBuilder $builder = NULL, ?string $owner = NULL): ResourceBlueprint {
    return new ResourceBlueprint($builder ?? new SurfaceBuilder(), $this->container->get('typed_data_manager'), FALSE, $this->container->get('surface.resource_registry'), $owner);
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
    $review = ['code', Schema::string(), 'Code', ['first'], BadgeCode::class];
    $this->assertRefused(fn () => $inputs->compute(...$review), ['must be of type array, string given'], \TypeError::class);

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
    $this->assertRefused(fn () => $this->blueprint()->reads('cap', Schema::integer()->minimum(3)->maximum(5)->required()), ['reads("cap")', '"minimum"', '"maximum"']);
    $this->assertRefused(fn () => $this->blueprint()->reads('p', Schema::enum(['a', 'b'])), ['reads("p")', '"enum"']);
    $this->assertRefused(fn () => $this->blueprint()->reads('p', Schema::string()->title('T')->default('x')), ['"title"', '"default"']);

    $inputs = $this->blueprint();
    $inputs->reads('cap', Schema::integer()->required());
    $inputs->reads('stamp', Schema::of('portable:timestamp'));
    $inputs->reads('any', Schema::any());
    $this->assertSame(['cap', 'stamp', 'any'], array_keys($inputs->ports()));
    $this->assertTrue($inputs->ports()['cap']->required);
  }

}
