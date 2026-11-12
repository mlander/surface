<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormState;
use Drupal\Core\Form\FormStateInterface;
use Drupal\surface\Contract\ServedContract;
use Drupal\surface\Form\Document\DocumentNodes;
use Drupal\surface\Form\SurfaceFormBuilderInterface;
use Drupal\surface\SurfaceInterface;
use Drupal\surface\Widget\SurfaceWidgetBase;
use Drupal\surface\Widget\WidgetHostInterface;
use Drupal\surface_examples\Resource\BulletinResource;
use Drupal\surface_examples\Resource\CateringResource;
use Drupal\surface_examples\Resource\MailerResource;
use Drupal\surface_examples\Resource\RegistrationStep2Resource;
use Drupal\surface_examples\Resource\RegistrationStep3Resource;
use Drupal\surface_examples\Resource\VenueResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PortableSchema\Builder\Schema;

/**
 * Tests the form is drawn from the served document, rule by rule.
 *
 * The drawer reads an envelope (its schema, `values` and `stale`) and
 * nothing else, by the rules the React app reads it by: the widget the
 * word names, required from the owner, the notes, the lock and what it
 * passes on, the empty option and a stale value, bounds, choices,
 * fieldsets and groups, lists and collections, variants, the autocomplete,
 * a key that does not apply, and the hints a widget publishes. Each rule
 * a shipped example exercises is held on the envelope that example
 * serves: groups and weights (the catering), a stale select (the
 * registration's room after its venue moved), variants redrawn (the
 * ticket the pricing chooses), a key that does not apply (the bulletin's
 * send day), the select and bounds (the registration's venue and
 * capacity); the autocomplete, a text input's size and a text area's
 * rows on a content entity's envelope (DocumentFormEntityTest), the
 * locked note on the cabinet's (LockedAccessTest), and a deferred
 * variant inside an item on the spotlight's (SpotlightFormTest). The
 * documents written here, as an emitter writes one, hold the rules no
 * shipped example exercises: a key naming a group its frame does not
 * declare, a collection padded to its `minItems`, a module's widget's
 * published setting, check options that name a handler, and every rule
 * once more, small enough to read.
 *
 * Decision: see docs/decisions.md#the-form-is-drawn-from-the-document.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class DocumentFormTest extends SurfaceKernelTestBase {

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
    'surface_http',
    'surface_examples',
    'surface_widget_test',
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
   * Draws a document written here.
   *
   * @param array $schema
   *   The schema, as an emitter writes it.
   * @param array $values
   *   The envelope's values.
   * @param string[] $stale
   *   Its stale pointers.
   * @param \Drupal\surface\Widget\WidgetHostInterface|null $host
   *   What the host answers.
   *
   * @return array
   *   The container.
   */
  protected function draw(array $schema, array $values = [], array $stale = [], ?WidgetHostInterface $host = NULL): array {
    $document = [
      'resource' => 'test',
      'operation' => NULL,
      'label' => NULL,
      'schema' => $schema,
      'values' => $values,
      'stale' => $stale,
    ];
    $contract = new ServedContract($document, (new CacheableMetadata())->setCacheTags([
      'test_list',
    ]), Schema::from($schema));
    return $this->formBuilder()->drawDocument($contract, new FormState(), $host);
  }

  /**
   * Writes an object schema of properties.
   *
   * @param array $properties
   *   The properties.
   * @param string[] $required
   *   Its `required`.
   * @param array $more
   *   Other keywords of the object.
   *
   * @return array
   *   The schema.
   */
  protected static function object(array $properties, array $required = [], array $more = []): array {
    return ['type' => 'object', 'properties' => $properties] + ($required === [] ? [] : [
      'required' => $required,
    ]) + $more;
  }

  /**
   * Rules 1 and 2: the word names the widget; the owner says required.
   */
  public function testTheWordNamesTheWidgetAndTheOwnerRequires(): void {
    $form = $this->draw(static::object([
      'title' => ['title' => 'Title', 'type' => 'string', 'x-portable-ui' => ['widget' => 'text']],
      'body' => ['title' => 'Body', 'type' => 'string', 'x-portable-ui' => ['widget' => 'textarea']],
      'mail' => ['title' => 'Mail', 'type' => 'string', 'format' => 'email', 'x-portable-ui' => ['widget' => 'email']],
      'site' => ['title' => 'Site', 'type' => 'string', 'format' => 'uri', 'x-portable-ui' => ['widget' => 'text']],
      'opens' => ['title' => 'Opens', 'type' => 'string', 'x-portable-ui' => ['widget' => 'time']],
      'stamp' => ['title' => 'Stamp', 'type' => 'integer'],
      'starts' => ['title' => 'Starts', 'type' => 'string', 'format' => 'date-time'],
      'color' => ['title' => 'Color', 'type' => 'string', 'x-portable-ui' => ['widget' => 'no_such_widget']],
    ], ['title']));
    $types = array_map(static fn (array $element): ?string => $element['#type'] ?? NULL, array_intersect_key($form, array_flip([
      'title',
      'body',
      'mail',
      'site',
      'opens',
      'stamp',
      'starts',
      'color',
    ])));
    $this->assertSame([
      'title' => 'textfield',
      'body' => 'textarea',
      'mail' => 'email',
      'site' => 'url',
      'opens' => 'date',
      'stamp' => 'number',
      'starts' => 'datetime',
      'color' => 'textfield',
    ], $types, 'The word names the widget; no word, or a word no widget answers to, the stock one for the shape.');
    $this->assertTrue($form['title']['#required'], 'Required where its owner lists it.');
    $this->assertFalse($form['body']['#required']);
    $this->assertSame('/title', $form['title'][SurfaceFormBuilderInterface::PATH_KEY]);
    $this->assertSame('string', $form['title'][SurfaceFormBuilderInterface::WIDGET_KEY]);
    $this->assertContains('test_list', $form['#cache']['tags'], 'The envelope\'s cacheability is the container\'s.');
  }

  /**
   * Rules 3 and 4: the notes, and a lock with its reason, passed on.
   */
  public function testNotesAndTheLock(): void {
    $form = $this->draw(static::object([
      'code' => [
        'title' => 'Code',
        'description' => 'Four digits.',
        'type' => 'string',
        'x-portable-ui' => ['widget' => 'text', 'options' => ['help' => 'Ask the office.']],
      ],
      'name' => [
        'title' => 'Name',
        'type' => 'string',
        'readOnly' => TRUE,
        'const' => 'x',
        'x-portable-ui' => ['widget' => 'text'],
        'x-surface' => ['locked' => 'Only the office renames it.'],
      ],
      'kept' => ['title' => 'Kept', 'type' => 'string', 'readOnly' => TRUE, 'x-portable-ui' => ['widget' => 'text']],
      'part' => static::object([
        'inner' => [
          'title' => 'Inner',
          'type' => 'string',
          'readOnly' => TRUE,
          'x-portable-ui' => ['widget' => 'text'],
        ],
      ], [], ['title' => 'Part', 'readOnly' => TRUE, 'x-portable-ui' => ['widget' => 'fieldset']]),
    ]), ['name' => 'x', 'part' => ['inner' => 'y']]);
    $this->assertSame('Four digits. Ask the office.', $form['code']['#description'], 'Help after the description, plain.');
    $this->assertTrue($form['name']['#disabled']);
    $this->assertSame('Only the office renames it.', $form['name']['#description'], 'The reason the document gives.');
    $this->assertSame('Fixed for this operation.', (string) $form['kept']['#description'], 'No reason given: the fixed note.');
    $this->assertTrue($form['part']['#disabled']);
    $this->assertArrayNotHasKey('#disabled', $form['part']['inner'], 'Inside a locked fieldset: inherited, as Form API passes it on.');
    $this->assertArrayNotHasKey('#description', $form['part']['inner'], 'And no note of its own.');

    $whole = $this->draw(static::object([
      'name' => ['title' => 'Name', 'type' => 'string', 'readOnly' => TRUE, 'x-portable-ui' => ['widget' => 'text']],
    ], ['name'], ['readOnly' => TRUE, 'x-surface' => ['locked' => 'Audit only.']]));
    $this->assertTrue($whole['name']['#disabled']);
    $this->assertArrayNotHasKey('#required', $whole['name'], 'Locked whole: nothing is asked.');
    $this->assertArrayNotHasKey('#description', $whole['name'], 'The reason is said once, above the form.');
  }

  /**
   * Rule 5: the empty option the document states, and a stale value.
   */
  public function testTheEmptyOptionAndStaleValue(): void {
    $choices = [['const' => 'a', 'title' => 'A'], ['const' => 'b', 'title' => 'B']];
    $form = $this->draw(static::object([
      'held' => [
        'title' => 'Held',
        'type' => 'string',
        'oneOf' => $choices,
        'x-portable-ui' => [
          'widget' => 'select',
          'options' => ['emptyOption' => ['show' => FALSE, 'label' => '- Select -']],
        ],
      ],
      'open' => [
        'title' => 'Open',
        'type' => ['string', 'null'],
        'oneOf' => $choices,
        'x-portable-ui' => [
          'widget' => 'select',
          'options' => ['emptyOption' => ['show' => TRUE, 'label' => '- None -']],
        ],
      ],
      'gone' => [
        'title' => 'Gone',
        'type' => 'string',
        'oneOf' => $choices,
        'x-portable-ui' => [
          'widget' => 'select',
          'options' => ['emptyOption' => ['show' => TRUE, 'label' => '- Keep c (no longer offered) -']],
        ],
        'x-surface' => ['stale' => TRUE],
      ],
    ], ['held', 'gone']), ['held' => 'a', 'open' => 'b', 'gone' => NULL], ['/gone']);
    $this->assertArrayNotHasKey('#empty_option', $form['held'], 'A valid choice, emptiness no answer: no empty option.');
    $this->assertSame(['- None -', 'b'], [$form['open']['#empty_option'], $form['open']['#default_value']]);
    $this->assertSame([
      '- Keep c (no longer offered) -',
      '',
    ], [$form['gone']['#empty_option'], $form['gone']['#default_value']]);
    $this->assertTrue($form['gone'][SurfaceWidgetBase::STALE_KEY], 'Stale: it stands for what is stored.');
    $this->assertFalse($form['gone']['#required'], 'Empty is "keep", so the browser does not refuse it.');
    $this->assertSame('true', $form['gone']['#attributes']['aria-required']);
    $this->assertSame('gone', $form[SurfaceFormBuilderInterface::STALE_MARKER_KEY]['#value'], 'The marker the rebuild reads.');
  }

  /**
   * Rules 6 and 7: bounds, and choices drawn as checkboxes or radios.
   */
  public function testBoundsAndChoices(): void {
    $form = $this->draw(static::object([
      'count' => [
        'title' => 'Count',
        'type' => 'integer',
        'minimum' => 1,
        'maximum' => 9,
        'x-portable-ui' => ['widget' => 'number'],
      ],
      'ratio' => ['title' => 'Ratio', 'type' => 'number', 'x-portable-ui' => ['widget' => 'number']],
      'name' => [
        'title' => 'Name',
        'type' => 'string',
        'maxLength' => 12,
        'x-portable-ui' => ['widget' => 'text', 'options' => ['size' => 20]],
      ],
      'notes' => [
        'title' => 'Notes',
        'type' => 'string',
        'x-portable-ui' => ['widget' => 'textarea', 'options' => ['rows' => 3]],
      ],
      'days' => [
        'title' => 'Days',
        'type' => 'array',
        'items' => [
          'type' => 'string',
          'oneOf' => [
            ['const' => 'mon', 'title' => 'Monday', 'description' => 'The first.'],
            ['const' => 'tue', 'title' => 'Tuesday'],
          ],
        ],
        'x-portable-ui' => ['widget' => 'checkboxes'],
      ],
      'size' => [
        'title' => 'Size',
        'type' => ['string', 'null'],
        'oneOf' => [['const' => 's', 'title' => 'Small'], ['const' => 'l', 'title' => 'Large']],
        'x-portable-ui' => [
          'widget' => 'radios',
          'options' => ['emptyOption' => ['show' => TRUE, 'label' => '- None -']],
        ],
      ],
    ]), ['days' => ['tue']]);
    $this->assertSame([1, 9, 1], [$form['count']['#min'], $form['count']['#max'], $form['count']['#step']]);
    $this->assertSame('any', $form['ratio']['#step']);
    $this->assertSame([
      12,
      20,
    ], [
      $form['name']['#attributes']['maxlength'],
      $form['name']['#size'],
    ], 'maxLength a hint, size the published option.');
    $this->assertSame(3, $form['notes']['#rows']);
    $this->assertSame([
      'checkboxes',
      ['mon' => 'Monday', 'tue' => 'Tuesday'],
      ['tue'],
    ], [$form['days']['#type'], $form['days']['#options'], $form['days']['#default_value']]);
    $this->assertSame('The first.', $form['days']['mon']['#description']);
    $this->assertSame([
      '',
      's',
      'l',
    ], array_map('strval', array_keys($form['size']['#options'])), 'A "- None -" radio where the key may be cleared.');
  }

  /**
   * Rules 8 and 9: fieldsets, groups by weight, an undeclared group.
   */
  public function testFieldsetsAndGroups(): void {
    $form = $this->draw(static::object([
      'a' => [
        'title' => 'A',
        'type' => 'string',
        'x-portable-ui' => ['widget' => 'text', 'group' => 'more', 'weight' => 2],
      ],
      'b' => [
        'title' => 'B',
        'type' => 'string',
        'x-portable-ui' => ['widget' => 'text', 'group' => 'more', 'weight' => 1],
      ],
      'c' => ['title' => 'C', 'type' => 'string', 'x-portable-ui' => ['widget' => 'text', 'group' => 'nowhere']],
      'map' => static::object([
        'inner' => ['title' => 'Inner', 'type' => 'string', 'x-portable-ui' => ['widget' => 'text']],
      ], ['inner'], ['title' => 'Map', 'x-portable-ui' => ['widget' => 'fieldset']]),
      'third_party_settings' => static::object([
        'mod' => static::object([
          'x' => ['title' => 'X', 'type' => 'boolean', 'x-portable-ui' => ['widget' => 'checkbox']],
        ], [], ['title' => 'Mod', 'x-portable-ui' => ['widget' => 'fieldset'], 'x-surface' => ['after' => 'a']]),
      ], [], ['x-portable-ui' => ['widget' => 'fieldset'], 'x-surface' => ['group' => TRUE]]),
    ], [
      'map',
    ], [
      'x-portable-ui' => [
        'groups' => [
          'more' => ['label' => 'More', 'widget' => 'details', 'options' => ['open' => FALSE], 'weight' => 5],
        ],
      ],
    ]));
    $group = $form[SurfaceFormBuilderInterface::GROUP_PREFIX . 'more'];
    $this->assertSame([
      'details',
      'More',
      FALSE,
      5,
    ], [$group['#type'], (string) $group['#title'], $group['#open'], $group['#weight']]);
    $this->assertSame([2, 1], [$form['a']['#weight'], $form['b']['#weight']]);
    $this->assertArrayNotHasKey(SurfaceFormBuilderInterface::GROUP_KEY, $form['c'], 'A group the frame does not declare: drawn ungrouped.');
    $this->assertSame([
      'details',
      'Map',
      TRUE,
    ], [$form['map']['#type'], $form['map']['#title'], $form['map']['#required']]);
    $this->assertSame('textfield', $form['map']['inner']['#type']);
    $this->assertSame('/map/inner', $form['map']['inner'][SurfaceFormBuilderInterface::PATH_KEY]);
    $this->assertSame('container', $form['third_party_settings']['#type'], 'An object that only groups: nothing drawn around it.');
    $this->assertSame('a', $form['third_party_settings']['mod'][SurfaceFormBuilderInterface::PLACED_AFTER_KEY]);
  }

  /**
   * Rule 10: a list of free values is rows; a collection, one per item.
   */
  public function testListsAndCollections(): void {
    $item = static::object([
      'unit' => [
        'title' => 'Unit',
        'type' => 'string',
        'oneOf' => [['const' => 'cm', 'title' => 'cm'], ['const' => 'in', 'title' => 'in']],
        'x-portable-ui' => ['widget' => 'select'],
      ],
      'height' => [
        'title' => 'Height',
        'type' => 'integer',
        'x-portable-ui' => ['widget' => 'number'],
        'x-surface' => ['dependsOn' => ['unit']],
      ],
    ], [], [
      'allOf' => [
      [
        'if' => ['properties' => ['unit' => ['const' => 'cm']], 'required' => ['unit']],
        'then' => ['properties' => ['height' => ['maximum' => 200]]],
      ],
      [
        'if' => ['properties' => ['unit' => ['const' => 'in']], 'required' => ['unit']],
        'then' => ['properties' => ['height' => ['maximum' => 80]]],
      ],
      ],
    ]);
    $form = $this->draw(static::object([
      'tags' => [
        'title' => 'Tags',
        'type' => 'array',
        'items' => ['type' => 'string'],
        'x-portable-ui' => ['widget' => 'list'],
      ],
      'shelves' => [
        'title' => 'Shelves',
        'type' => 'array',
        'minItems' => 2,
        'maxItems' => 2,
        'items' => $item,
        'x-portable-ui' => ['widget' => 'list'],
        'x-surface' => ['collection' => TRUE],
      ],
    ]), ['tags' => ['a', 'b'], 'shelves' => [['unit' => 'in', 'height' => 40]]]);
    $this->assertSame([
      'fieldset',
      'a',
      'b',
    ], [$form['tags']['#type'], $form['tags'][0]['#default_value'], $form['tags'][1]['#default_value']]);
    $this->assertSame('/tags/1', $form['tags'][1][SurfaceFormBuilderInterface::PATH_KEY]);
    $shelves = $form['shelves'];
    $this->assertSame([
      'details',
      'Shelves 1',
      'Shelves 2',
    ], [$shelves['#type'], (string) $shelves[0]['#title'], (string) $shelves[1]['#title']], 'Padded to its minimum.');
    $this->assertTrue($shelves['@add']['#disabled'], 'At its maximum: nothing more is added.');
    $this->assertTrue($shelves[0]['@remove']['#disabled'], 'At its minimum: nothing is removed.');
    $this->assertSame(80, $shelves[0]['height']['#max'], 'Each item narrowed by the conditionals its values meet.');
    $this->assertArrayNotHasKey('#max', $shelves[1]['height'], 'An item that chose nothing: no bound.');
  }

  /**
   * Rule 11: variants from the parent's conditionals, and nothing chosen.
   */
  public function testVariants(): void {
    $schema = static::object([
      'kind' => [
        'title' => 'Kind',
        'type' => 'string',
        'oneOf' => [['const' => 'card', 'title' => 'Card'], ['const' => 'list', 'title' => 'List']],
        'x-portable-ui' => ['widget' => 'select'],
      ],
      'settings' => [
        'title' => 'Settings',
        'type' => ['object', 'null'],
        'x-portable-ui' => ['widget' => 'variants'],
        'x-surface' => ['dependsOn' => ['kind'], 'by' => 'kind'],
      ],
    ], [], [
      'allOf' => [
      [
        'if' => ['properties' => ['kind' => ['const' => 'card']], 'required' => ['kind']],
        'then' => [
          'properties' => [
            'settings' => static::object([
              'image' => ['title' => 'Image', 'type' => 'boolean', 'x-portable-ui' => ['widget' => 'checkbox']],
            ], [], ['title' => 'Settings']),
          ],
        ],
      ],
      [
        'if' => ['properties' => ['kind' => ['const' => 'list']], 'required' => ['kind']],
        'then' => [
          'properties' => [
            'settings' => static::object([
              'rows' => ['title' => 'Rows', 'type' => 'integer', 'x-portable-ui' => ['widget' => 'number']],
            ], [], ['title' => 'Settings']),
          ],
        ],
      ],
      ],
    ]);
    $card = $this->draw($schema, ['kind' => 'card', 'settings' => ['image' => TRUE]]);
    $this->assertSame(['details', 'checkbox'], [$card['settings']['#type'], $card['settings']['image']['#type']]);
    $this->assertArrayNotHasKey('rows', $card['settings']);
    $this->assertSame(['by' => 'kind', 'for' => 'card'], $card['settings'][SurfaceFormBuilderInterface::DRAWN_FOR_KEY]);
    $this->assertSame('settings', $card['settings']['#surface_refresh'], 'A target of its deciding key, which the rebuild replaces.');
    $this->assertSame(['settings'], $card['kind'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces']);
    $list = $this->draw($schema, ['kind' => 'list', 'settings' => ['rows' => 3]]);
    $this->assertSame(3, $list['settings']['rows']['#default_value'], 'Redrawn for the variant a new envelope chooses.');
    $none = $this->draw($schema, ['kind' => NULL]);
    $this->assertTrue($none['settings'][SurfaceFormBuilderInterface::PLACEHOLDER_KEY], 'Nothing chosen: its wrapper alone.');
  }

  /**
   * Rules 12 and 13: the autocomplete and a key that does not apply.
   */
  public function testAutocompleteAndKeyThatDoesNotApply(): void {
    $host = new class() implements WidgetHostInterface {

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function labelOf(string $entity_type_id, int|string $id): ?string {
        return $entity_type_id === 'user' ? 'Ada, sir' : NULL;
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function selection(string $pointer): ?array {
        return NULL;
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function chosen(string $pointer, string|int|float|bool $value): ?ServedContract {
        return NULL;
      }

    };
    $form = $this->draw(static::object([
      'contact' => [
        'title' => 'Contact',
        'type' => 'integer',
        'portable-check' => ['drupal:SurfaceValidReference' => ['entityTypeId' => 'user']],
        'x-portable-ui' => ['widget' => 'autocomplete'],
      ],
      'open' => ['title' => 'Open', 'type' => 'boolean', 'x-portable-ui' => ['widget' => 'checkbox']],
    ], [], ['x-surface' => ['doesNotApply' => ['reason' => ['open']]]]), ['contact' => 7], [], $host);
    $this->assertSame(['entity_autocomplete', 'user'], [$form['contact']['#type'], $form['contact']['#target_type']]);
    $this->assertSame('"Ada, sir (7)"', $form['contact']['#default_value'], 'The host\'s label, as core\'s element shows a suggestion.');
    $this->assertFalse($form['contact']['#process_default_value']);
    $this->assertTrue($form['reason'][SurfaceFormBuilderInterface::RULED_OUT_KEY], 'Left out of its frame: does not apply, its wrapper alone.');
    $this->assertSame([
      'contact',
      'open',
      'reason',
    ], array_values(array_filter(array_keys($form), static fn (string|int $key): bool => is_string($key) && !str_starts_with($key, '#') && !str_starts_with($key, '@'))), 'After the key it depends on.');
    $this->assertSame('reason', $form[SurfaceFormBuilderInterface::ABSENT_MARKER_KEY]['#value']);
    $this->assertSame(['reason'], $form['open'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces']);
  }

  /**
   * Rule 15: a setting a module's widget publishes reaches its element.
   */
  public function testPublishedSetting(): void {
    $form = $this->draw(static::object([
      'accent' => [
        'title' => 'Accent',
        'type' => 'string',
        'x-portable-ui' => ['widget' => 'widget_test_color', 'options' => ['palette' => ['#336699', '#ffffff']]],
      ],
    ]), ['accent' => '#336699']);
    $this->assertSame([
      'color',
      '#336699 #ffffff',
    ], [$form['accent']['#type'], $form['accent']['#attributes']['data-palette']]);
  }

  /**
   * The envelope a shipped example serves is drawn by the same rules.
   */
  public function testShippedEnvelope(): void {
    $resources = $this->container->get('surface.resources');
    $surface = $resources->build(RegistrationStep2Resource::class, $resources->operation(RegistrationStep2Resource::class, 'configure'));
    $values = $surface->getDefaultValues();
    $form = $this->formBuilder()->buildSurfaceForm($surface, $values, new FormState());
    $envelope = $this->formBuilder()->envelope($surface, $values)->document['schema'];
    $this->assertSame('select', $envelope['properties']['venue']['x-portable-ui']['widget']);
    $this->assertSame('select', $form['venue']['#type']);
    $this->assertSame(array_map(static fn (array $choice): string => $choice['title'], $envelope['properties']['venue']['oneOf']), array_values(array_map('strval', $form['venue']['#options'])));
    $this->assertSame([
      $envelope['properties']['capacity']['minimum'] ?? NULL,
      $envelope['properties']['capacity']['maximum'] ?? NULL,
    ], [$form['capacity']['#min'] ?? NULL, $form['capacity']['#max'] ?? NULL]);
    $this->assertContains('room', $form['venue'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces'], 'What its dependsOn names rebuilds it.');
  }

  /**
   * Builds a shipped example's operation.
   *
   * @param class-string $class
   *   The resource.
   * @param string $operation
   *   The operation.
   *
   * @return \Drupal\surface\SurfaceInterface
   *   The built resource.
   */
  protected function shipped(string $class, string $operation = 'configure'): SurfaceInterface {
    $resources = $this->container->get('surface.resources');
    return $resources->build($class, $resources->operation($class, $operation));
  }

  /**
   * Groups and weights, on the catering's envelope.
   */
  public function testShippedGroups(): void {
    $surface = $this->shipped(CateringResource::class);
    $values = $surface->getDefaultValues();
    $schema = $this->formBuilder()->envelope($surface, $values)->document['schema'];
    $this->assertSame('Details', $schema['x-portable-ui']['groups']['details']['label']);
    $form = $this->formBuilder()->buildSurfaceForm($surface, $values, new FormState());
    $group = $form[SurfaceFormBuilderInterface::GROUP_PREFIX . 'details'];
    $this->assertSame('Details', (string) $group['#title'], 'Drawn where its first key is.');
    $this->assertSame('details', $form['budget'][SurfaceFormBuilderInterface::GROUP_KEY]['id']);
    $this->assertSame('details', $form['notes'][SurfaceFormBuilderInterface::GROUP_KEY]['id']);
    $this->assertSame('EUR', (string) $form['budget']['#field_suffix'], 'The suffix the arrangement gives.');
    $keys = array_values(array_filter(array_keys($form), static fn (int|string $key): bool => is_string($key) && !str_starts_with($key, '#') && !str_starts_with($key, '@') && $key !== SurfaceFormBuilderInterface::ABSENT_MARKER_KEY));
    $this->assertSame(array_keys((array) $schema['properties']), array_values(array_intersect($keys, array_keys((array) $schema['properties']))), 'In the order the document states.');
  }

  /**
   * A stale select, on the registration's envelope after its venue moved.
   */
  public function testShippedStaleSelect(): void {
    $surface = $this->shipped(RegistrationStep2Resource::class);
    $stored = [
      'title' => 'Spring meetup',
      'open' => TRUE,
      'venue' => 'riverside',
      'room' => 'riverside_main',
      'capacity' => 50,
    ];
    $values = $this->formBuilder()->refinementOverlay($surface, $stored, ['venue' => 'harbour'] + $stored);
    $envelope = $this->formBuilder()->envelope($surface, $values);
    $this->assertContains('/room', $envelope->document['stale']);
    $form = $this->formBuilder()->buildSurfaceForm($surface, $values, new FormState());
    $this->assertSame('', $form['room']['#default_value'], 'The empty option, selected.');
    $this->assertSame($envelope->document['schema']['properties']['room']['x-portable-ui']['options']['emptyOption']['label'], (string) $form['room']['#empty_option'], 'Worded as the document says.');
    $this->assertSame('riverside_main', $form['room'][SurfaceWidgetBase::STALE_KEY], 'It stands for what is stored.');
    $this->assertArrayNotHasKey('riverside_main', $form['room']['#options'], 'Never offered again.');
  }

  /**
   * Variants redrawn, on the registration's envelope for each pricing.
   */
  public function testShippedVariantsRedraw(): void {
    $surface = $this->shipped(RegistrationStep3Resource::class);
    $values = $surface->getDefaultValues();
    $free = $this->formBuilder()->buildSurfaceForm($surface, ['pricing' => 'free'] + $values, new FormState());
    $this->assertSame(['by' => 'pricing', 'for' => 'free'], $free['ticket'][SurfaceFormBuilderInterface::DRAWN_FOR_KEY]);
    $paid = $this->formBuilder()->buildSurfaceForm($surface, $this->formBuilder()->refinementOverlay($surface, $values, ['pricing' => 'paid'] + $values), new FormState());
    $this->assertSame(['by' => 'pricing', 'for' => 'paid'], $paid['ticket'][SurfaceFormBuilderInterface::DRAWN_FOR_KEY]);
    $this->assertArrayHasKey('price', $paid['ticket'], 'The paid ticket\'s keys.');
    $this->assertContains('ticket', $paid['pricing'][SurfaceFormBuilderInterface::TRIGGER_KEY]['replaces']);
    $this->assertNull(DocumentNodes::of($this->formBuilder()->envelope($surface, ['pricing' => 'free'] + $values))->at('/ticket')?->get('description'), 'The free ticket\'s def says what the structure is, not what the key is for.');
  }

  /**
   * A key that does not apply, on the bulletin's envelope.
   */
  public function testShippedKeyThatDoesNotApply(): void {
    $surface = $this->shipped(BulletinResource::class);
    $values = ['frequency' => 'monthly'] + $surface->getDefaultValues();
    $schema = $this->formBuilder()->envelope($surface, $values)->document['schema'];
    $this->assertArrayNotHasKey('send_day', (array) $schema['properties'], 'Left out of its frame.');
    $form = $this->formBuilder()->buildSurfaceForm($surface, $values, new FormState());
    $this->assertTrue($form['send_day'][SurfaceFormBuilderInterface::RULED_OUT_KEY], 'Its wrapper alone.');
    $this->assertSame('send_day', $form[SurfaceFormBuilderInterface::ABSENT_MARKER_KEY]['#value']);
    $weekly = $this->formBuilder()->buildSurfaceForm($surface, ['frequency' => 'weekly'] + $surface->getDefaultValues(), new FormState());
    $this->assertSame('select', $weekly['send_day']['#type']);
    $this->assertSame('5', (string) $weekly['items']['#max'], 'Narrowed for a weekly issue.');
  }

  /**
   * A def's description stays off a variant's key.
   */
  public function testShippedVariantKeysShowNoDefDescription(): void {
    $mailer = $this->shipped(MailerResource::class);
    $nodes = DocumentNodes::of($this->formBuilder()->envelope($mailer, $mailer->getDefaultValues()));
    $this->assertNotNull($nodes->at('/credential'));
    $this->assertNull($nodes->at('/credential')->get('description'));
    $form = $this->formBuilder()->buildSurfaceForm($mailer, $mailer->getDefaultValues(), new FormState());
    $this->assertArrayNotHasKey('#description', $form['credential']);
  }

  /**
   * Check options that name a handler win over the host's answer.
   *
   * No shipped document writes them; a document another host writes may.
   */
  public function testCheckOptionsNamingTheHandlerWin(): void {
    $host = new class() implements WidgetHostInterface {

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function labelOf(string $entity_type_id, int|string $id): ?string {
        return NULL;
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function selection(string $pointer): array {
        return ['handler' => 'default:user', 'handlerSettings' => ['include_anonymous' => TRUE]];
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function chosen(string $pointer, string|int|float|bool $value): ?ServedContract {
        return NULL;
      }

    };
    $reference = static fn (array $options): array => [
      'title' => 'Contact',
      'type' => 'integer',
      'portable-check' => ['drupal:SurfaceValidReference' => ['entityTypeId' => 'user'] + $options],
      'x-portable-ui' => ['widget' => 'autocomplete'],
    ];
    $form = $this->draw(static::object([
      'named' => $reference(['handler' => 'default:user', 'handlerSettings' => ['include_anonymous' => FALSE]]),
      'unnamed' => $reference([]),
    ]), [], [], $host);
    $this->assertSame(['include_anonymous' => FALSE], $form['named']['#selection_settings'], 'The options the document carries.');
    $this->assertSame(['include_anonymous' => TRUE], $form['unnamed']['#selection_settings'], 'The host\'s, where the document carries none.');
    $this->assertSame('default:user', $form['unnamed']['#selection_handler']);
  }

  /**
   * A taken machine name is refused at its element, by the key's check.
   *
   * Core's machine name element is drawn with no `exists` callback and no
   * validation of its own: the pipeline's SurfaceUnique refuses the name,
   * as at every door, and the refusal is flagged on the element.
   */
  public function testTakenMachineNameIsRefusedAtTheElement(): void {
    $surface = $this->shipped(VenueResource::class, 'create');
    $values = ['label' => 'Riverside Hall', 'id' => 'riverside'] + $surface->getDefaultValues();
    $form_state = (new FormState())->setFormObject(new class() extends FormBase {

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function getFormId(): string {
        return 'surface_machine_name';
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function buildForm(array $form, FormStateInterface $form_state): array {
        return $form;
      }

      // phpcs:ignore Drupal.Commenting.FunctionComment.Missing
      public function submitForm(array &$form, FormStateInterface $form_state): void {
      }

    });
    $form = [
      '#form_id' => 'surface_machine_name',
      '#build_id' => 'machine-name',
      'surface' => $this->formBuilder()->buildSurfaceForm($surface, $values, $form_state),
    ];
    $this->assertSame('machine_name', $form['surface']['id']['#type']);
    $this->assertArrayNotHasKey('exists', $form['surface']['id']['#machine_name'], 'No callback of its own.');
    $this->assertSame([], $form['surface']['id']['#element_validate']);
    $builder = $this->container->get('form_builder');
    $builder->prepareForm('surface_machine_name', $form, $form_state);
    $form = $builder->doBuildForm('surface_machine_name', $form, $form_state);
    $this->assertFalse($this->formBuilder()->validateSurfaceForm($surface, $values, $form['surface'], $form_state));
    $errors = array_map('strval', $form_state->getErrors());
    $this->assertArrayHasKey('surface][id', $errors, json_encode($errors));
    $this->assertStringContainsString('riverside', $errors['surface][id']);
  }

}
