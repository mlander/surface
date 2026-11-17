<?php

declare(strict_types=1);

namespace Drupal\Tests\surface_http\Kernel;

use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\Tests\surface\Kernel\ContentEntityFixtureTrait;
use Drupal\Tests\surface\Kernel\SurfaceKernelTestBase;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\user\Entity\Role;
use Drupal\user\Entity\User;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the lookup asks the handler a reference key names, with its settings.
 *
 * The kitchen bundle's resource, derived from its fields, with one more:
 * a reference to a user whose field names core's user selection handler
 * with settings of its own, the anonymous account left out and only the
 * accounts of one role. The lookup, asked of the controller, reads the
 * handler and its settings off the key's node and finds only that role's
 * account; the default handler with no settings would find every account.
 *
 * @see docs/decisions.md#the-remaining-readers-read-schemas
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class LookupHandlerSettingsTest extends SurfaceKernelTestBase {

  use ContentEntityFixtureTrait;
  use ServedControllerTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'surface_http'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->addField('kitchen', 'field_cook', 'entity_reference', ['target_type' => 'user'], [
      'handler' => 'default:user',
      'handler_settings' => [
        'include_anonymous' => FALSE,
        'filter' => ['type' => 'role', 'role' => ['chef' => 'chef']],
      ],
    ], 'Cook');
    $display = EntityFormDisplay::load('node.kitchen.default');
    $this->assertNotNull($display);
    $display->setComponent('field_cook', ['weight' => 99])->save();
    Role::create(['id' => 'chef', 'label' => 'Chef'])->save();
    User::create(['uid' => 0, 'name' => '', 'status' => 0])->save();
    User::create(['name' => 'Cook Ada', 'status' => 1, 'roles' => ['chef']])->save();
    User::create(['name' => 'Cook Bob', 'status' => 1])->save();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests the lookup finds what the key's handler and settings find.
   */
  public function testTheLookupAsksTheKeysHandler(): void {
    $registry = $this->container->get('surface.resource_registry');
    $definition = $registry->getDefinition('entity.content:node.kitchen');
    $operation = $registry->getOperation($definition->name(), 'create');
    $url = $this->container->get('surface.served_paths')->lookupUrl($definition, $operation, [])->getGeneratedUrl();
    $cook = User::loadMultiple();
    $ada = array_search('Cook Ada', array_map(static fn (User $user): string => (string) $user->getAccountName(), $cook), TRUE);
    $this->assertSame(
      [
        'key' => '/field_cook/target_id',
        'entityType' => 'user',
        'bundles' => [],
        'items' => [['id' => (int) $ada, 'label' => 'Cook Ada']],
      ],
      $this->servedJson('GET', $url . '?key=/field_cook/target_id&q=Cook'),
      'Only the chef: the default handler with no settings would find Cook Bob too.',
    );
  }

}
