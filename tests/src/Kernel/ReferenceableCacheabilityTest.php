<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Tests\data_surface\Traits\ServedControllerTrait;
use Drupal\Core\Entity\Entity\EntityFormDisplay;
use Drupal\data_surface_entity\Plugin\DataSurfaceOptionsResolver\ReferenceableOptions;
use Drupal\Core\Cache\CacheableJsonResponse;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a reference's options say what they vary by.
 *
 * Bug B5 of the reviews (2026-10-20): the referenceable options, and so
 * the full schema the schema route lets Drupal cache, varied by
 * `user.permissions` alone, though node grants decide per account which
 * nodes a selection offers. A second account with the same roles could
 * be served the first's labels. They now carry what core's selection and
 * its query access read: node grants for a node, permissions for a term.
 *
 * @see \Drupal\data_surface_entity\Plugin\DataSurfaceOptionsResolver\ReferenceableOptions
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ReferenceableCacheabilityTest extends DataSurfaceKernelTestBase {

  use ServedControllerTrait;

  use ContentEntityFixtureTrait;
  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [...self::ENTITY_MODULES, 'data_surface_react'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntityFixture();
    $this->setUpCurrentUser(admin: TRUE);
    $selection = ['handler' => 'default:node', 'handler_settings' => ['target_bundles' => ['page' => 'page']]];
    $this->addField('page', 'field_related', 'entity_reference', ['target_type' => 'node'], $selection, 'Related');
    $display = EntityFormDisplay::load('node.page.default');
    assert($display !== NULL);
    $display->setComponent('field_related', ['weight' => 40])->save();
    Node::create(['type' => 'page', 'title' => 'A page to point at', 'field_description' => 'Pointed at.'])->save();
  }

  /**
   * Gets the schema route's answer for adding a page.
   *
   * @return \Drupal\Core\Cache\CacheableJsonResponse
   *   The response.
   */
  protected function schema(): CacheableJsonResponse {
    $response = $this->served('GET', '/surface-api/entity.content:node.page/schema');
    $this->assertInstanceOf(CacheableJsonResponse::class, $response);
    return $response;
  }

  /**
   * Tests the full schema of a node reference varies by node grants.
   */
  public function testNodeReferenceVariesByNodeGrants(): void {
    $response = $this->schema();
    $schema = json_decode((string) $response->getContent(), TRUE);
    $this->assertArrayHasKey('field_related', $schema['properties']);
    $this->assertStringContainsString('A page to point at', (string) json_encode($schema['$defs']['field.item:node.page.field_related']), 'The targets are enumerated.');
    $contexts = $response->getCacheableMetadata()->getCacheContexts();
    $this->assertContains('user.node_grants:view', $contexts);
    $this->assertContains('user.permissions', $contexts);
  }

  /**
   * Tests what each target type's options vary by.
   */
  public function testEachTargetTypeSaysWhatItReads(): void {
    $this->assertSame(['user.permissions', 'user.node_grants:view'], ReferenceableOptions::CONTEXTS['node']);
    $this->assertSame(['user.permissions'], ReferenceableOptions::CONTEXTS['taxonomy_term']);
    $this->assertArrayNotHasKey('file', ReferenceableOptions::CONTEXTS, 'A file\'s selection offers what the account uploaded: it varies by the account.');
    // The page's tags, a term reference, vary by permissions alone.
    $this->assertNotContains('user', $this->schema()->getCacheableMetadata()->getCacheContexts());
  }

}
