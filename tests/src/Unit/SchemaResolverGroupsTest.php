<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\SchemaResolver;
use Drupal\surface\Contract\Schemas;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;
use PortableSchema\Builder\Schema;

/**
 * Tests a frame's groups survive what SchemaResolver lays over them.
 *
 * A narrowing declares only the groups its own keys name, and a structure
 * mixed in declares its groups on its def: resolved, each frame holds
 * every group its keys name, the site's or the frame's word standing for
 * a group both declare.
 *
 * @see docs/decisions.md#the-ui-hints-are-x-portable-ui
 */
#[Group('surface')]
class SchemaResolverGroupsTest extends UnitTestCase {

  /**
   * The def of a structure whose keys are drawn in two groups.
   */
  protected const CONTACT = [
    'type' => 'object',
    'properties' => [
      'email' => ['type' => 'string', 'x-portable-ui' => ['group' => 'reach']],
      'phone' => ['type' => 'string', 'x-portable-ui' => ['group' => 'call']],
    ],
    'x-portable-ui' => [
      'groups' => [
        'reach' => ['label' => 'Reach', 'widget' => 'fieldset'],
        'call' => ['label' => 'Call', 'widget' => 'details', 'options' => ['open' => FALSE]],
      ],
    ],
  ];

  /**
   * Tests a narrowed site keeps the def's groups its narrowing does not name.
   */
  public function testNarrowingJoinsTheDefsGroups(): void {
    $root = [
      'type' => 'object',
      'properties' => [
        'first' => ['$ref' => '#/$defs/contact'],
        'second' => [
          '$ref' => '#/$defs/contact',
          'properties' => ['email' => ['type' => 'string', 'minLength' => 3, 'x-portable-ui' => ['group' => 'reach']]],
          'x-portable-ui' => ['groups' => ['reach' => ['label' => 'Reach us', 'widget' => 'fieldset']]],
        ],
      ],
      '$defs' => ['contact' => self::CONTACT],
    ];
    $inline = Schemas::decoded(SchemaResolver::inline(Schema::from($root)));
    $this->assertSame(self::CONTACT['x-portable-ui'], $inline['properties']['first']['x-portable-ui']);
    $this->assertSame([
      'groups' => [
        'reach' => ['label' => 'Reach us', 'widget' => 'fieldset'],
        'call' => ['label' => 'Call', 'widget' => 'details', 'options' => ['open' => FALSE]],
      ],
    ], $inline['properties']['second']['x-portable-ui'], 'The def\'s other group stays; the site\'s word stands for the one both declare.');
    $this->assertSame(['group' => 'call'], $inline['properties']['second']['properties']['phone']['x-portable-ui']);

    // A site's own hints replace the def's whole, its groups apart.
    $root['properties']['second']['x-portable-ui']['widget'] = 'fieldset';
    $second = Schemas::decoded(SchemaResolver::inline(Schema::from($root)))['properties']['second']['x-portable-ui'];
    $this->assertSame(['widget', 'groups'], array_keys($second));
    $this->assertSame(['reach', 'call'], array_keys($second['groups']));
  }

  /**
   * Tests a mixed-in structure's groups join the frame's.
   */
  public function testMixInJoinsItsGroups(): void {
    $root = [
      'type' => 'object',
      'properties' => [
        'name' => ['type' => 'string', 'x-portable-ui' => ['group' => 'call']],
        'email' => TRUE,
        'phone' => TRUE,
      ],
      'allOf' => [['$ref' => '#/$defs/contact']],
      'x-portable-ui' => ['groups' => ['call' => ['label' => 'Ring', 'widget' => 'fieldset']]],
      '$defs' => ['contact' => self::CONTACT],
    ];
    $inline = Schemas::decoded(SchemaResolver::inline(Schema::from($root)));
    $this->assertSame([
      'groups' => [
        'call' => ['label' => 'Ring', 'widget' => 'fieldset'],
        'reach' => ['label' => 'Reach', 'widget' => 'fieldset'],
      ],
    ], $inline['x-portable-ui'], 'The frame\'s word stands for a group both declare.');
    $this->assertSame(['group' => 'reach'], $inline['properties']['email']['x-portable-ui']);
  }

}
