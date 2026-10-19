<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests a key declared with a shape on the form: refused in its words.
 *
 * The compliance licence is a shape now, so example 3's form refuses a
 * malformed licence under the field in the shape's own message; the
 * depot uses one shape at two keys, refused in the same words at each
 * and in each key's own words for what it narrows.
 *
 * @see \Drupal\Tests\data_surface\Kernel\ShapesTest
 * @see docs/decisions.md#a-shape-is-a-named-definition
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class ShapeFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface_examples',
    'data_surface_examples_compliance',
    'data_surface_resource_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->drupalLogin($this->drupalCreateUser(['administer site configuration']));
  }

  /**
   * Reads a config object as stored.
   *
   * @param string $name
   *   The config name.
   *
   * @return array
   *   Its data.
   */
  protected function stored(string $name): array {
    $this->container->get('config.factory')->reset();
    return array_diff_key($this->config($name)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Tests the licence's pattern, and the depot's two keys, refuse as said.
   */
  public function testShapedKeysRefuseInTheShapesWords(): void {
    $assert = $this->assertSession();
    $licence = 'surface[third_party_settings][data_surface_examples_compliance][licence]';
    $this->drupalGet('surface-examples/3');
    $assert->statusCodeEquals(200);
    $assert->fieldExists($licence);
    $before = $this->stored('data_surface_examples.registration_step3');
    $this->submitForm([$licence => 'EV-2048'], 'Save');
    $assert->elementExists('css', 'input[name="' . $licence . '"][aria-invalid="true"]');
    $assert->pageTextContains('A licence number is four digits, such as 2048.');
    $assert->pageTextNotContains('The changes have been saved.');
    $this->assertSame($before, $this->stored('data_surface_examples.registration_step3'));
    $this->submitForm([$licence => '2048'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame('2048', $this->stored('data_surface_examples.registration_step3')['third_party_settings']['data_surface_examples_compliance']['licence']);

    $this->drupalGet('surface-test/depot');
    $assert->statusCodeEquals(200);
    $depot = $this->stored('data_surface_resource_test.depot');
    $assert->fieldValueEquals('surface[sku]', 'AB1234');
    $assert->fieldValueEquals('surface[spare]', 'CD12');
    $this->submitForm(['surface[sku]' => 'ab1234', 'surface[spare]' => 'XY99999'], 'Save');
    $assert->elementExists('css', 'input[name="surface[sku]"][aria-invalid="true"]');
    $assert->pageTextContains('A stock code is two capitals and digits, such as AB12.');
    // The spare code's own maximum, which its element carries as
    // `maxlength`, so a browser stops at six.
    $assert->elementExists('css', 'input[name="surface[spare]"][maxlength="6"][aria-invalid="true"]');
    $assert->elementExists('css', 'input[name="surface[sku]"][maxlength="12"]');
    $this->assertSame($depot, $this->stored('data_surface_resource_test.depot'));
    $this->submitForm(['surface[sku]' => 'XY99', 'surface[spare]' => 'ZZ1'], 'Save');
    $assert->pageTextNotContains('A stock code is two capitals and digits');
    $stored = $this->stored('data_surface_resource_test.depot');
    $this->assertSame(['XY99', 'ZZ1'], [$stored['sku'], $stored['spare']]);
  }

}
