<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\data_surface\DataSurfaceInterface;
use Drupal\data_surface_resource_test\Resource\Library\OpeningHoursResource;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Holds each common settings recipe to what docs/recipes.md says it does.
 *
 * QuotedCodeDocsTest holds the page's code to the fixture; this holds the
 * fixture to the page's words: what each key draws, what the full schema
 * states and what the pipeline refuses.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class RecipesTest extends DataSurfaceKernelTestBase {

  use UserCreationTrait;

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
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
   * Builds the opening hours in their operation.
   *
   * @return \Drupal\data_surface\DataSurfaceInterface
   *   The built resource.
   */
  protected function hours(): DataSurfaceInterface {
    $resources = $this->container->get('data_surface.resources');
    return $resources->build(OpeningHoursResource::class, $resources->operation(OpeningHoursResource::class, 'configure'));
  }

  /**
   * Accepts and validates values, as every door does.
   *
   * @param array $values
   *   The values sent.
   *
   * @return array<string, string>
   *   The refusals, by key, as plain text.
   */
  protected function refusals(array $values): array {
    $stored = $this->config('data_surface_resource_test.opening_hours')->getRawData();
    $accepted = $this->pipeline()->accept($this->hours(), $values, $stored);
    $messages = [];
    foreach ($this->pipeline()->validate($this->hours(), $accepted, $stored) as $violation) {
      $messages[$violation->fullPath()] = strip_tags((string) $violation->message);
    }
    return $messages;
  }

  /**
   * Tests each recipe draws the element the page says.
   */
  public function testEachRecipeDraws(): void {
    $hours = $this->hours();
    $form = $this->formBuilder()->buildSurfaceForm($hours, $hours->getDefaultValues(), new FormState());
    $this->assertSame(['date', 'time'], [$form['opens']['#type'], $form['opens']['#attributes']['type']], 'A time of day is a time input.');
    $this->assertSame('number', $form['closes']['#type']);
    $this->assertSame([721, 1439], [$form['closes']['#min'], $form['closes']['#max']]);
    $this->assertSame('checkboxes', $form['days']['#type'], 'A list of a few choices is checkboxes.');
    $this->assertSame('textarea', $form['notes']['#type']);
    $this->assertArrayNotHasKey('#type', $form['door_code'], 'No element while after-hours access is off.');
    $this->assertSame('fieldset', $form['keywords']['#type'], 'A list of free strings is rows to add and remove.');
    $this->assertSame('Add another', (string) $form['keywords']['add']['#value']);

    $this->assertArrayNotHasKey('#type', $form['late_days'], 'No element while after-hours access is off.');
    $open = $this->formBuilder()->buildSurfaceForm($hours, ['after_hours' => TRUE] + $hours->getDefaultValues(), new FormState());
    $this->assertTrue($open['door_code']['#required']);
    $this->assertSame('checkboxes', $open['late_days']['#type']);

    $full = $this->container->get('data_surface.contract_emitter')->emitFull($hours, 'resource_test.opening_hours', widgets: TRUE)->document['schema'];
    $this->assertSame('^([01]\d|2[0-3]):[0-5]\d$', $full['properties']['opens']['pattern']);
    $this->assertSame([721, 1439], [$full['properties']['closes']['minimum'], $full['properties']['closes']['maximum']]);
    $this->assertSame('textarea', $full['properties']['notes']['x-surface']['widget']);
    $this->assertSame('time', $full['properties']['opens']['x-surface']['widget']);
    $this->assertSame('checkboxes', $full['properties']['days']['x-surface']['widget']);
    $this->assertSame('list', $full['properties']['keywords']['x-surface']['widget']);
    $this->assertSame(1, $full['properties']['late_days']['minItems'], 'At least one day, stated wherever the key applies.');
  }

  /**
   * Tests each recipe refuses what the page says.
   */
  public function testEachRecipeRefuses(): void {
    $this->assertSame([], $this->refusals([]));
    $this->assertSame(['opens' => 'Write the time as HH:MM, such as 09:00.'], $this->refusals(['opens' => '9am']));
    $closes = $this->refusals(['closes' => 720]);
    $this->assertSame(['closes' => 'This value should be between 721 and 1439.'], $closes, 'Noon is not later than noon.');
    $days = $this->refusals(['days' => ['mon', 'xyz']]);
    $this->assertSame(['days.1' => 'The value you selected is not a valid choice. Choose Monday, Tuesday, Wednesday, Thursday, Friday, Saturday or Sunday.'], $days, 'A refused item lists its choices, as a single choice does.');
    $this->assertSame(['door_code' => 'Door code does not apply when After-hours access is off.'], $this->refusals(['door_code' => '7310']));
    $this->assertSame([
      'door_code' => 'Door code is required.',
      'late_days' => 'Choose at least one day for after-hours access.',
    ], $this->refusals(['after_hours' => TRUE]), 'Required where it applies, by its own declaration; a list by its Count.');
    $this->assertSame([], $this->refusals(['after_hours' => TRUE, 'door_code' => '7310', 'late_days' => ['sat']]));
    $empty = $this->refusals([
      'after_hours' => TRUE,
      'door_code' => '7310',
      'late_days' => [],
    ]);
    $this->assertSame(['late_days' => 'Choose at least one day for after-hours access.'], $empty, 'The empty list is configured, so required alone would take it.');
    $this->assertSame(['late_days' => 'After-hours days does not apply when After-hours access is off.'], $this->refusals(['late_days' => ['sat']]));
    $this->assertSame([], $this->refusals(['keywords' => ['maps', 'archives']]));
  }

  /**
   * Tests the opening hours' schema asks what docs/storage.md says it does.
   *
   * Fully validatable: every key stored unless it says requiredKey:
   * false, and null only where it says nullable: true.
   */
  public function testTheSchemaIsFullyValidatable(): void {
    $typed = $this->container->get('config.typed');
    $name = 'data_surface_resource_test.opening_hours';
    // Written from the resource, it holds nothing translatable, so no
    // language code either.
    $stored = $this->config($name)->getRawData();
    $violations = static function (array $data) use ($typed, $name): array {
      $messages = [];
      foreach ($typed->createFromNameAndData($name, $data)->validate() as $violation) {
        $messages[$violation->getPropertyPath()] = strip_tags((string) $violation->getMessage());
      }
      return $messages;
    };
    $this->assertSame([], $violations($stored), 'The installed object holds every key.');
    $this->assertSame([], $violations(['door_code' => NULL, 'notes' => NULL] + $stored), 'A nullable key may hold nothing.');
    $this->assertArrayHasKey('opens', $violations(['opens' => NULL] + $stored), 'Any other may not.');
    $left_out = $stored;
    unset($left_out['holidays']);
    $this->assertSame(["'holidays' is a required key."], array_values($violations($left_out)), 'Every key is stored.');
  }

  /**
   * Tests a key left out keeps what is stored, so required asks no resend.
   *
   * On an edit, a required key is refused only when it is sent empty or
   * nothing is stored for it.
   */
  public function testKeyLeftOutKeepsWhatIsStored(): void {
    $this->config('data_surface_resource_test.opening_hours')->set('door_code', '7310')->set('late_days', ['sat'])->save();
    $this->assertSame([], $this->refusals(['after_hours' => TRUE]), 'Both left out: what is stored stands.');
    $refused = ['door_code' => 'Door code is required.'];
    $this->assertSame($refused, $this->refusals(['after_hours' => TRUE, 'door_code' => '']), 'Sent empty, it is refused.');
    $this->assertSame($refused, $this->refusals(['after_hours' => TRUE, 'door_code' => NULL]));
  }

}
