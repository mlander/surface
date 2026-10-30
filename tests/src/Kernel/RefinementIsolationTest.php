<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Pipeline\ResultDocument;
use Drupal\Component\Render\PlainTextOutput;
use Drupal\Core\TypedData\ComplexDataDefinitionInterface;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\ListDataDefinitionInterface;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a build never sees another build's refinement, in one process.
 *
 * Example 3 with the compliance alter, asked twice in one process with
 * values that narrow differently, in both orders: each call gets the
 * verdict its own values call for. Three keys are asked about: the
 * stewards the alter mounted and narrows by the capacity, the owner's
 * capacity the alter narrows without a license, and the owner's
 * capacity the owner narrows by the room. The first was held to the
 * first call's minimum by every later call in the process, through
 * core's typed data prototypes; the other two are top-level keys and
 * pin that they stay right. Each order runs in a process of its own,
 * since what the first call of a process leaves behind is the point.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class RefinementIsolationTest extends SurfaceKernelTestBase {

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
    'surface',
    'surface_tool',
    'surface_examples',
    'surface_examples_compliance',
  ];

  /**
   * The module the alter lives in, which its keys are mounted under.
   */
  protected const MODULE = 'surface_examples_compliance';

  /**
   * The path the stewards count is mounted at.
   */
  protected const STEWARDS = 'third_party_settings.' . self::MODULE . '.stewards';

  /**
   * An event in Riverside's main hall, which seats four hundred.
   */
  protected const EVENT = [
    'title' => 'Meetup',
    'venue' => 'riverside',
    'room' => 'riverside_main',
    'pricing' => 'free',
  ];

  /**
   * Each key asked about, with values that narrow it and values that do not.
   *
   * Keyed by case, then 'narrow' and 'wide': the values over the event,
   * and the paths the narrow values are refused at. The wide values are
   * accepted.
   */
  protected const CASES = [
    'the alter narrows its own mounted key' => [
      'narrow' => [['capacity' => 80, 'third_party_settings' => [self::MODULE => ['stewards' => 1]]], [self::STEWARDS]],
      'wide' => ['capacity' => 50, 'third_party_settings' => [self::MODULE => ['stewards' => 1]]],
    ],
    'the alter narrows the owner\'s key' => [
      'narrow' => [['capacity' => 150, 'third_party_settings' => [self::MODULE => ['stewards' => 3]]], ['capacity']],
      'wide' => ['capacity' => 150, 'third_party_settings' => [self::MODULE => ['license' => '2048', 'stewards' => 3]]],
    ],
    'the owner narrows its own key' => [
      'narrow' => [
        [
          'venue' => 'library',
          'room' => 'library_reading',
          'capacity' => 80,
          'third_party_settings' => [self::MODULE => ['stewards' => 2]],
        ],
        ['capacity'],
      ],
      'wide' => ['capacity' => 80, 'third_party_settings' => [self::MODULE => ['stewards' => 2]]],
    ],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Provides the two orders.
   *
   * @return array<string, array{bool}>
   *   Whether the narrowing values go first.
   */
  public static function orders(): array {
    return [
      'narrow then wide' => [TRUE],
      'wide then narrow' => [FALSE],
    ];
  }

  /**
   * Tests each derived tool call is held to its own values.
   */
  #[DataProvider('orders')]
  public function testEachToolCallGetsItsOwnVerdict(bool $narrow_first): void {
    foreach (self::CASES as $case => ['narrow' => [$narrow, $refused], 'wide' => $wide]) {
      foreach ($narrow_first ? [TRUE, FALSE] : [FALSE, TRUE] as $narrowing) {
        $refusal = $this->toolRefusal(($narrowing ? $narrow : $wide) + self::EVENT);
        if (!$narrowing) {
          $this->assertNull($refusal, "$case: the wide call is accepted.");
          continue;
        }
        $this->assertNotNull($refusal, "$case: the narrow call is refused.");
        foreach ($refused as $path) {
          $this->assertStringContainsString(ResultDocument::pointer($path) . ':', $refusal, "$case: refused at $path.");
        }
      }
    }
  }

  /**
   * Tests each build validated in one process is held to its own values.
   */
  #[DataProvider('orders')]
  public function testEachBuildGetsItsOwnVerdict(bool $narrow_first): void {
    foreach (self::CASES as $case => ['narrow' => [$narrow, $refused], 'wide' => $wide]) {
      foreach ($narrow_first ? [TRUE, FALSE] : [FALSE, TRUE] as $narrowing) {
        $paths = $this->refusals($this->surface(), ($narrowing ? $narrow : $wide) + self::EVENT);
        $this->assertSame($narrowing ? $refused : [], $paths, $case . ($narrowing ? ': the narrow build' : ': the wide build'));
      }
    }
  }

  /**
   * Tests refining and validating leaves the sealed resource as built.
   *
   * One sealed resource is refined and validated against every case's
   * values, and the tool is called with them beside it: its definitions
   * at every depth, constraints, labels and descriptions included, are
   * what they were, and what a fresh build seals.
   */
  public function testRefiningLeavesTheSealedResourceAsBuilt(): void {
    $surface = $this->surface();
    $before = static::described($surface);
    foreach (self::CASES as ['narrow' => [$narrow], 'wide' => $wide]) {
      foreach ([$narrow, $wide] as $values) {
        $surface->refine($values + self::EVENT);
        $this->pipeline()->validate($surface, $values + self::EVENT);
        $this->toolRefusal($values + self::EVENT);
      }
    }
    $this->assertSame($before, static::described($surface));
    $this->assertSame($before, static::described($this->surface()));
  }

  /**
   * Tests two builds with a key of one name keep their own properties.
   *
   * The cause underneath, apart from any alter: a plain map's properties
   * and a list's items are held to the data surface being validated,
   * never to another data surface's key of the same name validated
   * earlier in the process.
   */
  public function testTwoBuildsWithOneKeyNameKeepTheirOwnRules(): void {
    $build = static fn (int $max, string $tag): SurfaceInterface => (new SurfaceBuilder([
      'settings' => MapDataDefinition::create()
        ->setPropertyDefinition('size', DataDefinition::create('integer')->addConstraint('Range', ['max' => $max])),
      'tags' => ListDataDefinition::create('string')->setItemDefinition(
        DataDefinition::create('string')->addConstraint('Choice', ['choices' => [$tag]]),
      ),
    ]))->seal();
    $values = ['settings' => ['size' => 10], 'tags' => ['b']];

    $this->assertSame(['settings.size', 'tags.0'], $this->refusals($build(5, 'a'), $values));
    $this->assertSame([], $this->refusals($build(50, 'b'), $values));
    $this->assertSame(['settings.size', 'tags.0'], $this->refusals($build(5, 'a'), $values));
  }

  /**
   * Builds step 3 in its configure operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The sealed resource.
   */
  protected function surface(): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build(RegistrationStep3Resource::class, $resources->operation(RegistrationStep3Resource::class, 'configure'));
  }

  /**
   * Validates values against a resource and lists where it refused them.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The sealed resource.
   * @param array $values
   *   The values.
   *
   * @return string[]
   *   The full paths of the violations.
   */
  protected function refusals(SurfaceInterface $surface, array $values): array {
    return array_map(
      static fn (Violation $violation): string => $violation->fullPath(),
      iterator_to_array($this->pipeline()->validate($surface, $values)),
    );
  }

  /**
   * Asks step 3's derived configure tool, as a dry run, from a new instance.
   *
   * @param array $values
   *   The values.
   *
   * @return string|null
   *   The refusal as plain text, or NULL when the values were accepted.
   */
  protected function toolRefusal(array $values): ?string {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:registration.step3:configure');
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    $result = $tool->getResult();
    return $result->isSuccess() ? NULL : PlainTextOutput::renderFromHtml((string) $result->getMessage());
  }

  /**
   * Describes every definition of a resource, at every depth.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The sealed resource.
   *
   * @return array
   *   What each key says, by key.
   */
  protected static function described(SurfaceInterface $surface): array {
    return array_map(static::describe(...), $surface->getDefinitions()->toArray());
  }

  /**
   * Describes one definition and everything under it.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The definition.
   *
   * @return array
   *   Its type, label, description, requiredness and constraints, and
   *   its properties' or item's, described the same way.
   */
  protected static function describe(DataDefinitionInterface $definition): array {
    $described = [
      'type' => $definition->getDataType(),
      'label' => (string) $definition->getLabel(),
      'description' => (string) $definition->getDescription(),
      'required' => $definition->isRequired(),
      'constraints' => serialize($definition->getConstraints()),
    ];
    if ($definition instanceof ComplexDataDefinitionInterface) {
      $described['properties'] = array_map(static::describe(...), $definition->getPropertyDefinitions());
    }
    if ($definition instanceof ListDataDefinitionInterface) {
      $described['item'] = static::describe($definition->getItemDefinition());
    }
    return $described;
  }

}
