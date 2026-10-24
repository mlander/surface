<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\data_surface\Options\DataSurfaceOptions;
use Drupal\data_surface\Resource\AccessAnswer;
use Drupal\data_surface\Resource\OperationAccessInterface;
use Drupal\data_surface\Resource\OperationContext;
use Drupal\data_surface_examples\Resource\RegistrationStep3Resource;
use Drupal\data_surface_resource_test\Access\ArchiveAccess;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * A worker that outlives a request does not keep what each call made.
 *
 * An MCP stdio server, a queue worker or a Drush batch validates, emits
 * and builds for as long as it runs. Every refine makes new definitions,
 * which the options memo kept for good (about 2.5 KB a call), and an
 * update context per entity added a static access answer per entity id.
 * Both now let go: the options memo is weak, and the static answers keep
 * only the latest. So do the sealed builds the build step keeps for a
 * request: a worker building in one context after another keeps the
 * latest few.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class LongLivedWorkerMemoryTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'field',
    'serialization',
    'tool',
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Tests that a thousand validations of example 3 keep nothing.
   *
   * Each one refines afresh, with a capacity of its own, so each makes
   * new definitions; once it returns nothing should hold them. Before
   * the memo was weak a thousand of them kept about 2.7 MB; now none.
   */
  public function testValidatingThousandsOfTimesKeepsNothing(): void {
    $resources = $this->container->get('data_surface.resources');
    $surface = $resources->build(RegistrationStep3Resource::class, $resources->operation(RegistrationStep3Resource::class, 'configure'));
    $values = [
      'title' => 'Meetup',
      'venue' => 'library',
      'room' => 'library_reading',
      'pricing' => 'paid',
      'ticket' => ['price' => 12.5, 'currency' => 'EUR'],
      'contact' => ['email' => 'events@example.com'],
    ];
    $validate = function (int $from, int $to) use ($surface, $values): void {
      for ($capacity = $from; $capacity < $to; $capacity++) {
        $this->pipeline()->validate($surface, ['capacity' => $capacity] + $values);
      }
    };
    $this->assertTrue($this->pipeline()->validate($surface, ['capacity' => 40] + $values)->isEmpty());
    // A warm-up, then a thousand more, no capacity asked twice.
    $validate(0, 100);
    gc_collect_cycles();
    $warm = memory_get_usage();
    $validate(100, 1100);
    gc_collect_cycles();
    $grown = memory_get_usage() - $warm;
    $this->assertLessThan(256 * 1024, $grown, sprintf('A thousand validations kept %d bytes.', $grown));
  }

  /**
   * Tests that the options memo holds no entry for a released definition.
   */
  public function testTheOptionsMemoLetsReleasedDefinitionsGo(): void {
    $options = $this->options();
    $memo = new \ReflectionProperty($options, 'resolved');
    $held = fn (): int => count($memo->getValue($options));
    $before = $held();

    $definition = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['a', 'b']]);
    $this->assertSame(['a', 'b'], array_keys($options->resolve($definition)->options ?? []));
    $this->assertSame($before + 1, $held());
    unset($definition);
    gc_collect_cycles();
    $this->assertSame($before, $held());

    // A service that holds it may be serialized (a form's), and the memo
    // is left behind and starts again empty.
    $kept = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['c']]);
    $options->resolve($kept);
    $woken = unserialize(serialize($options), ['allowed_classes' => [DataSurfaceOptions::class]]);
    $this->assertCount(0, $memo->getValue($woken));
    $this->assertSame(['c'], array_keys($woken->resolve($kept)->options ?? []));
  }

  /**
   * Tests that static access answers for many entities stay bounded.
   *
   * A static answer may read the identity it is asked about (the field
   * item access answers that a field is missing for an entity, by the
   * account's permissions alone), so the memo is keyed by what the
   * context knows and bounded, never keyed without it. Asked as a child's
   * access is, for one thing after another.
   */
  public function testStaticAnswersStayBoundedAcrossEntities(): void {
    $resources = $this->container->get('data_surface.resources');
    $memo = new \ReflectionProperty($resources, 'staticAnswers');
    $limit = (new \ReflectionClassConstant($resources, 'STATIC_ANSWERS_KEPT'))->getValue();
    $this->assertIsInt($limit);
    $ask = new \ReflectionMethod($resources, 'ask');
    $account = $this->container->get('current_user');
    for ($id = 0; $id < $limit + 300; $id++) {
      $context = new OperationContext('update', known: ['id' => $id]);
      $answer = $ask->invoke($resources, ArchiveAccess::class, OperationAccessInterface::class, $context, $account);
      $this->assertInstanceOf(AccessAnswer::class, $answer);
      $this->assertTrue($answer->isStatic());
    }
    $kept = count($memo->getValue($resources));
    $this->assertGreaterThan(0, $kept, 'Static answers are kept.');
    $this->assertLessThanOrEqual($limit, $kept);
  }

  /**
   * Tests that the sealed builds kept stay bounded across contexts.
   *
   * Each context a different one (a worker serving one entity after
   * another), each build kept until a newer one pushes it out; and a build
   * that expires (the exhibit's insurance answers access with max-age 0)
   * is never kept at all.
   */
  public function testKeptBuildsStayBoundedAcrossContexts(): void {
    $resources = $this->container->get('data_surface.resources');
    $memo = new \ReflectionProperty($resources, 'builds');
    $limit = (new \ReflectionClassConstant($resources, 'BUILDS_KEPT'))->getValue();
    $this->assertIsInt($limit);
    $context = static fn (int $capacity): OperationContext => new OperationContext('configure', constraints: [
      'capacity' => ['Range' => ['min' => 1, 'max' => $capacity]],
    ]);
    for ($capacity = 1; $capacity <= $limit + 300; $capacity++) {
      $resources->build(RegistrationStep3Resource::class, $context($capacity));
    }
    $this->assertCount($limit, $memo->getValue($resources));
    // The latest is kept, and answered from the memo.
    $counts = $resources->buildCounts();
    $resources->build(RegistrationStep3Resource::class, $context($limit + 300));
    $this->assertSame($counts['reused'] + 1, $resources->buildCounts()['reused']);

    $resources->forgetBuilds();
    $exhibit = $resources->operation('resource_test.exhibit', 'configure');
    $this->assertSame(0, $resources->build('resource_test.exhibit', $exhibit)->getCacheMaxAge());
    $this->assertCount(0, $memo->getValue($resources));
  }

}
