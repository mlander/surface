<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\ResourceBuild\ResourceRegistry;
use Drupal\surface_resource_test\OutsideDiscovery\EastWordingAlter;
use Drupal\surface_resource_test\OutsideDiscovery\NorthWordingAlter;
use Drupal\surface_resource_test\OutsideDiscovery\SouthWordingAlter;
use Drupal\surface_resource_test\Resource\Relay\PlainRelayResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests two alters saying one fact about a key: the second is refused.
 *
 * The owner's words are what alters lay over. The first alter to set a
 * fact on a key it does not own sets it; a second setting the same fact
 * on the same key is refused at build, naming the key, the fact and both
 * alters. Two alters setting different facts compose, and an alter
 * wording its own mount again is no collision.
 *
 * @see docs/decisions.md#a-key-may-stand-for-another-at-use
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class AlterWordingTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the plain relay with the alters given.
   *
   * @param array<class-string, string> $alters
   *   Each alter, by the module it stands for.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface|null
   *   The token, as built.
   */
  protected function token(array $alters): ?DataDefinitionInterface {
    $registry = new ResourceRegistry([
      'resources' => [PlainRelayResource::class => 'surface_resource_test'],
      'operations' => [PlainRelayResource::class => 'surface_resource_test'],
      'alters' => $alters,
    ], $this->container->get('cache.discovery'));
    $resources = $this->container->get('surface.resources')->withRegistry($registry, $this->container->get('class_resolver'));
    return $resources->buildOperation(PlainRelayResource::class, 'configure')->getDefinition('token');
  }

  /**
   * Tests a second alter setting the token's label is refused, naming both.
   */
  public function testTheSameFactTwiceIsRefused(): void {
    try {
      $this->token([NorthWordingAlter::class => 'north_wording', SouthWordingAlter::class => 'south_wording']);
      $this->fail('Two alters labeling one key were not refused.');
    }
    catch (\LogicException $e) {
      $this->assertSame('describe("token") in the south_wording alter sets its label, which the north_wording alter set already. A fact about a key is set by one alter: the owner\'s is what alters lay over, and two alters setting the same one have no right answer between them. One module words a key, or each sets another fact.', $e->getMessage());
    }
  }

  /**
   * Tests two alters setting different facts on one key compose.
   *
   * The north alter labels the token and gives it a help text, and words
   * its own mount twice; the east alter gives it a placeholder.
   */
  public function testDifferentFactsCompose(): void {
    $token = $this->token([NorthWordingAlter::class => 'north_wording', EastWordingAlter::class => 'east_wording']);
    $this->assertNotNull($token);
    $this->assertSame('North token', (string) $token->getLabel());
    $arrangement = DefinitionMetadata::arrangementOf($token);
    $this->assertSame('Issued by the north desk.', (string) $arrangement?->help);
    $this->assertSame('tok-...', (string) $arrangement?->placeholder);
  }

}
