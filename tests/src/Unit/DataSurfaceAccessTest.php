<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Access\AccessResultInterface;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\data_surface\DataSurfaceAccess;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the rules for reading a provider's access answer.
 *
 * The whole point of answering with core's tri-state rather than a
 * boolean is that "no opinion" is sayable, and the way that goes wrong
 * is a host combining the two answers with a plain andIf(): allowed AND
 * neutral is neutral, and neutral is not allowed, so every provider that
 * never adopted the method would silently close its host's door. This is
 * the truth table that must not regress.
 *
 * @see \Drupal\data_surface\DataSurfaceAccess
 */
#[Group('data_surface')]
class DataSurfaceAccessTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // A refusal's message is a TranslatableMarkup, and rendering one
    // asks the container for the translation service, which a unit test
    // has to provide for itself.
    $container = new ContainerBuilder();
    $container->set('string_translation', $this->getStringTranslationStub());
    // Adding a cache context to an access result validates it against
    // the registered ones, which in a unit test nothing registers.
    $contexts = $this->createMock(CacheContextsManager::class);
    $contexts->method('assertValidTokens')->willReturn(TRUE);
    $container->set('cache_contexts_manager', $contexts);
    \Drupal::setContainer($container);
  }

  /**
   * Builds one of the three access answers by name.
   *
   * @param string $state
   *   The state to build: 'allowed', 'neutral' or 'forbidden'.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The answer.
   */
  protected static function answer(string $state): AccessResultInterface {
    return match ($state) {
      'allowed' => AccessResult::allowed(),
      'forbidden' => AccessResult::forbidden(),
      default => AccessResult::neutral(),
    };
  }

  /**
   * Every combination of a host answer and a provider answer.
   *
   * @return array<string, array{0: string, 1: string, 2: string}>
   *   Test cases: the host's answer, the provider's answer, and what the
   *   gate makes of the two.
   */
  public static function gateCases(): array {
    return [
      // A provider with no opinion never changes the host's answer,
      // which is the whole compatibility promise.
      'allowed and no opinion' => ['allowed', 'neutral', 'allowed'],
      'neutral and no opinion' => ['neutral', 'neutral', 'neutral'],
      'forbidden and no opinion' => ['forbidden', 'neutral', 'forbidden'],
      // A provider may refuse what the host allowed.
      'allowed but the provider refuses' => ['allowed', 'forbidden', 'forbidden'],
      // And may agree, without that being a grant of its own.
      'allowed and the provider agrees' => ['allowed', 'allowed', 'allowed'],
      // What it may never do is open a door the host closed.
      'the host has no opinion, the provider allows' => ['neutral', 'allowed', 'neutral'],
      'the host forbids, the provider allows' => ['forbidden', 'allowed', 'forbidden'],
      'both refuse' => ['forbidden', 'forbidden', 'forbidden'],
    ];
  }

  /**
   * Tests the tri-state truth table hosts rely on.
   */
  #[DataProvider('gateCases')]
  public function testGate(string $host, string $provider, string $expected): void {
    $result = DataSurfaceAccess::gate(
      static::answer($host),
      static::answer($provider),
    );

    $this->assertSame($expected === 'allowed', $result->isAllowed(), 'allowed');
    $this->assertSame($expected === 'forbidden', $result->isForbidden(), 'forbidden');
    $this->assertSame($expected === 'neutral', $result->isNeutral(), 'neutral');
  }

  /**
   * Tests that a neutral provider still contributes its cacheability.
   *
   * "This provider had nothing to say" is a conclusion like any other:
   * it can stop being true, so whoever caches the combined answer has to
   * hear about it.
   */
  public function testNeutralStillCarriesItsCacheability(): void {
    $host = AccessResult::allowed()->addCacheContexts(['user.permissions']);
    $provider = AccessResult::neutral()->addCacheTags(['config:node_type_list']);

    $result = DataSurfaceAccess::gate($host, $provider);
    $cacheability = CacheableMetadata::createFromObject($result);

    $this->assertTrue($result->isAllowed());
    $this->assertContains('user.permissions', $cacheability->getCacheContexts());
    $this->assertContains('config:node_type_list', $cacheability->getCacheTags());
  }

  /**
   * Tests that the owner of an operation has no third state to offer.
   *
   * Core's permission and entity helpers answer neutral when the answer
   * is no, because another checker might still allow it. A route reads
   * that as a refusal; the pipeline's gate reads it as "nothing to say".
   * A provider that is where the rule is written down therefore says no
   * out loud, or the same account would be turned away by the route and
   * let through by the payload.
   */
  public function testDecisiveTurnsNoOpinionIntoRefusal(): void {
    $assembled = AccessResult::neutral()->addCacheContexts(['user.permissions']);

    $refused = DataSurfaceAccess::decisive($assembled, 'Both permissions are needed.');

    $this->assertTrue($refused->isForbidden());
    $this->assertStringContainsString(
      'Both permissions are needed.',
      (string) DataSurfaceAccess::message($refused),
    );
    // Whatever the assembled answer knew about its own lifetime is still
    // known, so the refusal is cached for no longer than it is true.
    $this->assertContains(
      'user.permissions',
      CacheableMetadata::createFromObject($refused)->getCacheContexts(),
    );
  }

  /**
   * Tests that a decided answer is handed back exactly as it came.
   */
  public function testDecisiveLeavesDecidedAnswerAlone(): void {
    $allowed = AccessResult::allowed();
    $own_reason = AccessResult::forbidden('The field is locked.');

    $this->assertSame($allowed, DataSurfaceAccess::decisive($allowed, 'Unused.'));
    // A refusal that already said why keeps its own words rather than
    // being restated in generic ones.
    $this->assertSame($own_reason, DataSurfaceAccess::decisive($own_reason, 'Unused.'));
  }

  /**
   * Tests that a refusal's reason becomes a message object.
   */
  public function testMessageCarriesTheReason(): void {
    $message = DataSurfaceAccess::message(AccessResult::forbidden('The field is locked.'));

    $this->assertStringContainsString('The field is locked.', (string) $message);
  }

  /**
   * Tests that a refusal without a reason still says something.
   */
  public function testMessageWithoutReason(): void {
    $this->assertSame('Access refused.', (string) DataSurfaceAccess::message(AccessResult::forbidden()));
    // A neutral answer carries no reason either, and the message is the
    // same generic one: nothing about the answer is invented here.
    $this->assertSame('Access refused.', (string) DataSurfaceAccess::message(AccessResult::neutral()));
  }

}
