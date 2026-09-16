<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Unit;

use Drupal\Core\Cache\Cache;
use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\Context\CacheContextsManager;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\data_surface\Options\OptionSet;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the value object a resolved list of allowed values arrives in.
 *
 * Two rules live here and nowhere else. A definition carrying more than
 * one list of allowed values allows only what every one of them allows,
 * because every constraint on it has to hold at once — so intersect() is
 * the whole of "what may this key be" for a definition with two
 * constraints, and a set that quietly widened would offer a person a
 * value the validator then refuses. And a list is only reusable for as
 * long as its shorter-lived half: a permanent list intersected with one
 * read from site state is not permanent, and saying otherwise is how a
 * form holding live options gets cached as though it were static.
 *
 * @see \Drupal\data_surface\Options\OptionSet
 */
#[Group('data_surface')]
class OptionSetTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   *
   * Merging cache contexts asks the contexts manager whether each token
   * is one the site knows, so even this much of Drupal's cacheability
   * needs a container. A stub that accepts every token is enough: which
   * tokens are valid is core's question, and what is under test is that
   * both sets' contexts arrive in the answer.
   */
  protected function setUp(): void {
    parent::setUp();
    $contexts_manager = $this->createMock(CacheContextsManager::class);
    $contexts_manager->method('assertValidTokens')->willReturn(TRUE);
    $container = new ContainerBuilder();
    $container->set('cache_contexts_manager', $contexts_manager);
    \Drupal::setContainer($container);
  }

  /**
   * Tests that the intersection keeps only what both sets allow.
   */
  public function testIntersectKeepsOnlyCommonValues(): void {
    $first = new OptionSet(['a' => 'A', 'b' => 'B', 'c' => 'C']);
    $second = new OptionSet(['b' => 'Bee', 'c' => 'Cee', 'd' => 'Dee']);

    $this->assertSame(['b', 'c'], array_keys($first->intersect($second)->options));
    // Intersection is symmetric in which values survive, whichever set
    // is asked.
    $this->assertSame(['b', 'c'], array_keys($second->intersect($first)->options));
  }

  /**
   * Tests that the set being asked owns the labels of what survives.
   *
   * The receiver is the definition's own reading of its own constraint;
   * the argument is whatever is being narrowed against it. A label is
   * how a value is named to a person, and it belongs to the declaration
   * that is doing the naming, so the receiver's wins and the other set
   * only fills in a value the receiver did not name at all.
   */
  public function testIntersectLabelPrecedence(): void {
    $first = new OptionSet(['b' => 'B', 'c' => 'C'], ['b' => 'Help for B']);
    $second = new OptionSet(
      ['b' => 'Bee', 'c' => 'Cee'],
      ['b' => 'Other help for B', 'c' => 'Help for C'],
    );

    $intersected = $first->intersect($second);

    // Labels come from the set being asked.
    $this->assertSame(['b' => 'B', 'c' => 'C'], $intersected->options);
    // Help text likewise, with the other set filling in what the first
    // left unsaid.
    $this->assertSame(
      ['b' => 'Help for B', 'c' => 'Help for C'],
      $intersected->descriptions,
    );
  }

  /**
   * Tests that help text for a refused value is dropped with it.
   */
  public function testIntersectDropsDescriptionsOfRemovedValues(): void {
    $first = new OptionSet(['a' => 'A', 'b' => 'B'], ['a' => 'Help for A']);
    $second = new OptionSet(['b' => 'B'], ['b' => 'Help for B']);

    $intersected = $first->intersect($second);

    $this->assertSame(['b' => 'B'], $intersected->options);
    $this->assertSame(['b' => 'Help for B'], $intersected->descriptions);
  }

  /**
   * Tests that a set with no cacheability of its own is permanent.
   */
  public function testListReadFromTheConstraintAloneIsPermanent(): void {
    $set = new OptionSet(['a' => 'A']);

    $this->assertSame([], $set->getCacheTags());
    $this->assertSame([], $set->getCacheContexts());
    $this->assertSame(Cache::PERMANENT, $set->getCacheMaxAge());
  }

  /**
   * Tests that the intersection carries the cacheability of both sets.
   */
  public function testIntersectMergesCacheability(): void {
    $first = new OptionSet(
      ['a' => 'A', 'b' => 'B'],
      [],
      (new CacheableMetadata())
        ->setCacheTags(['node_list'])
        ->setCacheContexts(['languages:language_interface'])
        ->setCacheMaxAge(600),
    );
    $second = new OptionSet(
      ['b' => 'B'],
      [],
      (new CacheableMetadata())
        ->setCacheTags(['user_list'])
        ->setCacheContexts(['user.permissions'])
        ->setCacheMaxAge(60),
    );

    $intersected = $first->intersect($second);

    // Tags and contexts are the union: the answer changes when either
    // half of it changes.
    $this->assertSame(['node_list', 'user_list'], $intersected->getCacheTags());
    $this->assertSame(
      ['languages:language_interface', 'user.permissions'],
      $intersected->getCacheContexts(),
    );
    // The max age is the shorter of the two: an answer is only reusable
    // for as long as its shortest lived half.
    $this->assertSame(60, $intersected->getCacheMaxAge());
  }

  /**
   * Tests that a permanent set intersected with a live one is not permanent.
   */
  public function testPermanentIntersectedWithLiveIsNotPermanent(): void {
    $permanent = new OptionSet(['a' => 'A', 'b' => 'B']);
    $live = new OptionSet(
      ['b' => 'B'],
      [],
      (new CacheableMetadata())->setCacheMaxAge(30)->setCacheTags(['node_list']),
    );

    $this->assertSame(30, $permanent->intersect($live)->getCacheMaxAge());
    // And in the other direction, because the rule is about the answer
    // rather than about which set was asked.
    $this->assertSame(30, $live->intersect($permanent)->getCacheMaxAge());
  }

  /**
   * Tests that an uncacheable half makes the whole answer uncacheable.
   */
  public function testUncacheableHalfWins(): void {
    $permanent = new OptionSet(['a' => 'A']);
    $uncacheable = new OptionSet(
      ['a' => 'A'],
      [],
      (new CacheableMetadata())->setCacheMaxAge(0),
    );

    $this->assertSame(0, $permanent->intersect($uncacheable)->getCacheMaxAge());
  }

}
