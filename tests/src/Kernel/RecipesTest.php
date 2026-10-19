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
    $this->assertSame('textfield', $form['opens']['#type'], 'A time of day is typed.');
    $this->assertSame('number', $form['closes']['#type']);
    $this->assertSame([721, 1439], [$form['closes']['#min'], $form['closes']['#max']]);
    $this->assertSame('select', $form['days']['#type'], 'A list of choices is a multiple select.');
    $this->assertTrue($form['days']['#multiple']);
    $this->assertSame('textarea', $form['notes']['#type']);
    $this->assertArrayNotHasKey('#type', $form['door_code'], 'No element while after-hours access is off.');
    $this->assertSame('textarea', $form['keywords']['#type'], 'A list of free strings, drawn by a widget of its own.');

    $open = $this->formBuilder()->buildSurfaceForm($hours, ['after_hours' => TRUE] + $hours->getDefaultValues(), new FormState());
    $this->assertTrue($open['door_code']['#required']);

    $full = $this->container->get('data_surface.contract_emitter')->emitFull($hours, 'resource_test.opening_hours', widgets: TRUE)->document['schema'];
    $this->assertSame('^([01]\d|2[0-3]):[0-5]\d$', $full['properties']['opens']['pattern']);
    $this->assertSame([721, 1439], [$full['properties']['closes']['minimum'], $full['properties']['closes']['maximum']]);
    $this->assertSame('textarea', $full['properties']['notes']['x-surface']['widget']);
    $days = $full['properties']['days']['x-surface'];
    $this->assertSame(['select', TRUE], [$days['widget'], $days['multiple']]);
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
    $this->assertSame(['days.1' => 'The value you selected is not a valid choice.'], $days);
    $this->assertSame(['door_code' => 'Door code does not apply when After-hours access is off.'], $this->refusals(['door_code' => '7310']));
    $this->assertSame(['door_code' => 'Door code is required.'], $this->refusals(['after_hours' => TRUE]), 'Required where it applies, by its own declaration.');
    $this->assertSame([], $this->refusals(['after_hours' => TRUE, 'door_code' => '7310']));
    $this->assertSame([], $this->refusals(['keywords' => ['maps', 'archives']]));
  }

}
