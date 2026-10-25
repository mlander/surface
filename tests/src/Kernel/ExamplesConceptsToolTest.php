<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Kernel;

use Drupal\data_surface_examples\ConceptCalls;
use Drupal\data_surface_examples\Hook\SpotlightBlockHooks;
use Drupal\data_surface_examples_references\Sponsorship;
use Drupal\data_surface_examples_retitle\Retitle;
use Drupal\node\Entity\Node;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the tool calls of examples 7 to 17, and the README quoting them.
 *
 * Makes every call ConceptCalls writes down, on the settings the module
 * ships, the way each example's panel makes it, with example 10's
 * workflow module on for the call that needs it, example 13's
 * component block and placement resource on with its Spotlight block
 * placed, example 14's module that manages keys on, and examples 16's
 * and 17's modules on with their three nodes each written: what each
 * answers is
 * pinned here for the rule it shows, the README must quote every command
 * and every answer as made, and nothing is left written: example 15's
 * calls write a fourth venue and remove it again with the last, and
 * example 17's retitle its pages and put each title back with the last.
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
    'data_surface_block',
    'data_surface_examples_keys',
    'text',
    'filter',
    'node',
    'data_surface_examples_references',
    'data_surface_examples_retitle',
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installSchema('node', ['node_access']);
    // Example 16's bare key counts what each content entity type holds.
    $this->installEntitySchema('path_alias');
    $this->installConfig([
      'system',
      'node',
      'filter',
      'data_surface_examples',
      'data_surface_examples_keys',
      'data_surface_examples_references',
      'data_surface_examples_retitle',
    ]);
    $this->container->get('theme_installer')->install(['stark']);
    $this->config('system.theme')->set('default', 'stark')->save();
    $this->container->get('class_resolver')->getInstanceFromDefinition(SpotlightBlockHooks::class)->place();
    $this->setUpCurrentUser(admin: TRUE);
    // Example 16's three nodes, as its module writes them when installed.
    $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->writeNodes();
    // Example 17's three pages, as its module writes them when installed.
    $this->container->get('class_resolver')->getInstanceFromDefinition(Retitle::class)->writeNodes();
  }

  /**
   * Reads every config object the examples ship, and the two blocks.
   *
   * @return array<string, array>
   *   The stored values, by config object.
   */
  protected function everything(): array {
    $factory = $this->container->get('config.factory');
    $factory->reset();
    $stored = [];
    $blocks = ['block.block.' . SpotlightBlockHooks::BLOCK, 'block.block.' . SpotlightBlockHooks::SECTION];
    foreach ([...$factory->listAll('data_surface_examples.'), ...$blocks] as $name) {
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
    $this->assertSame($before, $this->everything(), 'No call left anything written.');

    // The answer's first line and, after a refusal, its first violation.
    $first = static fn (string $answer): string => implode("\n", array_slice(explode("\n", $answer), 0, str_starts_with($answer, 'Failed: ') ? 2 : 1));
    $this->assertSame("Failed: The values were refused:\n" . '/send_day: Send day does not apply when Frequency is monthly (Monthly).', $first($answers['event.bulletin']['monthly_with_day']));
    $this->assertSame("Failed: The values were refused:\n" . '/items: This value should be between 1 and 5. This applies when Frequency is weekly (Weekly).', $first($answers['event.bulletin']['weekly_items']));
    $this->assertStringContainsString('values: {"frequency":"monthly","items":8}', $answers['event.bulletin']['monthly_items'], 'The send day does not apply, so no answer says it; storage keeps it.');
    $this->assertSame("Failed: The values were refused:\n" . '/contact/email: Email is required when Show on the press page is on.', $first($answers['event.press']['listed_without_email']));
    $this->assertStringStartsWith('Success: ', $answers['event.press']['unlisted_without_email']);
    $this->assertSame("Failed: The values were refused:\n" . '/reviewed_by: Reviewed by is required when Reviewed on is set.', $first($answers['event.press']['reviewed_by_nobody']));
    $this->assertSame("Failed: The values were refused:\n" . '/phone: Write the number in digits, such as +1 555 0100.', $first($answers['event.desk']['phone_with_dashes']));
    $this->assertStringContainsString("nothing was written.\n/on_sale/speaker: Speaker is locked and kept as stored; the value sent was not taken.\n", $answers['event.sales']['speaker_on_sale'], 'A note on a line of its own, after the message.');
    $this->assertStringContainsString('"speaker":false', $answers['event.sales']['speaker_on_sale']);
    $this->assertSame("Failed: The values were refused:\n" . '/limits/student/per_order: Per order does not apply when On sale: Student is off.', $first($answers['event.sales']['limit_off_sale']));
    $this->assertSame('Success: Check a program changes nothing: the values were accepted and prepared, and nothing was written.', $first($answers['event.program']['check']));
    $this->assertSame("Failed: The values were refused:\n" . '/sessions: This value should be between 1 and 30.', $first($answers['event.program']['check_too_many']));
    $this->assertSame("Failed: The values were refused:\n" . 'This may be seen and not changed, so nothing was written: The program is published in its workflow, so it is fixed.', $first($answers['event.program']['published_in_workflow']));
    $this->assertNull($this->container->get('state')->get('data_surface_examples_workflow.state'), 'The State the call was made in is put back.');
    $this->assertStringContainsString('"notes":"Hand out the maps."', $answers['event.doors']['left_out'], 'Left out: kept.');
    $this->assertStringContainsString('"notes":null', $answers['event.doors']['null'], 'Null: cleared.');
    $this->assertStringContainsString('"notes":"Doors close at ten."', $answers['event.doors']['value'], 'A value: replaced.');
    $this->assertSame("Failed: The values were refused:\n" . '/badges: Badges cannot be cleared: it always holds a value, printed (Printed at the door) by default. Leave it out to keep what it holds.', $first($answers['event.doors']['always_held']));
    $this->assertStringContainsString('values: {"component":"data_surface_examples:hero","props":{"title":"Doors open at half past six","subtitle":null,"align":"start","overlay":40,"full_width":false},"slots":{"actions":{"kind":"text","text":null}}}', $answers['event.spotlight']['hero'], 'The hero\'s props, read from its schema, its defaults filled in, and its slot holding text.');
    $this->assertSame("Failed: The values were refused:\n" . '/props/align: props.align belongs to the data_surface_examples:hero variant, but component chose data_surface_examples:card.', $first($answers['event.spotlight']['wrong_component']), 'Refused, naming the component the props belong to.');
    $this->assertSame("Failed: The values were refused:\n" . '/props/overlay: This value should be between 0 and 100.', $first($answers['event.spotlight']['out_of_bounds']));
    $this->assertSame("Failed: The values were refused:\n" . '/id: The machine name data_surface_examples_spotlight is already in use: choose another.', $first($answers['block.placement']['place']), 'Placed once, the Spotlight block refuses a second add under its name.');
    $this->assertSame("Failed: The values were refused:\n" . '/id: The machine name data_surface_examples_section is already in use: choose another.', $first($answers['block.placement']['section']), 'Placed once, the Tonight block refuses a second add under its name.');
    $this->assertStringContainsString('"settings":{"component":"data_surface_examples:hero","props":{"title":"Doors open at half past six","subtitle":null,"align":"start","overlay":40,"full_width":false},"slots":{"actions":{"kind":"text","text":null}}}', $answers['block.placement']['props'], 'The placed block named by its machine name, the hero read from its schema.');
    $this->assertSame("Failed: The values were refused:\n" . '/settings/slots/actions/component/component: The value you selected is not a valid choice. Choose data_surface_examples:call_to_action (Call to action).', $first($answers['block.placement']['wrong_slot']), 'A component the slot does not expect, refused naming the one it does.');
    $this->assertSame('Success: Dry run of Remove a placed block: the removal was rehearsed, and nothing was removed. The values it holds are answered.', $first($answers['block.placement']['delete']));
    $this->assertStringNotContainsString('mk-example-7f3a91', $answers['event.mailer']['pasted'], 'A pasted key is in no answer.');
    $this->assertStringContainsString('"source":"pasted","credential":[]', $answers['event.mailer']['pasted']);
    $this->assertStringContainsString('"source":"managed","credential":{"key_id":"mail_service"}', $answers['event.mailer']['managed']);
    $this->assertSame("Failed: The values were refused:\n" . '/credential/api_key: credential.api_key belongs to the pasted variant, but source chose managed.', $first($answers['event.mailer']['pasted_while_managed']), 'Refused, naming the source that chose the other variant.');
    $this->assertSame('Success: Configure the mailer: the stored values were read, and nothing was written.', $first($answers['event.mailer']['read']));
    $this->assertStringContainsString('"credential":{"key_id":"webhook_signing"}', str_replace('"third_party_settings":{"data_surface_examples_keys":', '"credential":', $answers['event.webhook']['managed']));
    $this->assertSame("Failed: The values were refused:\n" . '/api_key: API key does not apply when Managed key: Key is set.', $first($answers['event.webhook']['pasted_beside_managed']), 'Refused, naming the key it depends on.');
    $this->assertStringContainsString("\n  items: [{\"id\":\"riverside\",\"label\":\"Riverside Hall\",\"capacity\":520,\"weight\":0},{\"id\":\"library\",\"label\":\"Old Library\",\"capacity\":90,\"weight\":1}]\n  total: 3", $answers['event.venue']['list'], 'The listing answers its page and its total beside the result.');
    $this->assertSame('Success: Add a venue: the values were accepted and written.', $first($answers['event.venue']['create']), 'Town Hall written, so the next calls find it.');
    $this->assertSame('Success: Edit a venue: the values were accepted and written.', $first($answers['event.venue']['update']));
    $this->assertSame('Success: Dry run of Reorder the venues: the values were accepted and prepared, and nothing was written.', $first($answers['event.venue']['reorder']));
    $this->assertSame("Failed: The values were refused:\n/venues/0/id: No venue is named concert_hall.\n/venues/2/id: The library venue is named already, at position 1: a reorder names each venue once.", $answers['event.venue']['reorder_unknown'], 'Refused by the storage, at each place.');
    $this->assertStringContainsString('"capacity":350', $answers['event.venue']['delete_rehearsed'], 'The removal rehearsed answers what Town Hall holds, its capacity changed.');
    $this->assertSame('Success: Remove a venue: removed. The values it held are answered.', $answers['event.venue']['delete'], 'The last call removes Town Hall, so the three venues shipped are what is left.');
    $nodes = $this->container->get('class_resolver')->getInstanceFromDefinition(Sponsorship::class)->ids();
    $this->assertStringContainsString(sprintf('values: {"venue":"library","contact":1,"page":%d,"thing":{"entity_type":"node","id":"%d"}', $nodes['article'], $nodes['article']), $answers['event.sponsorship']['valid'], 'Each id as its key keeps it: the bare key\'s a string.');
    $this->assertSame("Failed: The values were refused:\n" . sprintf('/page: This entity (node: %d) cannot be referenced.', $nodes['page']), $first($answers['event.sponsorship']['wrong_bundle']), 'A page where an article is asked for.');
    $this->assertSame("Failed: The values were refused:\n" . sprintf('/page: This entity (node: %d) cannot be referenced.', $nodes['draft']), $first($answers['event.sponsorship']['not_referenceable']), 'The draft, for an account that may not see unpublished content.');
    $this->assertStringContainsString('/setting/entity_type: The entity type node cannot be referenced here.', $answers['event.sponsorship']['not_config']);
    $pages = $this->container->get('class_resolver')->getInstanceFromDefinition(Retitle::class)->ids();
    $this->assertStringContainsString(sprintf('titles: [{"node":%d,"old":"volunteer briefing for the night shift","new":"Volunteer Briefing for the Night Shift"},{"node":%d,"old":"WHERE TO PARK ON FESTIVAL DAYS","new":"Where to Park on Festival Days"}', $pages['one'], $pages['two']), $answers['ai.retitle']['dry_run'], 'A dry run answers each title it would write.');
    $this->assertSame("Failed: The values were refused:\n" . implode("\n", [
      "/items/0/node: You may not update volunteer briefing for the night shift: The 'edit any page content' permission is required.",
      "/items/1/node: You may not update WHERE TO PARK ON FESTIVAL DAYS: The 'edit any page content' permission is required.",
      "/items/2/node: You may not update menu of the food stalls: The 'edit any page content' permission is required.",
    ]), $answers['ai.retitle']['may_not_edit'], "Refused on each page by the entity gate, with core's reason.");
    $this->assertSame("Failed: The values were refused:\n/items/1/node: You may not edit the title of menu of the food stalls: The menu is at the printer, so its title is locked.", $answers['ai.retitle']['title_locked'], 'The first page passes; the third is refused by the field gate alone, which the entity gate allowed.');
    $this->assertSame('Success: Retitle nodes: the values were accepted and written.', $first($answers['ai.retitle']['retitle']));
    $this->assertStringContainsString('{"node":' . $pages['one'] . ',"old":"Volunteer Briefing for the Night Shift","new":"Volunteer Briefing for the Night Shift"}', $answers['ai.retitle']['read_back'], 'Read back, each title is the one written, and the rewriter writes it again.');
    $this->assertSame(Retitle::WRITTEN, ConceptCalls::INSTALLED);
    foreach (Retitle::WRITTEN as $name => $title) {
      $this->assertSame($title, Node::load($pages[$name])?->getTitle(), 'The last call put each title back as installed.');
    }
    $this->assertNull($this->container->get('state')->get(ConceptCalls::LOCKED), 'The lock the third call was made under is put back.');

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
