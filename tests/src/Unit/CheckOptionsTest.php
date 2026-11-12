<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Unit;

use Drupal\surface\Contract\CheckOptions;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests what a document publishes of a check's options, plugin by plugin.
 *
 * The rule: a reference publishes what it refers to, `{entityTypeId,
 * bundles?}`; JsonSchema the schema it holds a value to, `{schema}`; every
 * other plugin `{}`, whatever options its definition holds.
 *
 * @see docs/decisions.md#a-definition-is-read-once-into-a-built-node
 */
#[Group('surface')]
class CheckOptionsTest extends UnitTestCase {

  /**
   * Lists each plugin with the options a definition holds and what is said.
   *
   * @return array<string, array{0: string, 1: mixed, 2: string}>
   *   The plugin id, its options, and the published options as JSON.
   */
  public static function rows(): array {
    $schema = ['type' => 'object', 'required' => ['name']];
    $reference = [
      'entityTypeId' => 'node',
      'bundles' => ['article'],
      'handler' => 'default:node',
      'handlerSettings' => ['auto_create' => FALSE],
    ];
    $unique = [
      'storage' => 'Drupal\example\Storage',
      'key' => 'id',
      'thing' => 'venue',
      'reason' => 'Taken.',
    ];
    $targets = [
      'interface' => 'Drupal\example\EntityInterface',
      'targets' => ['node' => []],
    ];
    return [
      'a reference: its target, the handler left out' => [
        'SurfaceValidReference',
        $reference,
        '{"entityTypeId":"node","bundles":["article"]}',
      ],
      'a reference to no bundle' => [
        'SurfaceValidReference',
        ['entityTypeId' => 'event_venue'],
        '{"entityTypeId":"event_venue"}',
      ],
      'a reference whose bundles are numbers' => [
        'SurfaceValidReference',
        ['entityTypeId' => 'taxonomy_term', 'bundles' => [7]],
        '{"entityTypeId":"taxonomy_term","bundles":["7"]}',
      ],
      'a reference naming no entity type' => ['SurfaceValidReference', ['bundles' => ['article']], '{}'],
      'a reference with no options' => ['SurfaceValidReference', NULL, '{}'],
      'JsonSchema: its schema' => [
        'JsonSchema',
        ['schema' => $schema],
        '{"schema":{"type":"object","required":["name"]}}',
      ],
      'JsonSchema with an empty schema' => ['JsonSchema', ['schema' => []], '{"schema":{}}'],
      'JsonSchema with no schema' => ['JsonSchema', [], '{"schema":{}}'],
      'JsonSchema whose options are no array' => ['JsonSchema', NULL, '{}'],
      'SurfaceUnique: nothing of its storage' => ['SurfaceUnique', $unique, '{}'],
      'SurfaceReferenceableType: nothing of its targets' => ['SurfaceReferenceableType', $targets, '{}'],
      'LabeledChoice named as a check' => ['LabeledChoice', ['choices' => ['a' => 'A']], '{}'],
      'Sequentially: nothing of its parts' => [
        'Sequentially',
        ['constraints' => [['Length' => ['max' => 3]]]],
        '{}',
      ],
      'Email beside its format' => ['Email', [], '{}'],
      'a core plugin with a scalar option' => ['PluginExists', 'plugin.manager.block', '{}'],
      'a core plugin with no options' => ['NotBlank', NULL, '{}'],
    ];
  }

  /**
   * Tests the table, one plugin at a time.
   */
  #[DataProvider('rows')]
  public function testPublished(string $id, mixed $options, string $published): void {
    $this->assertSame($published, json_encode(CheckOptions::published($id, $options), JSON_UNESCAPED_SLASHES));
  }

}
