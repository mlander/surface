<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\SurfaceInterface;
use Drupal\surface\Pipeline\UnknownKeysException;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Variants\ComponentSlots;
use Drupal\surface_examples_component_block\Structure\ComponentBlockStructure;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a component's slots: read from its metadata, held to it.
 *
 * What a slot expects is read back from the component's metadata: the
 * components it names by id, or by a tag, none for an empty list, every
 * one for no list; the kinds it offers follow, a list unless it holds one
 * component at most, text alone at the depth slots nest to. Each is a
 * refusal: a component outside what a slot expects, one whose tags do not
 * match, more items than it holds. The test module's nest expects itself
 * in both its slots, by tag and by id, so it nests as deep as slots go.
 *
 * @see docs/decisions.md#a-slot-holds-text-a-component-or-components
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ComponentSlotsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'block',
    'field',
    'serialization',
    'tool',
    'surface',
    'surface_tool',
    'surface_examples',
    'surface_examples_component_block',
    'surface_component_test',
  ];

  /**
   * The nest's id.
   */
  protected const NEST = 'surface_component_test:nest';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Gets the provider.
   *
   * @return \Drupal\surface\Variants\ComponentSlots
   *   ComponentSlots, as the build step makes it.
   */
  protected function provider(): ComponentSlots {
    $provider = $this->container->get('class_resolver')->getInstanceFromDefinition(ComponentSlots::class);
    $this->assertInstanceOf(ComponentSlots::class, $provider);
    return $provider;
  }

  /**
   * Builds the component block's structure.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The structure, built to configure.
   */
  protected function block(): SurfaceInterface {
    return $this->container->get('surface.resources')->build(ComponentBlockStructure::class, new OperationContext('configure'));
  }

  /**
   * Gets the slot of one component at the top, or below one in a slot.
   *
   * @param \Drupal\surface\SurfaceInterface $frame
   *   The frame holding `slots`: the block, or a nested component.
   * @param string $component
   *   The component the frame chooses.
   * @param string $slot
   *   The slot.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The slot's structure.
   */
  protected function slot(SurfaceInterface $frame, string $component, string $slot): SurfaceInterface {
    $slots = $frame->getDefinitions()->entry('slots')->variants->variants[$component]->child;
    return $slots->getDefinitions()->entry($slot)->attachment->child;
  }

  /**
   * Gets the components a nested component's key offers.
   *
   * @param \Drupal\surface\SurfaceInterface $slot
   *   The slot holding it.
   *
   * @return string[]
   *   Their ids, in order.
   */
  protected function offered(SurfaceInterface $slot): array {
    $nested = $slot->getDefinitions()->entry('component')->attachment->child;
    return array_map('strval', array_keys($this->options()->resolve($nested->getDefinition('component'))->options));
  }

  /**
   * Tests what a slot expects is read back from the component's metadata.
   */
  public function testExpectedIsReadFromMetadata(): void {
    $provider = $this->provider();
    $hero = $provider->metadata('surface_examples:hero');
    $this->assertSame(['actions'], array_keys($hero));
    $this->assertSame(['surface_examples:call_to_action'], $hero['actions']['expected']);
    $this->assertSame(2, $hero['actions']['maxItems']);
    $this->assertSame([], $provider->metadata('surface_examples:call_to_action'), 'A component with no slot has none.');

    // By id, by tag, none for an empty list, every one for no list.
    $this->assertSame(['surface_examples:call_to_action'], $provider->expected($hero['actions']));
    $this->assertSame(['surface_examples:card', 'surface_examples:hero'], $provider->expected($provider->metadata('surface_examples:section')['content']), 'The tag block: the card and the hero carry it.');
    $this->assertSame([], $provider->expected($provider->metadata('surface_examples:card')['body']));
    $this->assertSame([
      'surface_component_test:nest',
      'surface_examples:call_to_action',
      'surface_examples:card',
      'surface_examples:hero',
      'surface_examples:section',
    ], $provider->expected(['title' => 'Anything']));

    // The kinds follow: a list unless one at most, text alone at the cap
    // or where no component is expected.
    $this->assertSame(ComponentSlots::KINDS, $provider->kinds($hero['actions'], 1));
    $this->assertSame(['text'], $provider->kinds($provider->metadata('surface_examples:card')['body'], 1));
    $this->assertSame(['text', 'component'], $provider->kinds($provider->metadata(self::NEST)['single'], 1));
    $this->assertSame(ComponentSlots::KINDS, $provider->kinds($hero['actions'], ComponentSlots::DEPTH - 1));
    $this->assertSame(['text'], $provider->kinds($hero['actions'], ComponentSlots::DEPTH));

    // Built: the hero's actions offer the call to action alone, in a list
    // of two at most, and the section's content the components tagged.
    $built = $this->block();
    $actions = $this->slot($built, 'surface_examples:hero', 'actions');
    $this->assertSame(['kind', 'text', 'component', 'components'], $actions->getDefinitions()->names());
    $this->assertSame(['surface_examples:call_to_action'], $this->offered($actions));
    $count = $actions->getDefinition('components')->getConstraint('Count');
    $this->assertSame(['max' => 2], $count);
    $this->assertSame(['surface_examples:card', 'surface_examples:hero'], $this->offered($this->slot($built, 'surface_examples:section', 'content')));
    $body = $this->slot($built, 'surface_examples:card', 'body');
    $this->assertSame(['kind', 'text'], $body->getDefinitions()->names(), 'An empty list expects no component: text alone.');
    $single = $this->slot($built, self::NEST, 'single');
    $this->assertSame(['kind', 'text', 'component'], $single->getDefinitions()->names(), 'One at most: text or one component, no list.');
  }

  /**
   * Tests slots nest as deep as declared, and hold text alone there.
   */
  public function testTheDepthIsCapped(): void {
    $this->assertSame(3, ComponentSlots::DEPTH);
    $frame = $this->block();
    $component = self::NEST;
    for ($depth = 1; $depth < ComponentSlots::DEPTH; $depth++) {
      $slot = $this->slot($frame, $component, 'inner');
      $this->assertSame(['kind', 'text', 'component', 'components'], $slot->getDefinitions()->names(), "At depth $depth the slot holds components.");
      $this->assertSame([self::NEST], $this->offered($slot));
      $frame = $slot->getDefinitions()->entry('component')->attachment->child;
    }
    $capped = $this->slot($frame, $component, 'inner');
    $this->assertSame(['kind', 'text'], $capped->getDefinitions()->names(), 'At the cap the slot holds text alone.');
    $this->assertSame(['text'], array_map('strval', array_keys($this->options()->resolve($capped->getDefinition('kind'))->options)));

    // The same in the full schema: a def per depth, text alone at the cap.
    $full = $this->container->get('surface.contract_emitter')->emitFull($this->container->get('surface.resources')->buildStatic(ComponentBlockStructure::class, new OperationContext('configure')), 'block.surface_component')->document['schema'];
    $defs = $full['$defs'];
    $this->assertArrayHasKey('component.slot.' . self::NEST . '.inner.1', $defs);
    $this->assertArrayHasKey('component.slot.' . self::NEST . '.inner.2', $defs);
    $this->assertArrayNotHasKey('component.slot.' . self::NEST . '.inner.3', $defs);
    $this->assertSame('#/$defs/' . ComponentSlots::TEXT, $defs['component.slots.' . self::NEST . '.3']['properties']['inner']['$ref']);
    $this->assertSame([['const' => 'text', 'title' => 'Text']], $defs[ComponentSlots::TEXT]['properties']['kind']['oneOf']);

    // Three nests deep is accepted; a fourth is refused: the slot at the
    // cap has no key to hold it, and holds text alone.
    $nest = static fn (array $inner): array => ['component' => self::NEST, 'slots' => ['inner' => $inner]];
    $holding = static fn (array $nested): array => ['kind' => 'component', 'component' => $nested];
    $deepest = ['kind' => 'text', 'text' => 'At the bottom.'];
    $this->assertSame([], $this->refused($nest($holding($nest($holding($nest($deepest)))))));
    $capped = 'slots.inner.component.slots.inner.component.slots.inner';
    $refused = $this->refused($nest($holding($nest($holding($nest(['kind' => 'component']))))));
    $this->assertSame([$capped . '.kind' => 'The value you selected is not a valid choice. Choose Text.'], $refused);
    try {
      $this->refused($nest($holding($nest($holding($nest($holding($nest($deepest))))))));
      $this->fail('A fourth nest is refused.');
    }
    catch (UnknownKeysException $e) {
      $this->assertSame('Unknown key(s) component under ' . $capped . '.', $e->getMessage());
    }
  }

  /**
   * Tests what a slot expects is refused when it is not what is sent.
   */
  public function testExpectationsAreRefusals(): void {
    $cta = [
      'component' => 'surface_examples:call_to_action',
      'props' => ['text' => 'Find your ticket', 'url' => 'https://example.com/tickets'],
    ];
    $hero = static fn (array $actions): array => [
      'component' => 'surface_examples:hero',
      'props' => ['title' => 'Doors open at half past six'],
      'slots' => ['actions' => $actions],
    ];
    $this->assertSame([], $this->refused($hero(['kind' => 'components', 'components' => [$cta, $cta]])));

    // A component outside what the slot expects, by name.
    $this->assertSame(
      ['slots.actions.component.component' => 'The value you selected is not a valid choice. Choose Call to action.'],
      $this->refused($hero([
        'kind' => 'component',
        'component' => ['component' => 'surface_examples:hero', 'props' => ['title' => 'Inside']],
      ])),
    );
    // A tag the component does not carry.
    $this->assertSame(
      ['slots.content.component.component' => 'The value you selected is not a valid choice. Choose Card or Hero.'],
      $this->refused([
        'component' => 'surface_examples:section',
        'slots' => ['content' => ['kind' => 'component', 'component' => $cta]],
      ]),
    );
    // More items than the slot holds.
    $this->assertSame(
      ['slots.actions.components' => 'This collection should contain 2 elements or less.'],
      $this->refused($hero(['kind' => 'components', 'components' => [$cta, $cta, $cta]])),
    );
    // One at most: no list.
    $this->assertSame(
      ['slots.single.kind' => 'The value you selected is not a valid choice. Choose Text or A component.'],
      $this->refused(['component' => self::NEST, 'slots' => ['single' => ['kind' => 'components']]]),
    );
    // A slot that expects no component holds text alone.
    $this->assertSame(
      ['slots.body.kind' => 'The value you selected is not a valid choice. Choose Text.'],
      $this->refused([
        'component' => 'surface_examples:card',
        'props' => ['heading' => 'Doors'],
        'slots' => ['body' => ['kind' => 'component']],
      ]),
    );
  }

  /**
   * Accepts and validates values for the block's structure.
   *
   * @param array $values
   *   The values.
   *
   * @return array<string, string>
   *   Each refusal's message, by its dotted path.
   */
  protected function refused(array $values): array {
    $built = $this->block();
    $refused = [];
    foreach ($this->pipeline()->validate($built, $this->pipeline()->accept($built, $values, [])) as $violation) {
      $refused[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $refused;
  }

}
