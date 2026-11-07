<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Resource\BlueprintAdditionsInterface;
use Drupal\surface\Resource\BlueprintInterface;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the size of the authoring API: the concept budget for the code.
 *
 * "Your first resource" is the budget for the docs, held by
 * FirstResourceDocsTest; this is the one for the names an author writes.
 * It counts what `src/Resource/` declares: the attributes, their
 * constructor arguments, the blueprint methods (BlueprintInterface and the
 * add-only BlueprintAdditionsInterface together) and the interfaces. A
 * change that adds one fails here until this test is updated with it,
 * which is the place to say why the surface grows; one that removes one
 * fails too, so the count stays the count.
 *
 * The counts were taken on 2026-10-24, when the surface was counted and
 * cut: 9 attributes, 33 arguments to 30, 17 blueprint methods to 15, 23
 * interfaces to 20. Grown on 2026-10-25 by one argument,
 * `#[Operation(gate:)]`, access borrowed from another thing's owner
 * (docs/decisions.md#a-gate-is-borrowed-never-copied): 31 arguments.
 * Grown on 2026-10-27 by one blueprint method, `resolve()`, a key read
 * otherwise at use than it is stored
 * (docs/decisions.md#a-key-may-stand-for-another-at-use): 16 methods.
 * Grown on 2026-10-28 by one interface, EntrySchemasInterface, a provider
 * of variants that answers an entry's schema only when a door reads it,
 * so a long list costs a request the entries it reads
 * (docs/decisions.md#the-static-build-is-cached-the-rest-is-per-request):
 * 21 interfaces.
 * Grown on 2026-10-31 by four arguments, `label:` and `description:` on
 * #[Resource] and #[Structure], what a resource or structure is called
 * and is for, beside its id
 * (docs/decisions.md#a-resource-and-a-structure-may-say-what-they-are-called-and-what-they-are-for):
 * 35 arguments.
 * Cut on 2026-11-01 by one blueprint method, `refer()`: a reference is a
 * key of the id's type with the constraint every reader already reads
 * (docs/decisions.md#a-reference-is-a-constraint-on-a-value): 17 methods.
 * Grown on 2026-11-04 by eighteen attributes and their 76 arguments, the
 * blueprint's verbs spelled as properties (#[Input], #[Output], #[Map],
 * #[Attach], #[AttachList], #[Variants], #[Each], #[AttachEach], #[Port],
 * #[Computed], #[Constraint], #[Rule], #[Describe], #[Group], #[Examples],
 * #[Widget], #[Setting], #[MixIn]), which DeclaresByProperties reads into
 * the same verbs: a second spelling of the blueprint, never a second
 * contract, so no blueprint method and no interface was added
 * (docs/decisions.md#a-class-may-declare-its-keys-as-properties): 27
 * attributes, 112 arguments. #[Output] extends #[Input], so its five
 * arguments are counted with it.
 * Grown on 2026-11-05 by one blueprint method, `mixInVariants()`, the
 * structure a sibling key chooses mixed in flat, as config schema stores
 * a type chosen by a key inside its own value, and its attribute,
 * #[MixInVariants], with its three arguments
 * (docs/decisions.md#a-mixin-may-be-chosen-by-a-sibling-key): 18
 * methods, 28 attributes, 115 arguments.
 * Unchanged on 2026-11-06 by a rename, #[Variants] to #[AttachVariants],
 * and by mixIn() and mixInVariants() moving from the add-only interface to
 * BlueprintInterface, which this test counts together.
 * Cut on 2026-11-06 by two arguments, #[Each(to:, bundles:)]: a reference
 * per entry is the entry's definition with the reference constraint,
 * #[Constraint(..., item: TRUE)] beside #[Each]
 * (docs/decisions.md#a-reference-is-a-constraint-on-a-value): 113
 * arguments.
 * Cut on 2026-11-06 by the blueprint method `mixInVariants()` and its
 * attribute, #[MixInVariants], with its three arguments: a tagged union
 * has one blueprint form, attachVariants() at a key, and storing the
 * chosen keys beside the deciding key is a setting on that key,
 * Variants::STORED_AS, which the engine's layout reads: 17 methods, 27
 * attributes, 110 arguments.
 *
 * @see docs/decisions.md#the-surface-was-counted-and-cut
 */
#[Group('surface')]
class PublicSurfaceTest extends UnitTestCase {

  /**
   * The directory of the authoring API.
   */
  protected const RESOURCE = __DIR__ . '/../../../src/Resource';

  /**
   * The namespace of the authoring API.
   */
  protected const NAMESPACE = 'Drupal\\surface\\Resource\\';

  /**
   * What the surface holds, by kind.
   */
  protected const PINNED = [
    'attributes' => 27,
    'attribute arguments' => 110,
    'blueprint methods' => 17,
    'interfaces' => 22,
  ];

  /**
   * Tests the authoring API is the size this test says.
   */
  public function testTheSurfaceIsPinned(): void {
    $attributes = [];
    $arguments = [];
    foreach (glob(self::RESOURCE . '/Attribute/*.php') ?: [] as $file) {
      $class = self::NAMESPACE . 'Attribute\\' . basename($file, '.php');
      $attributes[] = $class;
      foreach ((new \ReflectionClass($class))->getConstructor()?->getParameters() ?? [] as $parameter) {
        $arguments[] = basename($file, '.php') . '::' . $parameter->getName();
      }
    }
    $methods = [];
    foreach ([BlueprintInterface::class, BlueprintAdditionsInterface::class] as $interface) {
      foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
        $methods[$method->getName()] = $method->getName();
      }
    }
    $interfaces = [];
    foreach (glob(self::RESOURCE . '/*.php') ?: [] as $file) {
      $name = self::NAMESPACE . basename($file, '.php');
      if (interface_exists($name)) {
        $interfaces[] = basename($file, '.php');
      }
    }
    $counted = [
      'attributes' => $attributes,
      'attribute arguments' => $arguments,
      'blueprint methods' => array_values($methods),
      'interfaces' => $interfaces,
    ];
    foreach (self::PINNED as $kind => $pinned) {
      $this->assertCount($pinned, $counted[$kind], sprintf(
        'The authoring API holds %d %s, and this test pins %d: %s. Say why it changed, and pin the new count here and in docs/decisions.md#the-surface-was-counted-and-cut.',
        count($counted[$kind]),
        $kind,
        $pinned,
        implode(', ', $counted[$kind]),
      ));
    }
  }

}
