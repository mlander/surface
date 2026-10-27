<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Render\Element;
use Drupal\data_surface\DataSurfaceBuilder;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface\DefinitionMetadata;
use Drupal\data_surface\Form\DataSurfaceFormBuilder;
use Drupal\data_surface\Form\DataSurfaceFormBuilderInterface;
use Drupal\data_surface\Resource\Arrangement;
use Drupal\data_surface\Resource\ArrangementGroup;
use Drupal\data_surface\ResourceBuild\ResourceBlueprint;
use Drupal\data_surface\ResourceBuild\ResourceBlueprintAdditions;
use Drupal\data_surface_resource_test\Resource\Studio\StudioResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a key's arrangement: declared once, read by every door.
 *
 * The studio declares two groups, weights, a placeholder, a prefix, a
 * suffix and a help text on its keys; StudioAlter rewords the name and
 * weighs the deposit. The build carries both onto the sealed keys, the
 * served contract states them only when a renderer asks for widget hints,
 * and the form builder draws them. An alter may place and reword an
 * owner's key, never take what the owner arranged away, and keys of one
 * frame naming one group must draw it alike.
 *
 * @see docs/decisions.md#arrangement-is-declared-once
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ArrangementTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'serialization',
    'data_surface',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['data_surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds the studio in its operation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The studio, sealed.
   */
  protected function studio(): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    return $resources->build(StudioResource::class, $resources->operation(StudioResource::class, 'configure'));
  }

  /**
   * Tests the arrangement is read from the definition, and from an alter.
   */
  public function testTheArrangementIsOnTheSealedKeys(): void {
    $studio = $this->studio();
    $rate = DefinitionMetadata::arrangementOf($studio->getDefinition('rate'));
    $this->assertInstanceOf(Arrangement::class, $rate);
    $this->assertSame([
      'group' => ['id' => 'pricing', 'label' => 'Pricing', 'as' => 'details', 'collapsed' => TRUE],
      'placeholder' => 'For example, 40',
      'prefix' => '$',
      'suffix' => 'per hour',
      'help' => 'Whole dollars.',
    ], $rate->toArray());
    $this->assertSame(['group' => ['id' => 'hours', 'label' => 'Opening hours', 'as' => 'fieldset'], 'weight' => 1], DefinitionMetadata::arrangementOf($studio->getDefinition('closes'))->toArray());
    $this->assertSame(['weight' => -10], DefinitionMetadata::arrangementOf($studio->getDefinition('notes'))->toArray());

    // The alter's words over the owner's: it gave the name, which the
    // owner did not arrange, a placeholder and a help text, and weighed
    // the deposit inside the owner's group, which stays.
    $this->assertSame(['placeholder' => 'Riverside studio', 'help' => 'Shown on the door.'], DefinitionMetadata::arrangementOf($studio->getDefinition('name'))->toArray());
    $this->assertSame([
      'group' => ['id' => 'pricing', 'label' => 'Pricing', 'as' => 'details', 'collapsed' => TRUE],
      'weight' => -1,
    ], DefinitionMetadata::arrangementOf($studio->getDefinition('deposit'))->toArray());

    // Refinement keeps it: the arrangement is the key's, whatever the
    // values.
    $this->assertSame('For example, 40', (string) DefinitionMetadata::arrangementOf($studio->refine(['rate' => 10])->getDefinition('rate'))->placeholder);

    // Nothing in it is a rule: the example is still an example, and is no
    // placeholder of its own.
    $this->assertSame([40], DefinitionMetadata::getExamples($studio->getDefinition('rate')));
  }

  /**
   * Tests the served contract states it only when widget hints are asked.
   */
  public function testTheContractStatesItWithTheWidgetHints(): void {
    $studio = $this->studio();
    $emitter = $this->container->get('data_surface.contract_emitter');
    $values = $studio->getDefaultValues();

    $plain = $emitter->emit($studio, $values, 'resource_test.studio', 'configure')->document['schema'];
    $full = $emitter->emitFull($studio, 'resource_test.studio', 'configure')->document['schema'];
    foreach (['rate', 'name', 'notes'] as $key) {
      $this->assertArrayNotHasKey('arrangement', $plain['properties'][$key]['x-surface'] ?? [], $key);
      $this->assertArrayNotHasKey('arrangement', $full['properties'][$key]['x-surface'] ?? [], $key);
    }

    $hinted = $emitter->emit($studio, $values, 'resource_test.studio', 'configure', widgets: TRUE)->document['schema'];
    $this->assertSame([
      'group' => ['id' => 'pricing', 'label' => 'Pricing', 'as' => 'details', 'collapsed' => TRUE],
      'placeholder' => 'For example, 40',
      'prefix' => '$',
      'suffix' => 'per hour',
      'help' => 'Whole dollars.',
    ], $hinted['properties']['rate']['x-surface']['arrangement']);
    $this->assertSame(['placeholder' => 'Riverside studio', 'help' => 'Shown on the door.'], $hinted['properties']['name']['x-surface']['arrangement']);
    $this->assertSame(['weight' => -10], $hinted['properties']['notes']['x-surface']['arrangement']);
    $full = $emitter->emitFull($studio, 'resource_test.studio', 'configure', widgets: TRUE)->document['schema'];
    $this->assertSame($hinted['properties']['rate']['x-surface']['arrangement'], $full['properties']['rate']['x-surface']['arrangement']);
    // Nothing a validator reads moved: the help is no description, and the
    // example stays an example.
    $this->assertSame('What an hour in the studio costs.', $hinted['properties']['rate']['description']);
    $this->assertSame([40], $hinted['properties']['rate']['examples']);
  }

  /**
   * Tests the form builder draws it: groups, weights and input texts.
   */
  public function testTheFormDrawsIt(): void {
    $studio = $this->studio();
    $container = $this->container->get('data_surface.form_builder')->buildSurfaceForm($studio, $studio->getDefaultValues(), new FormState());
    $children = array_values(array_filter(array_keys($container), static fn ($key): bool => !str_starts_with((string) $key, '#')));
    // Each group directly before its first key, which stays where its
    // value is posted.
    $this->assertSame([
      'name',
      '@group.hours',
      'opens',
      'closes',
      '@group.pricing',
      'rate',
      'deposit',
      'notes',
      'third_party_settings',
    ], $children);
    $this->assertSame(['#type' => 'fieldset', '#title' => 'Opening hours'], array_map(static fn ($value) => is_object($value) ? (string) $value : $value, $container['@group.hours']));
    $this->assertSame('details', $container['@group.pricing']['#type']);
    $this->assertFalse($container['@group.pricing']['#open']);
    $this->assertSame('hours', $container['opens'][DataSurfaceFormBuilderInterface::GROUP_KEY]['id']);
    $this->assertSame(2, $container['opens']['#weight']);
    $this->assertSame(1, $container['closes']['#weight']);
    $this->assertSame(-10, $container['notes']['#weight']);
    $this->assertSame('For example, 40', (string) $container['rate']['#placeholder']);
    $this->assertSame('$', (string) $container['rate']['#field_prefix']);
    $this->assertSame('per hour', (string) $container['rate']['#field_suffix']);
    $this->assertSame('What an hour in the studio costs. Whole dollars.', (string) $container['rate']['#description']);
    $this->assertSame('Riverside studio', (string) $container['name']['#placeholder']);
    $this->assertSame('Shown on the door.', (string) $container['name']['#description']);
    // A placeholder is only ever declared: the opening time's default is
    // no placeholder, and neither is an example.
    $this->assertArrayNotHasKey('#placeholder', $container['opens']);
  }

  /**
   * Tests an alter may place and reword an owner's key, never take away.
   */
  public function testAnAlterThatWouldRemoveIsRefused(): void {
    $typed_data = $this->container->get('typed_data_manager');
    $builder = new DataSurfaceBuilder();
    $owner = new ResourceBlueprint($builder, $typed_data);
    $title = $owner->add('title', 'string', 'Title');
    // The owner arranges its own key, found by its name once declared.
    $owner->describe('title', placeholder: 'A short title', help: 'Shown in lists.');
    $this->assertSame(['placeholder' => 'A short title', 'help' => 'Shown in lists.'], DefinitionMetadata::arrangementOf($title)->toArray());
    $alter = new ResourceBlueprintAdditions($builder, $typed_data, 'first_module');
    $alter->add('badge', 'string', 'Badge');

    // Reworded and placed: allowed, over what the owner said.
    $alter->describe('title', group: ArrangementGroup::fieldset('basics', 'Basics'), help: 'Shown in every list.');
    $this->assertSame([
      'group' => ['id' => 'basics', 'label' => 'Basics', 'as' => 'fieldset'],
      'placeholder' => 'A short title',
      'help' => 'Shown in every list.',
    ], DefinitionMetadata::arrangementOf($title)->toArray());

    // Emptied: refused, naming what it would take away.
    try {
      $alter->describe('title', placeholder: '', help: '');
      $this->fail('An alter that empties what the owner arranged is refused.');
    }
    catch (\LogicException $e) {
      $this->assertStringContainsString('The first_module alter arranges "title" with an empty placeholder and help, which would take away what its owner arranged.', $e->getMessage());
    }
    $this->assertSame('A short title', (string) DefinitionMetadata::arrangementOf($title)->placeholder, 'Nothing was taken.');

    // Its own key is its own to arrange as it likes.
    $alter->describe('third_party_settings.first_module.badge', placeholder: 'Gold');
    $alter->describe('third_party_settings.first_module.badge', placeholder: '');

    // Outputs are never drawn.
    $outputs = new ResourceBlueprint($builder, $typed_data, outputs: TRUE);
    $outputs->add('shown', 'string', 'Shown');
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('outputs are never drawn, so they have nothing to arrange');
    $outputs->describe('shown', weight: 1);
  }

  /**
   * Tests a fieldset placed after a grouped key is drawn in that group.
   *
   * StudioAlter places its booking fieldset after the deposit, which the
   * pricing group holds. Drawn, the fieldset is inside the pricing group,
   * right after the deposit and before the rate, as the React app draws
   * it; the mount's container, left with nothing to draw, is drawn as
   * nothing. That it still posts under its mount is the form test's.
   *
   * @see docs/fresh-eyes-2026-10-21.md
   */
  public function testFieldsetPlacedAfterGroupedKeyIsDrawnInItsGroup(): void {
    $studio = $this->studio();
    $container = $this->container->get('data_surface.form_builder')->buildSurfaceForm($studio, $studio->getDefaultValues(), new FormState());
    $mount = $container['third_party_settings']['data_surface_resource_test'];
    $this->assertSame('deposit', $mount[DataSurfaceFormBuilderInterface::PLACED_AFTER_KEY]);
    $this->assertSame('Booking', (string) $mount['#title']);

    $drawn = DataSurfaceFormBuilder::preRenderSurfaceContainer($container);
    $placed = '@placed.third_party_settings.data_surface_resource_test';
    $this->assertArrayNotHasKey($placed, $drawn, 'Not after the group: inside it.');
    $this->assertSame(['deposit', $placed, 'rate'], Element::children($drawn['@group.pricing'], TRUE));
    $this->assertArrayHasKey('booking_fee', $drawn['@group.pricing'][$placed]);
    $this->assertFalse($drawn['third_party_settings']['#access']);

    // A key no group holds is still followed in the frame.
    $this->assertSame(['booking_fee' => 0], $studio->getDefaultValues()['third_party_settings']['data_surface_resource_test']);
  }

  /**
   * Tests keys of one frame naming one group must draw it alike.
   */
  public function testOneGroupIsDrawnOneWay(): void {
    $typed_data = $this->container->get('typed_data_manager');
    $builder = new DataSurfaceBuilder();
    $owner = new ResourceBlueprint($builder, $typed_data);
    $owner->add('opens', 'string', 'Opens');
    $owner->describe('opens', group: ArrangementGroup::fieldset('hours', 'Hours'));
    $owner->add('closes', 'string', 'Closes');
    $owner->describe('closes', group: ArrangementGroup::details('hours', 'Hours'));
    $this->expectException(\LogicException::class);
    $this->expectExceptionMessage('The keys opens and closes name the group "hours" and draw it differently');
    $builder->seal();
  }

}
