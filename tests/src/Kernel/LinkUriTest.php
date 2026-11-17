<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a link is held to what a link field takes, in and out.
 *
 * A key held to SurfaceLinkUri takes every value a `uri` key takes, the
 * format RFC 3986 states, and Drupal's own link values beside them, which
 * the format refuses; it refuses what has no scheme. The same at an output.
 *
 * @see \Drupal\surface\Plugin\Validation\Constraint\LinkUriConstraint
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class LinkUriTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['system', 'user', 'surface'];

  /**
   * Values a link field's uri holds, and others.
   */
  protected const VALUES = [
    'https://example.com/a',
    'http://example.com/a?b=c#d',
    'internal:/node/1',
    'internal:/',
    'internal:#top',
    'internal:?a=1',
    'entity:node/1',
    'route:<nolink>',
    'route:<none>',
    'route:<button>',
    'route:<front>',
    'route:entity.node.canonical;node=1',
    'route:system.admin',
    'base:robots.txt',
    'mailto:a@example.com',
    'tel:+15550100',
    'urn:isbn:0451450523',
    'https://example.com/a b',
    '/node/1',
    'node/1',
    '//example.com/a',
    'no scheme',
    '',
  ];

  /**
   * The values the link check takes that a `uri` key refuses.
   */
  protected const WIDER = [
    'route:<nolink>',
    'route:<none>',
    'route:<button>',
    'route:<front>',
    'https://example.com/a b',
  ];

  /**
   * Tests an input link key takes more than a `uri` key and refuses less.
   */
  public function testLinkTakesWhatLinkFieldTakes(): void {
    $uri = $this->surface(DataDefinition::create('uri')->setLabel('Link'));
    $link = $this->surface(DataDefinition::create('string')->setLabel('Link')->addConstraint('SurfaceLinkUri'));
    $wider = [];
    foreach (self::VALUES as $value) {
      $before = $this->accepted($uri, $value);
      $now = $this->accepted($link, $value);
      if ($before) {
        $this->assertTrue($now, sprintf('"%s" was accepted, and still is.', $value));
      }
      if ($now && !$before) {
        $wider[] = $value;
      }
      // An output is held as an input is.
      $this->assertSame($now, $this->pipeline()->conformOutput($link, ['link' => $value])->isEmpty(), sprintf('"%s" at an output.', $value));
    }
    $this->assertSame(self::WIDER, $wider);
    $this->assertFalse($this->accepted($link, '/node/1'));
    $said = iterator_to_array($this->pipeline()->validate($link, $this->pipeline()->accept($link, ['link' => 'no scheme'])));
    $this->assertSame('check.drupal:SurfaceLinkUri', $said[0]->code);
    $this->assertSame('This value is not a valid link: it needs a scheme, such as https:, internal: or route:.', (string) $said[0]->message);
  }

  /**
   * Builds a surface holding one key, as an input and as an output.
   *
   * @param \Drupal\Core\TypedData\DataDefinition $definition
   *   The key.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  protected function surface(DataDefinition $definition): SurfaceInterface {
    $builder = new SurfaceBuilder(['link' => $definition]);
    $builder->setOutputDefinition('link', clone $definition);
    return $builder->seal();
  }

  /**
   * Answers whether the pipeline accepts and validates a value.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param string $value
   *   The value.
   *
   * @return bool
   *   TRUE when nothing refuses it.
   */
  protected function accepted(SurfaceInterface $surface, string $value): bool {
    return $this->pipeline()->validate($surface, $this->pipeline()->accept($surface, ['link' => $value]))->isEmpty();
  }

}
