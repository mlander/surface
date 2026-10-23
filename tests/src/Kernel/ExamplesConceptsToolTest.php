<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_examples\ConceptCalls;
use Drupal\data_surface_examples\Hook\SpotlightBlockHooks;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tool calls of examples 7 to 13, and the README quoting them.
 *
 * Makes every call ConceptCalls writes down, on the settings the module
 * ships, the way each example's panel makes it, with example 10's
 * workflow module on for the call that needs it, and example 13's
 * component block on with its Spotlight block placed: what each answers is
 * pinned here for the rule it shows, the README must quote every command
 * and every answer as made, and none of the calls writes anything.
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ExamplesConceptsToolTest extends DataSurfaceKernelTestBase {

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
    'data_surface',
    'data_surface_tool',
    'data_surface_examples',
    'data_surface_examples_workflow',
    'block',
    'path_alias',
    'data_surface_component_block',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installConfig(['system', 'data_surface_examples']);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('class_resolver')->getInstanceFromDefinition(SpotlightBlockHooks::class)->place();
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Reads every config object the examples ship, and the Spotlight block.
   *
   * @return array<string, array>
   *   The stored values, by config object.
   */
  protected function everything(): array {
    $factory = $this->container->get('config.factory');
    $factory->reset();
    $stored = [];
    foreach ([...$factory->listAll('data_surface_examples.'), 'block.block.' . SpotlightBlockHooks::BLOCK] as $name) {
      $stored[$name] = $factory->get($name)->getRawData();
    }
    return $stored;
  }

  /**
   * Tests every call answers as pinned, is quoted, and writes nothing.
   */
  public function testTheCallsAnswerAndWriteNothing(): void {
    $before = $this->everything();
    $panel = $this->container->get('data_surface_examples.concept_panel');
    $answers = [];
    foreach (ConceptCalls::CALLS as $resource => $calls) {
      foreach (array_keys($calls) as $name) {
        $this->assertTrue($this->container->get('plugin.manager.tool')->hasDefinition(ConceptCalls::tool($resource, $name)));
        $answers[$resource][$name] = $panel->answer($resource, $name);
      }
    }
    $this->assertSame($before, $this->everything(), 'No call wrote anything.');

    // The answer's first line and, after a refusal, its first violation.
    $first = static fn (string $answer): string => implode("\n", array_slice(explode("\n", $answer), 0, str_starts_with($answer, 'Failed: ') ? 2 : 1));
    $this->assertSame("Failed: The values were refused:\n" . '/send_day: Send day does not apply when frequency is monthly (Monthly).', $first($answers['event.bulletin']['monthly_with_day']));
    $this->assertSame("Failed: The values were refused:\n" . '/items: This value should be between 1 and 5. This applies when frequency is weekly (Weekly).', $first($answers['event.bulletin']['weekly_items']));
    $this->assertStringContainsString('values: {"frequency":"monthly","items":8}', $answers['event.bulletin']['monthly_items'], 'The send day does not apply, so no answer says it; storage keeps it.');
    $this->assertSame("Failed: The values were refused:\n" . '/contact/email: Email is required when public is on.', $first($answers['event.press']['listed_without_email']));
    $this->assertStringStartsWith('Success: ', $answers['event.press']['unlisted_without_email']);
    $this->assertSame("Failed: The values were refused:\n" . '/reviewed_by: Reviewed by is required when reviewed_on is set.', $first($answers['event.press']['reviewed_by_nobody']));
    $this->assertSame("Failed: The values were refused:\n" . '/phone: Write the number in digits, such as +1 555 0100.', $first($answers['event.desk']['phone_with_dashes']));
    $this->assertStringContainsString("nothing was written.\n/on_sale/speaker: Speaker is locked and kept as stored; the value sent was not taken.\n", $answers['event.sales']['speaker_on_sale'], 'A note on a line of its own, after the message.');
    $this->assertStringContainsString('"speaker":false', $answers['event.sales']['speaker_on_sale']);
    $this->assertSame("Failed: The values were refused:\n" . '/limits/student/per_order: Per order does not apply when on_sale.student is off.', $first($answers['event.sales']['limit_off_sale']));
    $this->assertSame('Success: Check a program changes nothing: the values were accepted and prepared, and nothing was written.', $first($answers['event.program']['check']));
    $this->assertSame("Failed: The values were refused:\n" . '/sessions: This value should be between 1 and 30.', $first($answers['event.program']['check_too_many']));
    $this->assertSame("Failed: The values were refused:\n" . 'This may be seen and not changed, so nothing was written: The program is published in its workflow, so it is fixed.', $first($answers['event.program']['published_in_workflow']));
    $this->assertNull($this->container->get('state')->get('data_surface_examples_workflow.state'), 'The State the call was made in is put back.');
    $this->assertStringContainsString('"notes":"Hand out the maps."', $answers['event.doors']['left_out'], 'Left out: kept.');
    $this->assertStringContainsString('"notes":null', $answers['event.doors']['null'], 'Null: cleared.');
    $this->assertStringContainsString('"notes":"Doors close at ten."', $answers['event.doors']['value'], 'A value: replaced.');
    $this->assertSame("Failed: The values were refused:\n" . '/badges: Badges cannot be cleared: it always holds a value, printed (Printed at the door) by default. Leave it out to keep what it holds.', $first($answers['event.doors']['always_held']));
    $this->assertStringContainsString('values: {"component":"data_surface_examples:hero","props":{"title":"Doors open at half past six","subtitle":null,"align":"start","overlay":40,"full_width":false}}', $answers['event.spotlight']['hero'], 'The hero\'s props, read from its schema, its defaults filled in.');
    $this->assertSame("Failed: The values were refused:\n" . '/props/align: props.align belongs to the data_surface_examples:hero variant, but component chose data_surface_examples:card.', $first($answers['event.spotlight']['wrong_component']), 'Refused, naming the component the props belong to.');
    $this->assertSame("Failed: The values were refused:\n" . '/props/overlay: This value should be between 0 and 100.', $first($answers['event.spotlight']['out_of_bounds']));
    $this->assertStringContainsString('values: {"block":"data_surface_examples_spotlight","settings":{"component":"data_surface_examples:hero","props":{"title":"Doors open at half past six","subtitle":null,"align":"start","overlay":40,"full_width":false}}}', $answers['block.placed_component']['hero'], 'The placed block named by its machine name, the hero read from its schema.');

    // The examples' README quotes every command, and every answer under
    // its call's label.
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/data_surface_examples/README.md');
    foreach ($answers as $resource => $made) {
      $number = 0;
      foreach ($made as $name => $answer) {
        $this->assertStringContainsString(ConceptCalls::drush($resource, $name), $readme);
        $this->assertStringContainsString(sprintf("%d. %s\n%s\n", ++$number, ConceptCalls::CALLS[$resource][$name]['label'], $answer), $readme, sprintf('The README quotes %s %s as it answers now.', $resource, $name));
      }
    }
  }

}
