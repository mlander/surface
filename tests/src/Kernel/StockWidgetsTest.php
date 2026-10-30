<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Form\FormState;
use Drupal\Core\TypedData\DataDefinition;
use Drupal\Core\TypedData\DataDefinitionInterface;
use Drupal\Core\TypedData\ListDataDefinition;
use Drupal\Core\TypedData\MapDataDefinition;
use Drupal\surface\SurfaceBuilder;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\DefinitionMetadata;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\Form\Drawing;
use Drupal\surface\Resource\OperationContext;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\SurfaceWidgetManager;
use Drupal\surface_resource_test\Resource\Library\OpeningHoursResource;
use Drupal\surface_resource_test\Resource\Parcel\ParcelResource;
use Drupal\surface_resource_test\Storage\OpeningHoursStorage;
use Drupal\surface_resource_test\Storage\ParcelStorage;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * The stock widget set, and what no element types in.
 *
 * Checkboxes, radios, a free list, a time, a date, a date and time, a
 * timestamp, a list of maps, and every data type core defines.
 *
 * Each widget's element is built from the definition alone, its posted
 * shape is read back in the definition's shape, absent keeps, emptied
 * clears where clearing is possible, and the `widget` setting overrides
 * the stock choice. The served contract's hints mirror each choice.
 *
 * @see docs/decisions.md#a-widget-is-chosen-from-the-definition
 * @see docs/decisions.md#a-key-its-storage-always-holds
 * @see docs/decisions.md#the-form-never-refuses-to-draw-a-declared-key
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class StockWidgetsTest extends SurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'surface',
    'surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_resource_test']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Builds a resource in its configure operation.
   *
   * @param class-string $class
   *   The resource.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function built(string $class): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build($class, $resources->operation($class, 'configure'));
  }

  /**
   * Builds the form elements for a data surface and values.
   *
   * @param \Drupal\surface\SurfaceInterface $surface
   *   The data surface.
   * @param array $values
   *   The values.
   *
   * @return array
   *   The container.
   */
  protected function form(SurfaceInterface $surface, array $values = []): array {
    return $this->formBuilder()->buildSurfaceForm($surface, $values + $surface->getDefaultValues(), new FormState());
  }

  /**
   * Seals a data surface of one key.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The data surface.
   */
  protected function one(DataDefinitionInterface $definition): SurfaceInterface {
    return (new SurfaceBuilder())->setDefinition('key', $definition)->seal();
  }

  /**
   * Reads one key back the way the form builder does, from posted values.
   *
   * @param \Drupal\Core\TypedData\DataDefinitionInterface $definition
   *   The key's definition.
   * @param array $element
   *   The element built for it.
   * @param mixed $posted
   *   What was posted for it.
   *
   * @return mixed
   *   What the widget reads back.
   */
  protected function extracted(DataDefinitionInterface $definition, array $element, mixed $posted): mixed {
    $state = (new FormState())->setValues(['key' => $posted]);
    return $this->container->get('plugin.manager.surface_widget')->getWidgetFor($definition)->extractValue($definition, $element, $state, ['key']);
  }

  /**
   * A list of choices.
   *
   * @param int $count
   *   How many choices.
   *
   * @return \Drupal\Core\TypedData\ListDataDefinition
   *   The list.
   */
  protected static function choices(int $count): ListDataDefinition {
    $item = DataDefinition::create('string')->addConstraint('Choice', ['choices' => array_map(static fn (int $n): string => 'c' . $n, range(1, $count))]);
    return (new ListDataDefinition(['type' => 'list'], $item))->setLabel('Choices');
  }

  /**
   * Weekdays are checkboxes, read back as the list of the days checked.
   */
  public function testCheckboxesForShortListOfChoices(): void {
    $hours = $this->built(OpeningHoursResource::class);
    $days = $this->form($hours)['days'];
    $this->assertSame('checkboxes', $days['#type'], 'Seven choices are a column of checkboxes.');
    $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'], array_keys($days['#options']));
    $this->assertSame(['mon', 'tue', 'wed', 'thu', 'fri'], $days['#default_value']);
    $this->assertFalse($days['#required'], 'The empty list is a value, so no box must be checked.');

    $definition = $hours->getDefinition('days');
    $posted = ['mon' => 'mon', 'tue' => 0, 'wed' => 0, 'thu' => 0, 'fri' => 0, 'sat' => 'sat', 'sun' => 0];
    $this->assertSame(['mon', 'sat'], $this->extracted($definition, $days, $posted));
    $none = array_fill_keys(array_keys($days['#options']), 0);
    $this->assertSame([], $this->extracted($definition, $days, $none), 'All unchecked is the empty list.');
    $this->assertSame(['tue'], $this->extracted($definition, $days, ['tue']), 'A list as a payload sends it passes as it is.');

    // The three-way rule, through accept(): left out keeps, the empty
    // list clears, the boxes left as drawn keep what they showed.
    $stored = $this->config('surface_resource_test.opening_hours')->getRawData();
    $this->assertSame($stored['days'], $this->pipeline()->accept($hours, [], $stored)['days']);
    $this->assertSame([], $this->pipeline()->accept($hours, ['days' => $this->extracted($definition, $days, $none)], $stored)['days']);
    $untouched = array_combine(array_keys($days['#options']), array_map(static fn (string $day): string|int => in_array($day, $stored['days'], TRUE) ? $day : 0, array_keys($days['#options'])));
    $this->assertSame($stored['days'], $this->pipeline()->accept($hours, ['days' => $this->extracted($definition, $days, $untouched)], $stored)['days']);
  }

  /**
   * A long list is a multiple select; the setting overrides either way.
   */
  public function testTheWidgetSettingOverridesTheCount(): void {
    $long = $this->form($this->one(static::choices(13)))['key'];
    $this->assertSame('select', $long['#type'], 'Thirteen choices are a multiple select.');
    $this->assertTrue($long['#multiple']);
    $twelve = $this->form($this->one(static::choices(12)))['key'];
    $this->assertSame('checkboxes', $twelve['#type'], 'Twelve are checkboxes.');

    $asked = $this->form($this->one(static::choices(13)->setSetting('widget', 'checkboxes')))['key'];
    $this->assertSame('checkboxes', $asked['#type']);
    $select = $this->form($this->one(static::choices(3)->setSetting('widget', 'select')))['key'];
    $this->assertSame(['select', TRUE], [$select['#type'], $select['#multiple']]);

    $emitter = $this->container->get('surface.contract_emitter');
    $hint = static fn (SurfaceInterface $surface): array => $emitter->emitFull($surface, 'test', widgets: TRUE)->document['schema']['properties']['key']['x-surface'];
    $this->assertSame('checkboxes', $hint($this->one(static::choices(12)))['widget']);
    $this->assertArrayNotHasKey('multiple', $hint($this->one(static::choices(12))));
    $long = $hint($this->one(static::choices(13)));
    $this->assertSame(['select', TRUE], [$long['widget'], $long['multiple']]);
    $this->assertSame('checkboxes', $hint($this->one(static::choices(13)->setSetting('widget', 'checkboxes')))['widget']);
  }

  /**
   * A setting naming a widget that does not draw the key is refused.
   */
  public function testAskingForMisfitWidgetIsRefused(): void {
    $manager = $this->container->get('plugin.manager.surface_widget');
    $refusals = [];
    foreach ([
      'radios for a list' => static::choices(3)->setSetting('widget', 'radios'),
      'checkboxes for one value' => DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['a']])->setSetting('widget', 'checkboxes'),
      'a time for a number' => DataDefinition::create('integer')->setSetting('widget', 'time'),
      'a date for a number' => DataDefinition::create('integer')->setSetting('widget', 'date'),
      'a word no widget answers to' => DataDefinition::create('string')->setSetting('widget', 'sparkly'),
    ] as $label => $definition) {
      try {
        $manager->getWidgetFor($definition);
      }
      catch (\InvalidArgumentException $e) {
        $refusals[$label] = $e->getMessage();
      }
    }
    $this->assertSame([
      'radios for a list' => '"Choices" asks for the radios widget, which does not draw a key of type list of string; the select widget does. Ask for a widget that draws it, or for none.',
      'checkboxes for one value' => '"string" asks for the checkboxes widget, which does not draw a key of type string; the select widget does. Ask for a widget that draws it, or for none.',
      'a time for a number' => '"integer" asks for the time widget, which does not draw a key of type integer; the number widget does. Ask for a widget that draws it, or for none.',
      'a date for a number' => '"integer" asks for the date widget, which does not draw a key of type integer; the number widget does. Ask for a widget that draws it, or for none.',
      'a word no widget answers to' => '"string" asks for the sparkly widget, which does not draw a key of type string; the string widget does. Ask for a widget that draws it, or for none.',
    ], $refusals);
  }

  /**
   * Radios when asked: "- None -" only where choosing nothing clears.
   */
  public function testRadios(): void {
    $parcel = $this->built(ParcelResource::class);
    $handling = $this->form($parcel, ['handling' => 'fragile'])['handling'];
    $this->assertSame('radios', $handling['#type']);
    $this->assertSame(['', 'fragile', 'upright'], array_map('strval', array_keys($handling['#options'])), 'Optional and clearable: a "- None -" radio first. Standard carries nothing cold.');
    $this->assertSame('- None -', (string) $handling['#options']['']);
    $this->assertSame('fragile', $handling['#default_value']);
    $this->assertSame('', $this->form($parcel, ['handling' => NULL])['handling']['#default_value'], 'Nothing stored: "- None -" is checked.');
    $this->assertNull($this->extracted($parcel->getDefinition('handling'), $handling, NULL), 'Nothing posted is nothing.');
    $this->assertSame('', $this->extracted($parcel->getDefinition('handling'), $handling, ''), '"- None -" posts the empty string, which clears.');

    $choice = static fn (): DataDefinition => DataDefinition::create('string')
      ->setLabel('Size')
      ->setSetting('widget', 'radios')
      ->addConstraint('Choice', ['choices' => ['s', 'm', 'l']]);
    $required = $this->form($this->one($choice()->setRequired(TRUE)))['key'];
    $this->assertSame(['s', 'm', 'l'], array_keys($required['#options']), 'Required: no empty radio.');
    $this->assertNull($required['#default_value'], 'Nothing chosen: nothing checked.');
    $this->assertTrue($required['#required'], 'Drawn required.');
    $this->assertArrayNotHasKey('#required_error', $required, 'The form builder keeps core\'s check off: the refusal is the data surface\'s.');
    $held = $choice();
    DefinitionMetadata::setAlwaysHeld($held);
    $this->assertSame(['s', 'm', 'l'], array_keys($this->form($this->one($held), ['key' => 'm'])['key']['#options']), 'Always held: no empty radio either.');

    $stale = $this->form($this->one($choice()->setRequired(TRUE)), ['key' => 'xl'])['key'];
    $this->assertNull($stale['#default_value'], 'A stored value no longer offered: nothing checked.');
    $this->assertSame('xl', $stale[SurfaceWidgetBase::STALE_KEY]);
    $this->assertFalse($stale['#required'], 'Left alone it keeps, so nothing is required of it.');
    $this->assertSame('xl', $this->extracted($choice()->setRequired(TRUE), $stale, NULL), 'Radios left alone keep the stored value.');

    $schema = $this->container->get('surface.contract_emitter')->emit($parcel, ['handling' => 'fragile'] + $parcel->getDefaultValues(), 'resource_test.parcel', widgets: TRUE)->document['schema'];
    $this->assertSame('radios', $schema['properties']['handling']['x-surface']['widget']);
    $this->assertSame(['show' => TRUE, 'label' => '- None -'], $schema['properties']['handling']['x-surface']['emptyOption']);
    $this->assertSame('select', $schema['properties']['service']['x-surface']['widget'], 'A choice that asks for nothing stays a select.');
  }

  /**
   * A key its storage always holds offers no empty option, and refuses null.
   */
  public function testKeyItsStorageAlwaysHolds(): void {
    $storage = $this->container->get(OpeningHoursStorage::class);
    $context = new OperationContext('configure');
    $this->assertSame(
      [
        'holidays' => FALSE,
        'after_hours' => FALSE,
        'keywords' => FALSE,
        'notes' => TRUE,
        'door_code' => TRUE,
        'fax' => NULL,
      ],
      array_map(static fn (string $key): ?bool => $storage->mayClear($context, $key), array_combine(
        ['holidays', 'after_hours', 'keywords', 'notes', 'door_code', 'fax'],
        ['holidays', 'after_hours', 'keywords', 'notes', 'door_code', 'fax'],
      )),
      'Fully validatable: only what the schema calls nullable may hold nothing; a key no schema describes is not known.',
    );
    $this->assertTrue($this->container->get(ParcelStorage::class)->mayClear($context, 'insured'), 'Not fully validatable: anything may hold nothing.');

    $hours = $this->built(OpeningHoursResource::class);
    $marked = array_filter(['holidays', 'after_hours', 'keywords', 'notes', 'opens'], static fn (string $key): bool => DefinitionMetadata::isAlwaysHeld($hours->getDefinition($key)));
    $this->assertSame(['holidays', 'after_hours', 'keywords'], array_values($marked), 'A required key is not marked: it holds a value anyway.');

    $holidays = $this->form($hours)['holidays'];
    $this->assertSame('select', $holidays['#type']);
    $this->assertArrayNotHasKey('#empty_option', $holidays, 'A valid choice of a key that cannot be cleared: no empty option.');
    $this->assertSame('closed', $holidays['#default_value']);
    $stale = $this->form($hours, ['holidays' => 'weekly'])['holidays'];
    $this->assertSame('- Keep weekly (no longer offered) -', (string) $stale['#empty_option'], 'No valid choice: the empty option stands for the stored value and says so, as a required select\'s does.');

    $optional = DataDefinition::create('string')->addConstraint('Choice', ['choices' => ['a', 'b']]);
    $this->assertSame('- None -', (string) $this->form($this->one($optional), ['key' => 'a'])['key']['#empty_option'], 'Optional and clearable: "- None -" always.');

    $stored = $this->config('surface_resource_test.opening_hours')->getRawData();
    $refusals = function (array $values) use ($hours, $stored): array {
      $messages = [];
      foreach ($this->pipeline()->validate($hours, $this->pipeline()->accept($hours, $values, $stored), $stored) as $violation) {
        $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
      }
      return $messages;
    };
    $this->assertSame(['holidays' => 'On public holidays cannot be cleared: it always holds a value, Closed by default. Leave it out to keep what it holds.'], $refusals(['holidays' => NULL]));
    $this->assertSame(['holidays' => 'On public holidays cannot be cleared: it always holds a value, Closed by default. Leave it out to keep what it holds.'], $refusals(['holidays' => '']), 'The empty string a form posts for nothing is null.');
    $this->assertSame(['after_hours' => 'After-hours access cannot be cleared: it always holds a value, off by default. Leave it out to keep what it holds.'], $refusals(['after_hours' => NULL]));
    $this->assertSame([], $refusals(['notes' => NULL]), 'Nullable in the schema: null clears it.');
    $this->assertSame([], $refusals(['holidays' => 'open']));

    $emitter = $this->container->get('surface.contract_emitter');
    foreach ([
      'the schema for these answers' => $emitter->emit($hours, $stored, 'resource_test.opening_hours', widgets: TRUE)->document['schema'],
      'the full schema' => $emitter->emitFull($hours, 'resource_test.opening_hours', widgets: TRUE)->document['schema'],
    ] as $label => $schema) {
      $properties = $schema['properties'];
      $this->assertSame('string', $properties['holidays']['type'], $label . ': no null where it cannot be cleared.');
      $this->assertNotContains(['const' => NULL], $properties['holidays']['oneOf'], $label);
      $this->assertSame('boolean', $properties['after_hours']['type'], $label);
      $this->assertSame('array', $properties['keywords']['type'], $label);
      $this->assertSame(['string', 'null'], $properties['notes']['type'], $label . ': null where it can.');
    }
    $these = $emitter->emit($hours, $stored, 'resource_test.opening_hours', widgets: TRUE)->document['schema']['properties'];
    $this->assertSame(['show' => FALSE, 'label' => '- Select -'], $these['holidays']['x-surface']['emptyOption']);
  }

  /**
   * A list of free strings is rows, added and removed, read back in order.
   */
  public function testListOfFreeValuesIsRows(): void {
    $hours = $this->built(OpeningHoursResource::class);
    $keywords = $this->form($hours, ['keywords' => ['maps', 'archives']])['keywords'];
    $this->assertSame('fieldset', $keywords['#type']);
    $this->assertTrue($keywords[SurfaceFormBuilderInterface::ROWS_KEY]);
    $this->assertSame(['textfield', 'maps'], [$keywords[0]['#type'], $keywords[0]['#default_value']]);
    $this->assertSame('Keywords 2', (string) $keywords[1]['#title']);
    $this->assertSame(['remove', 1], $keywords['remove_1'][SurfaceFormBuilderInterface::ROW_CONTROL_KEY]);
    $this->assertSame(['add', NULL], $keywords['add'][SurfaceFormBuilderInterface::ROW_CONTROL_KEY]);
    $this->assertFalse($keywords['add']['#disabled']);

    $definition = $hours->getDefinition('keywords');
    $three = $keywords + [2 => $keywords[1]];
    $this->assertSame(['maps', 'files'], $this->extracted($definition, $three, ['maps', '', 'files']), 'A row left empty is dropped.');
    $this->assertSame([], $this->extracted($definition, $this->form($hours, ['keywords' => []])['keywords'], []), 'No rows: the empty list.');

    $bounded = (new ListDataDefinition(['type' => 'list'], DataDefinition::create('integer')))
      ->setLabel('Lucky numbers')
      ->addConstraint('Count', ['min' => 2, 'max' => 2]);
    $element = $this->form($this->one($bounded))['key'];
    $this->assertSame(['number', 'number'], [$element[0]['#type'], $element[1]['#type']], 'Drawn with at least its minimum, each row the item\'s own element.');
    $this->assertTrue($element['remove_0']['#disabled'], 'At the minimum: nothing to remove.');
    $this->assertTrue($element['add']['#disabled'], 'At the maximum: nothing to add.');

    $hint = $this->container->get('surface.contract_emitter')->emitFull($hours, 'resource_test.opening_hours', widgets: TRUE)->document['schema']['properties']['keywords']['x-surface']['widget'];
    $this->assertSame('list', $hint);
  }

  /**
   * A time is a time input, and the pattern is still the rule.
   */
  public function testTimeOfDay(): void {
    $hours = $this->built(OpeningHoursResource::class);
    $opens = $this->form($hours)['opens'];
    $this->assertSame('date', $opens['#type']);
    $this->assertSame(['time', '09:00'], [$opens['#attributes']['type'], $opens['#default_value']]);
    $this->assertSame('17:30', $this->extracted($hours->getDefinition('opens'), $opens, '17:30'));
    $schema = $this->container->get('surface.contract_emitter')->emitFull($hours, 'resource_test.opening_hours', widgets: TRUE)->document['schema'];
    $this->assertSame('time', $schema['properties']['opens']['x-surface']['widget']);
    $this->assertSame('^([01]\d|2[0-3]):[0-5]\d$', $schema['properties']['opens']['pattern']);
    $this->assertArrayNotHasKey('format', $schema['properties']['opens'], 'A time input posts HH:MM, which RFC 3339 time refuses.');
  }

  /**
   * A date is a date input, stated as `format: date`; the pattern rules.
   */
  public function testDate(): void {
    $day = DataDefinition::create('string')
      ->setLabel('Reviewed on')
      ->setSetting('widget', 'date')
      ->addConstraint('Regex', ['pattern' => '/^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/D']);
    $surface = $this->one($day);
    $element = $this->form($surface, ['key' => '2026-10-19'])['key'];
    $this->assertSame(['date', '2026-10-19'], [$element['#type'], $element['#default_value']]);
    $this->assertArrayNotHasKey('#attributes', $element, "Core's date element is a date input as it stands.");
    $this->assertSame('date', $this->container->get('element_info')->getInfo('date')['#attributes']['type']);
    $this->assertSame('2026-10-20', $this->extracted($day, $element, '2026-10-20'));
    $emitter = $this->container->get('surface.contract_emitter');
    $property = $emitter->emitFull($surface, 'test', widgets: TRUE)->document['schema']['properties']['key'];
    $this->assertSame(['date', 'date'], [$property['x-surface']['widget'], $property['format']]);
    $this->assertSame('date', $emitter->emitFull($surface, 'test')->document['schema']['properties']['key']['format'], 'The format is stated without the widget hints.');
  }

  /**
   * Every data type core defines is drawn by a widget: none throws.
   *
   * What add() declares a key with is a typed data type, and core defines
   * them all (this module defines none); each, and a list of each, is
   * drawn by some widget. A type a contributed or core module adds
   * (`filter_format`, say) is not walked here: no widget names it.
   */
  public function testEveryDataTypeOfCoreIsDrawn(): void {
    $manager = $this->container->get('plugin.manager.surface_widget');
    $typed = $this->container->get('typed_data_manager');
    $drawn = [];
    $lists = [];
    foreach ($typed->getDefinitions() as $type => $plugin) {
      if (($plugin['provider'] ?? NULL) !== 'core') {
        continue;
      }
      $definition = $typed->createDataDefinition($type);
      $drawn[$type] = SurfaceWidgetManager::wordOf($manager->getWidgetFor($definition));
      $lists[$type] = SurfaceWidgetManager::wordOf($manager->getWidgetFor(new ListDataDefinition(['type' => 'list'], $definition)));
    }
    $primitives = [
      'any' => 'json',
      'binary' => 'not_drawn',
      'boolean' => 'checkbox',
      'datetime_iso8601' => 'datetime',
      'decimal' => 'number',
      'duration_iso8601' => 'string',
      'email' => 'string',
      'entity_reference' => 'not_drawn',
      'float' => 'number',
      'integer' => 'number',
      'language' => 'not_drawn',
      'language_reference' => 'not_drawn',
      'list' => 'json',
      'map' => 'fieldset',
      'string' => 'string',
      'timespan' => 'number',
      'timestamp' => 'number',
      'uri' => 'string',
    ];
    ksort($primitives);
    $read = array_intersect_key($drawn, $primitives);
    ksort($read);
    $this->assertSame($primitives, $read);
    // Every other one core defines (an entity, a field item) is a map.
    $this->assertSame([], array_diff(array_diff_key($drawn, $primitives), ['fieldset']));
    $some = ['binary', 'boolean', 'integer', 'list', 'map', 'string', 'timestamp'];
    $lists = array_intersect_key($lists, array_flip($some));
    ksort($lists);
    $this->assertSame([
      'binary' => 'not_drawn',
      'boolean' => 'list',
      'integer' => 'list',
      'list' => 'json',
      'map' => 'json',
      'string' => 'list',
      'timestamp' => 'list',
    ], $lists, 'A list of maps the form builder draws as a collection; asked alone, the manager answers the JSON widget.');
  }

  /**
   * A timestamp is a whole number; a date and time is core's element.
   */
  public function testTimestampAndDateTime(): void {
    $stamp = DataDefinition::create('timestamp')->setLabel('Opened');
    $element = $this->form($this->one($stamp), ['key' => 1761562800])['key'];
    $this->assertSame(['number', 1761562800, 1], [$element['#type'], $element['#default_value'], $element['#step']]);
    $this->assertSame('1761566400', $this->extracted($stamp, $element, '1761566400'), 'Read raw; accept() casts it.');

    $at = DataDefinition::create('datetime_iso8601')->setLabel('Starts');
    $element = $this->form($this->one($at), ['key' => '2026-10-27T18:30:00+00:00'])['key'];
    $this->assertSame('datetime', $element['#type']);
    $this->assertInstanceOf(DrupalDateTime::class, $element['#default_value']);
    $this->assertSame((new \DateTimeImmutable('2026-10-27T18:30:00+00:00'))->getTimestamp(), $element['#default_value']->getTimestamp(), 'The same instant, in the site\'s time zone.');
    $this->assertSame('2026-10-28T09:15:00+00:00', $this->extracted($at, $element, new DrupalDateTime('2026-10-28T09:15:00+00:00')), 'Core\'s DrupalDateTime back to the string.');
    $typed = ['date' => '2026-10-28', 'time' => '09:15:00'];
    $this->assertSame((new \DateTimeImmutable('2026-10-28 09:15:00'))->format('c'), $this->extracted($at, $element, $typed), 'What was typed, before core read it, in the site\'s time zone.');
    $this->assertNull($this->extracted($at, $element, ['date' => '', 'time' => '']), 'Nothing typed sent nothing.');
  }

  /**
   * Bytes are not drawn: an item saying so, and what is stored is kept.
   */
  public function testBinaryIsNotDrawn(): void {
    $bytes = DataDefinition::create('binary')->setLabel('Logo');
    $surface = $this->one($bytes);
    $element = $this->form($surface, ['key' => 'stored'])['key'];
    $this->assertSame('item', $element['#type']);
    $this->assertTrue($element[SurfaceFormBuilderInterface::PLACEHOLDER_KEY]);
    $this->assertSame('This is not drawn on this form; what it holds is kept. Set it through the tool, over HTTP or on the React page.', $element['#markup']);
    $container = $this->form($surface, ['key' => 'stored']);
    $this->assertSame(['key' => 'stored'], $this->formBuilder()->extractSurfaceValues($surface, $container, new FormState(), ['key' => 'stored']));
  }

  /**
   * A list of maps no collection was attached for is drawn as one.
   *
   * One details per item, each the map's own fields, an "Add another"
   * button under them; read back item by item, an item left empty
   * dropped.
   */
  public function testListOfMapsIsCollection(): void {
    $stop = MapDataDefinition::create()
      ->setPropertyDefinition('name', DataDefinition::create('string')->setLabel('Name'))
      ->setPropertyDefinition('minutes', DataDefinition::create('integer')->setLabel('Minutes'));
    $stops = (new ListDataDefinition(['type' => 'list'], $stop))->setLabel('Stops');
    $surface = $this->one($stops);
    $element = $this->form($surface, ['key' => [['name' => 'Harbor', 'minutes' => 5]]])['key'];
    $this->assertSame(['details', 'key'], [$element['#type'], $element[Drawing::LIST_KEY]]);
    $this->assertSame(['textfield', 'Harbor'], [$element[0]['name']['#type'], $element[0]['name']['#default_value']]);
    $this->assertSame(['number', 5], [$element[0]['minutes']['#type'], $element[0]['minutes']['#default_value']]);
    $this->assertSame('Stops 1', (string) $element[0]['#title']);
    $this->assertArrayHasKey('@add', $element);

    $container = $this->form($surface, ['key' => [['name' => 'Harbor', 'minutes' => 5], []]]);
    $state = (new FormState())->setValues([
      'key' => [['name' => 'Mill', 'minutes' => '7'], ['name' => '', 'minutes' => '']],
    ]);
    $this->assertSame(['key' => [['name' => 'Mill', 'minutes' => 7]]], $this->formBuilder()->extractSurfaceValues($surface, $container, $state));
  }

}
