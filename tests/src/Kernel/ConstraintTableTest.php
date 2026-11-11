<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\surface\Contract\ConstraintKeywords;
use Drupal\surface\Contract\ContractEmitter;
use Drupal\surface\SurfaceBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the table of constraints and keywords against core and the emitter.
 *
 * A row's keywords accept what the constraint accepts, value by value:
 * each case holds a value to core's constraint, as the server does, and to
 * the keywords the table writes for it, through the JsonSchema constraint
 * a standard validator checks, and the two verdicts are the same for every
 * value, so the table cannot drift from what the validator checks. Through
 * the emitter, a constraint the schema does not state is named under
 * `x-surface.checkedOnServer`, never dropped.
 *
 * @see \Drupal\surface\Contract\ConstraintKeywords
 * @see docs/audit-never-stricter.md
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ConstraintTableTest extends SurfaceKernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
  ];

  /**
   * Gives the cases: a constraint, its options, a value type, the values.
   *
   * @return array<string, array{0: string, 1: array, 2: string, 3: list<mixed>, 4: array}>
   *   The constraint, its options, `string` or `list`, the values, and the
   *   keywords the table writes.
   */
  public static function cases(): array {
    $strings = ['ab', 'abc', 'abcd', 'abcde', 'abcdef'];
    $lists = [['a'], ['a', 'b'], ['a', 'b', 'c'], ['a', 'b', 'c', 'd', 'e'], ['a', 'b', 'c', 'd', 'e', 'f']];
    return [
      // Beside a bound, Symfony ignores `exactly`.
      'Length exactly beside min' => ['Length', ['exactly' => 5, 'min' => 3], 'string', $strings, ['minLength' => 3]],
      'Length exactly beside max' => ['Length', ['exactly' => 2, 'max' => 4], 'string', $strings, ['maxLength' => 4]],
      'Length exactly' => ['Length', ['exactly' => 5], 'string', $strings, ['minLength' => 5, 'maxLength' => 5]],
      'Count exactly beside max' => ['Count', ['exactly' => 2, 'max' => 5], 'list', $lists, ['maxItems' => 5]],
      'Count exactly beside min' => ['Count', ['exactly' => 5, 'min' => 2], 'list', $lists, ['minItems' => 2]],
      'Count exactly' => ['Count', ['exactly' => 2], 'list', $lists, ['minItems' => 2, 'maxItems' => 2]],
    ];
  }

  /**
   * Tests the server and the keywords agree on every value.
   */
  #[DataProvider('cases')]
  public function testKeywordsAgreeWithTheConstraint(string $id, array $options, string $type, array $values, array $keywords): void {
    $written = ConstraintKeywords::state([$id => $options], [$type === 'list' ? 'array' : 'string']);
    $this->assertSame($keywords, $written['keywords']);
    $this->assertSame([], $written['left']);
    foreach ($values as $value) {
      $server = $this->accepts($this->definition($type)->addConstraint($id, $options), $value);
      $contract = $this->accepts($this->definition($type)->addConstraint('JsonSchema', ['schema' => $written['keywords']]), $value);
      $this->assertSame($server, $contract, sprintf('%s %s: the keywords %s %s as the server does.', $id, json_encode($options), $server ? 'accept' : 'refuse', json_encode($value)));
    }
  }

  /**
   * Gives each row on a type it does not apply to.
   *
   * @return array<string, array{0: string, 1: string, 2: array}>
   *   The data type (`list` for a list of strings), the constraint and its
   *   options, by constraint.
   */
  public static function notApplying(): array {
    return [
      'Range' => ['string', 'Range', ['min' => 1]],
      'Length' => ['integer', 'Length', ['max' => 3]],
      'Count' => ['string', 'Count', ['max' => 3]],
      'UniqueItems' => ['string', 'UniqueItems', []],
      'NotBlank' => ['list', 'NotBlank', []],
      'Email' => ['integer', 'Email', []],
      'Regex' => ['integer', 'Regex', ['pattern' => '/^1$/D']],
      'Uuid' => ['boolean', 'Uuid', []],
      'Sequentially' => ['integer', 'Sequentially', ['constraints' => [['Length' => ['min' => 1]]]]],
      'AtLeastOneOf' => ['string', 'AtLeastOneOf', ['constraints' => [['Range' => ['min' => 1]]]]],
    ];
  }

  /**
   * Tests the cases walk the whole table.
   */
  public function testEveryRowIsWalked(): void {
    $this->assertEqualsCanonicalizing(ConstraintKeywords::ids(), array_keys(static::notApplying()));
  }

  /**
   * Tests the emitter names a row on a type it does not apply to.
   */
  #[DataProvider('notApplying')]
  public function testRowNotApplyingIsCheckedOnServer(string $type, string $id, array $options): void {
    $definition = ($type === 'list' ? ListDataDefinition::create('string') : DataDefinition::create($type))->setLabel('Key')->addConstraint($id, $options);
    $emitted = $this->emitted(['key' => $definition])['key'];
    $this->assertSame([$id], array_column($emitted[ContractEmitter::EXTENSION]['checkedOnServer'] ?? [], 'constraint'), $id . ' is named under checkedOnServer.');
    $written = ConstraintKeywords::row($id)->write($id, $options, ['string', 'integer', 'number', 'array', 'boolean']);
    foreach (array_keys($written->keywords ?? []) as $keyword) {
      $this->assertArrayNotHasKey($keyword, $emitted, $id . ' writes no ' . $keyword . '.');
    }
  }

  /**
   * Tests a list of values the schema cannot state is checked on the server.
   *
   * A Choice that allows all but its values (`match` FALSE) is no list
   * of what is allowed, for any consumer: the emitter writes no `oneOf`
   * and the options service reads no list, so no form or tool offers one.
   * A Choice on a list definition writes no list (the `oneOf` is the
   * items'), and one of several values has its `min` and `max` checked on
   * an array no list is written for.
   */
  public function testListsTheSchemaCannotState(): void {
    $refusing = ['choices' => ['a', 'b'], 'match' => FALSE];
    $labeled = ['labels' => ['a' => 'A'], 'match' => FALSE];
    $several = ['choices' => ['a', 'b', 'c'], 'multiple' => TRUE];
    $definitions = [
      'not' => DataDefinition::create('string')->addConstraint('Choice', $refusing),
      'not_zero' => DataDefinition::create('string')->addConstraint('Choice', ['match' => 0] + $refusing),
      'labeled_not' => DataDefinition::create('string')->addConstraint('LabeledChoice', $labeled),
      'on_list' => ListDataDefinition::create('string')->addConstraint('Choice', ['min' => 2] + $several),
      'several' => DataDefinition::create('string')->addConstraint('Choice', ['max' => 2] + $several),
      'plain' => DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['a', 'b']]),
    ];
    $emitted = $this->emitted(array_map(static fn (DataDefinitionInterface $definition): DataDefinitionInterface => $definition->setLabel('Key'), $definitions));
    foreach (['not' => 'Choice', 'not_zero' => 'Choice', 'labeled_not' => 'LabeledChoice', 'on_list' => 'Choice'] as $key => $constraint) {
      $this->assertSame([$constraint], array_column($emitted[$key][ContractEmitter::EXTENSION]['checkedOnServer'] ?? [], 'constraint'), $key . ' is checked on the server.');
      $this->assertArrayNotHasKey('oneOf', $emitted[$key], $key . ' states no list.');
      $this->assertArrayNotHasKey('oneOf', $emitted[$key]['items'] ?? [], $key . ' states no list of items.');
    }
    foreach (['not', 'not_zero', 'labeled_not'] as $key) {
      $this->assertNull($this->options()->resolve($definitions[$key]), $key . ' offers no options.');
    }
    $this->assertSame(['Choice'], array_column($emitted['several'][ContractEmitter::EXTENSION]['checkedOnServer'], 'constraint'), 'Several values: checked on the server.');
    $this->assertArrayNotHasKey('checkedOnServer', $emitted['plain'][ContractEmitter::EXTENSION] ?? []);
    $this->assertSame(['a', 'b'], array_values(array_filter(array_column($emitted['plain']['oneOf'], 'const'))));
  }

  /**
   * Emits the full schema of definitions, by key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface[] $definitions
   *   The definitions, by key.
   *
   * @return array
   *   The properties.
   */
  protected function emitted(array $definitions): array {
    $builder = new SurfaceBuilder();
    foreach ($definitions as $key => $definition) {
      $builder->setDefinition($key, $definition);
    }
    return $this->container->get('surface.contract_emitter')->emitFull($builder->seal(), 'test.table')->document['schema']['properties'];
  }

  /**
   * Makes an empty definition of a value type.
   *
   * @param string $type
   *   The value type: `string` or `list`.
   *
   * @return \Drupal\Core\TypedData\DataDefinitionInterface
   *   The definition.
   */
  protected function definition(string $type): DataDefinitionInterface {
    return $type === 'list' ? ListDataDefinition::create('string') : DataDefinition::create('string');
  }

  /**
   * Answers whether a definition accepts a value.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   * @param mixed $value
   *   The value.
   *
   * @return bool
   *   TRUE when validating it gives no violation.
   */
  protected function accepts(DataDefinitionInterface $definition, mixed $value): bool {
    return count($this->container->get('typed_data_manager')->create($definition, $value)->validate()) === 0;
  }

}
