<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\surface\Pipeline\ResultDocument;
use Drupal\surface\Pipeline\Violation;
use Drupal\surface_examples\ConceptCalls;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\surface\Traits\ServedControllerTrait;
use Drupal\Tests\user\Traits\UserCreationTrait;
use Drupal\tool\Tool\ToolInterface;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests every path a result carries is a JSON Pointer, at every door.
 *
 * Contract 3: a violation's, a note's and a stale entry's `path`, and each
 * entry of `changes`, are JSON Pointers (RFC 6901) at the write tool (its
 * result's context, which `drush tool:run --json` prints as `outputs`,
 * and its text), at the HTTP door (a result, a refusal's problem
 * details, the envelope's `stale` and `notes`, a narrowing's
 * `discarded`), the whole call the empty pointer; the pipeline keeps the
 * dotted path, and the form its own naming.
 *
 * @see docs/decisions.md#one-result-at-every-door
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ResultPointersTest extends SurfaceKernelTestBase {

  use ServedControllerTrait;

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
    'surface_examples',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
    $this->setUpCurrentUser([], ['administer site configuration']);
  }

  /**
   * Runs a write tool, dry.
   *
   * @param string $resource
   *   The resource id.
   * @param array $values
   *   The values.
   *
   * @return array{0: array, 1: string}
   *   Its result's context, and its message.
   */
  protected function tool(string $resource, array $values): array {
    $tool = $this->container->get('plugin.manager.tool')->createInstance('surface:' . $resource . ':configure');
    assert($tool instanceof ToolInterface);
    $tool->setInputValue(OperationInputs::VALUES, $values);
    $tool->setInputValue(OperationInputs::DRY_RUN, TRUE);
    $tool->execute();
    return [$tool->getResult()->getContextValues(), (string) $tool->getResult()->getMessage()];
  }

  /**
   * Tests the pointer is RFC 6901's, and reads back.
   */
  public function testThePointer(): void {
    $this->assertSame('', ResultDocument::pointer(''));
    $this->assertSame('/room', ResultDocument::pointer('room'));
    $this->assertSame('/shelves/1/unit', ResultDocument::pointer('shelves.1.unit'));
    $this->assertSame('/a~1b/c~0d', ResultDocument::pointer('a/b.c~d'));
    foreach (['', 'room', 'shelves.1.unit', 'a/b.c~d'] as $dotted) {
      $this->assertSame($dotted, ResultDocument::dotted(ResultDocument::pointer($dotted)));
    }
    $this->assertSame('room', ResultDocument::dotted('room'), 'A dotted path is read as one.');
  }

  /**
   * Tests a refusal's path at the tool and at HTTP, structured and as text.
   */
  public function testRefusalPathIsPointerAtEveryDoor(): void {
    $sent = ['venue' => 'harbour', 'room' => 'library_garden'];
    [$tool, $message] = $this->tool('registration.step2', $sent);
    $this->assertSame(['/room' => 'no_such_choice'], array_column($tool['violations'], 'code', 'path'));
    $this->assertStringContainsString("\n/room: The value you selected is not a valid choice.", $message, 'The text names the path as the result does.');

    $problem = $this->servedJson('PATCH', '/surface-api/registration.step2?dryRun=true', $sent);
    $this->assertSame(422, $problem['status']);
    $this->assertSame(array_column($tool['violations'], 'code', 'path'), array_column($problem['errors'], 'code', 'path'));

    // Inside a child: the press contact's email, two segments.
    $listed = ConceptCalls::CALLS['event.press']['listed_without_email']['values'];
    [$tool] = $this->tool('event.press', $listed);
    $this->assertSame(['/contact/email' => Violation::REQUIRED], array_column($tool['violations'], 'code', 'path'));
    $problem = $this->servedJson('PATCH', '/surface-api/event.press?dryRun=true', $listed);
    $this->assertSame(['/contact/email' => Violation::REQUIRED], array_column($problem['errors'], 'code', 'path'));
  }

  /**
   * Tests a note, a stale entry and a change are pointers at every door.
   */
  public function testNotesStaleAndChangesArePointers(): void {
    // A value sent for a locked key: a note, by pointer.
    $values = ConceptCalls::CALLS['event.sales']['speaker_on_sale']['values'];
    [$tool, $message] = $this->tool('event.sales', $values);
    $this->assertSame(['/on_sale/speaker' => Violation::LOCKED], array_column($tool['notes'], 'code', 'path'));
    $this->assertStringContainsString('/on_sale/speaker: ', $message);
    $answer = $this->servedJson('PATCH', '/surface-api/event.sales?dryRun=true', $values);
    $this->assertSame(['/on_sale/speaker' => Violation::LOCKED], array_column($answer['notes'], 'code', 'path'));
    foreach ($answer['changes'] as $change) {
      $this->assertStringStartsWith('/', $change);
    }

    // A stored room the stored venue no longer offers: kept, stale.
    $this->config('surface_examples.registration_step2')->set('venue', 'harbour')->set('room', 'library_garden')->save();
    [$tool] = $this->tool('registration.step2', ['title' => 'Kept']);
    $this->assertSame(['/room' => Violation::STALE], array_column($tool['stale'], 'code', 'path'));
    $this->assertSame(['/title'], $tool['changes']);
    $answer = $this->servedJson('PATCH', '/surface-api/registration.step2?dryRun=true', ['title' => 'Kept']);
    $this->assertSame(['/room' => Violation::STALE], array_column($answer['stale'], 'code', 'path'));
    $this->assertSame(['/title'], $answer['changes']);

    // The envelope's stale paths and their notes; a narrowing's discarded.
    $envelope = $this->servedJson('GET', '/surface-api/registration.step2');
    $this->assertSame(['/room'], $envelope['stale']);
    $this->assertSame(['/room' => Violation::STALE], array_column($envelope['notes'], 'code', 'path'));
    $this->config('surface_examples.registration_step2')->set('venue', 'riverside')->set('room', 'riverside_main')->save();
    $narrowed = $this->servedJson('POST', '/surface-api/registration.step2/schema', [
      'venue' => 'library',
      'room' => 'riverside_main',
      'capacity' => 50,
    ]);
    $this->assertSame(['/room', '/capacity'], $narrowed['discarded']);
    $this->assertSame(['/room'], $narrowed['stale']);
    // The header the stale paths go back in takes the same pointers.
    $back = $this->servedJson('POST', '/surface-api/registration.step2/schema', ['venue' => 'riverside', 'room' => NULL], ['X-Surface-Stale' => '/room']);
    $this->assertSame('riverside_main', $back['values']['room']);
  }

  /**
   * Tests a refusal of the whole call is the empty pointer.
   */
  public function testTheWholeCallIsTheEmptyPointer(): void {
    $problem = $this->servedJson('PATCH', '/surface-api/registration.step2', ['title' => 'Late'], ['If-Match' => '"not-what-is-stored"']);
    $this->assertSame(412, $problem['status']);
    $this->assertSame(['' => Violation::CONFLICT], array_column($problem['errors'], 'code', 'path'));
  }

}
