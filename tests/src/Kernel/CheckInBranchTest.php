<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface_examples_block\Resource\BlockPlacementResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a check in a branch is not run on a value of the wrong type.
 *
 * A placed block's settings are one branch of the document for each
 * plugin. The featured content block's bundle is a string held to
 * EntityBundleExists, whose validator throws for a value that is no
 * string. A boolean sent there is refused with `type`, as a boolean at a
 * string key is anywhere (InputCoercionMatrixTest), and the check is not
 * run on it, neither to refuse it nor to word another refusal.
 *
 * @see docs/decisions.md#validation-runs-through-the-dialects-validator
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class CheckInBranchTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_http',
    'surface_examples',
    'surface_examples_component_block',
    'surface_examples_block',
    'surface_examples_plugins',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
    $this->container->get('theme_installer')->install(['stark']);
  }

  /**
   * Tests a boolean at a block's bundle is refused with `type`, not thrown.
   */
  public function testBooleanInBranchIsRefusedByType(): void {
    $resources = $this->container->get('surface.resources');
    $surface = $resources->build(BlockPlacementResource::class, $resources->operation(BlockPlacementResource::class, 'create'));
    $input = [
      'label' => 'Featured',
      'id' => 'featured',
      'plugin' => 'surface_examples_plugins',
      'theme' => 'stark',
      'region' => 'content',
      'settings' => ['bundle' => TRUE],
    ];
    $values = $this->pipeline()->accept($surface, $input);
    $this->assertTrue($values['settings']['bundle']);

    $found = [];
    foreach ($this->pipeline()->validate($surface, $values) as $violation) {
      $found[] = [$violation->fullPath(), $violation->code];
    }
    $this->assertContains(['settings.bundle', 'type'], $found);
    $this->assertNotContains('settings.bundle', array_column(array_filter($found, fn (array $one): bool => str_starts_with($one[1], 'check.')), 0));
  }

}
