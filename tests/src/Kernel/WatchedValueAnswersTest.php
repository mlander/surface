<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\surface\Contract\DefinitionSchema;
use Drupal\surface\Options\OptionSet;
use Drupal\surface\Options\SurfaceOptionsResolverInterface;
use Drupal\surface\Plugin\Validation\Constraint\LabeledChoiceConstraint;
use Drupal\surface\Refinement\WatchedValueAnswers;
use Drupal\surface\Refinement\WatchedValueCheckInterface;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;
use Symfony\Component\Validator\Constraint;

/**
 * Tests what the watched-value check keeps for a request, and when not.
 *
 * @see \Drupal\surface\Refinement\WatchedValueAnswers
 * @see docs/decisions.md#a-watched-value-is-asked-once-per-request
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class WatchedValueAnswersTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * What the Choice with a callback offers (offered()).
   *
   * @var string[]
   */
  protected static array $offered = ['system'];

  /**
   * What the per-account resolver offers, by account.
   *
   * @var array<int, string[]>
   */
  public static array $perAccount = [];

  /**
   * The account the per-account resolver answers for.
   *
   * A resolver may not read the current user (PipelineGuard), so the test
   * says which account it stands for.
   */
  public static int $account = 0;

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface'];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->setUpCurrentUser([], [], TRUE);
  }

  /**
   * Tests a kept answer is dropped on any cache tag invalidation.
   */
  public function testKeptAnswerDroppedOnInvalidation(): void {
    $schema = self::choice();
    $this->assertTrue($this->check()->admits($schema, 'free'));
    $this->assertFalse($this->check()->admits($schema, 'gold'));
    $this->assertCount(2, $this->kept());
    // The same question is answered from what was kept.
    $this->assertTrue($this->check()->admits($schema, 'free'));
    $this->assertCount(2, $this->kept());
    $this->container->get('cache_tags.invalidator')->invalidateTags(['surface_test_anything']);
    $this->assertSame([], $this->kept());
    $this->assertSame([], $this->holding());
    $this->assertFalse($this->check()->admits($schema, 'gold'));
    $this->assertCount(1, $this->kept());
  }

  /**
   * Tests a check that reads the site is asked again each time.
   *
   * A reference stops being valid inside the request, with no cache tag
   * invalidated: its rows are deleted from the database and the storage's
   * static cache is reset. A Choice's callback answers otherwise. The
   * check answers again at once each time, and a pure schema's kept answer
   * is still kept, so nothing was forgotten.
   */
  public function testSiteDependentCheckIsNotKept(): void {
    $account = $this->createUser();
    $id = (int) $account->id();
    $reference = self::checked('integer', 'SurfaceValidReference', ['entityTypeId' => 'user']);
    $this->assertStringContainsString('drupal:SurfaceValidReference', (string) json_encode($reference->toDocument()));
    $this->assertTrue($this->check()->admits(self::choice(), 'paid'));
    $this->assertTrue($this->check()->admits($reference, $id));
    $this->assertTrue($this->check()->admits($reference, $id));
    $this->assertCount(1, $this->kept());

    $database = $this->container->get('database');
    $database->delete('users_field_data')->condition('uid', $id)->execute();
    $database->delete('users')->condition('uid', $id)->execute();
    $this->container->get('entity_type.manager')->getStorage('user')->resetCache([$id]);

    $this->assertFalse($this->check()->admits($reference, $id));
    // Nothing was invalidated: the pure answer is still kept.
    $this->assertCount(1, $this->kept());

    // A choice whose callback answers otherwise inside the request, with
    // no cache tag invalidated, is asked again.
    $callback = self::checked('string', 'Choice', ['callback' => [self::class, 'offered']]);
    $this->assertFalse($this->check()->admits($callback, 'gold'));
    static::$offered = ['gold'];
    $this->assertTrue($this->check()->admits($callback, 'gold'));
    $this->assertCount(1, $this->kept());
  }

  /**
   * Tests the answers serialize without what they keep.
   */
  public function testSerializesWithoutWhatItKept(): void {
    $answers = new WatchedValueAnswers($this->container->get('surface.contract_emitter'));
    $this->assertTrue($answers->answer(self::choice(), 'free', static fn (): bool => TRUE));
    $copy = unserialize(serialize($answers), ['allowed_classes' => [WatchedValueAnswers::class]]);
    $this->assertInstanceOf(WatchedValueAnswers::class, $copy);
    $this->assertFalse($copy->answer(self::choice(), 'free', static fn (): bool => FALSE));
  }

  /**
   * Tests a schema whose check is not a pure one keeps no answer.
   */
  public function testImpureChecksKeepNoAnswer(): void {
    $schemas = [
      'another check' => self::checked('string', 'ExtensionExists', ['type' => 'module']),
      'a choice with a callback' => self::checked('string', 'Choice', ['callback' => [self::class, 'offered']]),
    ];
    foreach ($schemas as $name => $schema) {
      $this->assertTrue($this->check()->admits($schema, 'system'), $name);
      $this->assertTrue($this->check()->admits($schema, 'system'), $name);
      $this->assertSame([], $this->kept(), $name);
    }
  }

  /**
   * Tests an option list read for one account keeps no answer.
   *
   * A resolver whose list varies by account says so with the `user` cache
   * context. Its document may change inside the request, so nothing is
   * answered from what was kept: the second account is answered by its
   * own list.
   */
  public function testPerAccountOptionListKeepsNoAnswer(): void {
    $options = $this->container->get('surface.options');
    $resolvers = (new \ReflectionMethod($options, 'resolvers'))->invoke($options);
    $resolvers['surface_test_per_account'] = new class() implements SurfaceOptionsResolverInterface {

      /**
       * {@inheritdoc}
       */
      public function applies(Constraint $constraint): bool {
        return $constraint instanceof LabeledChoiceConstraint;
      }

      /**
       * {@inheritdoc}
       */
      public function resolve(Constraint $constraint, Schema $schema): OptionSet {
        return new OptionSet(array_fill_keys(WatchedValueAnswersTest::$perAccount[WatchedValueAnswersTest::$account], 'Plan'), [], (new CacheableMetadata())->addCacheContexts(['user']));
      }

    };
    (new \ReflectionProperty($options, 'resolvers'))->setValue($options, $resolvers);
    $first = $this->createUser();
    $second = $this->createUser();
    static::$perAccount = [(int) $first->id() => ['free'], (int) $second->id() => ['free', 'paid']];

    $this->setCurrentUser($first);
    static::$account = (int) $first->id();
    $this->assertFalse($this->check()->admits(self::choice(), 'paid'));
    $this->assertSame([], $this->kept());
    $this->setCurrentUser($second);
    static::$account = (int) $second->id();
    $this->assertTrue($this->check()->admits(self::choice(), 'paid'));
    $this->setCurrentUser($first);
    static::$account = (int) $first->id();
    $this->assertFalse($this->check()->admits(self::choice(), 'paid'));
  }

  /**
   * Tests a float is held by the type of the key it is at.
   *
   * A float past PHP's integers at an integer key is not handed to a
   * refiner typed for an integer; a float at a float key is, alone and in
   * a list.
   */
  public function testFloatsAreHeldByTheirKeysType(): void {
    $this->assertFalse($this->check()->admits(DefinitionSchema::of(DataDefinition::create('integer')), 1e20));
    $this->assertFalse($this->check()->admits(DefinitionSchema::of(DataDefinition::create('timestamp')), 1e20));
    $this->assertTrue($this->check()->admits(DefinitionSchema::of(DataDefinition::create('integer')), 3));
    $this->assertTrue($this->check()->admits(DefinitionSchema::of(DataDefinition::create('float')), 1.5));
    $this->assertTrue($this->check()->admits(DefinitionSchema::of(new ListDataDefinition([], DataDefinition::create('float'))), [1.5]));
  }

  /**
   * Lists what the Choice with a callback offers.
   *
   * @return string[]
   *   The values.
   */
  public static function offered(): array {
    return static::$offered;
  }

  /**
   * Makes a labeled choice's schema, whose check is a pure one.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema.
   */
  protected static function choice(): Schema {
    return self::checked('string', 'LabeledChoice', ['choices' => ['free' => 'Free', 'paid' => 'Paid']]);
  }

  /**
   * Makes the schema of a value of a type with one constraint.
   *
   * @param string $type
   *   The data type.
   * @param string $constraint
   *   The constraint's plugin id.
   * @param array $options
   *   Its options.
   *
   * @return \PortableSchema\Builder\Schema
   *   The schema, its constraint written as a check.
   */
  protected static function checked(string $type, string $constraint, array $options): Schema {
    return DefinitionSchema::of(DataDefinition::create($type)->addConstraint($constraint, $options));
  }

  /**
   * Gets the watched-value check.
   *
   * @return \Drupal\surface\Refinement\WatchedValueCheckInterface
   *   The module's check.
   */
  protected function check(): WatchedValueCheckInterface {
    return $this->container->get('surface.watched_value_check');
  }

  /**
   * Gets the answers kept.
   *
   * @return array<string, bool>
   *   The answers, by the schema's and the value's hash.
   */
  protected function kept(): array {
    return (new \ReflectionProperty(WatchedValueAnswers::class, 'answers'))->getValue($this->container->get('surface.watched_value_answers'));
  }

  /**
   * Gets the holding data surfaces kept.
   *
   * @return array<string, array>
   *   The data surfaces, by the schema's hash.
   */
  protected function holding(): array {
    return (new \ReflectionProperty(WatchedValueAnswers::class, 'holding'))->getValue($this->container->get('surface.watched_value_answers'));
  }

}
