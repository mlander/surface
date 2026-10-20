<?php

declare(strict_types=1);

namespace Drupal\Tests\data_surface\Functional;

use Drupal\data_surface_tool_test\Storage\CabinetStorage;
use Drupal\Tests\BrowserTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

/**
 * Tests the cabinet's forms and served contract when access locks.
 *
 * An auditor is shown the cabinet whole, every element disabled, with no
 * Save, and the served contract refuses a submit by name. A keeper who
 * sets no codes finds the code lock offered and fixed. Counting the
 * drawers, an operation that changes nothing, writes nothing.
 *
 * @see \Drupal\Tests\data_surface\Kernel\LockedAccessTest
 * @see docs/decisions.md#locked-at-the-top
 */
#[Group('data_surface')]
#[RunTestsInSeparateProcesses]
class LockedAccessFormTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'data_surface',
    'data_surface_react',
    'data_surface_tool_test',
  ];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * What the cabinet holds before each test.
   */
  protected const STORED = [
    'label' => 'Filing',
    'drawers' => 6,
    'lock' => 'code',
    'lock_settings' => ['digits' => 5],
  ];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    \Drupal::state()->set(CabinetStorage::KEY, self::STORED);
  }

  /**
   * Reads the cabinet as stored.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(): array {
    $this->container->get('state')->resetCache();
    return $this->container->get('state')->get(CabinetStorage::KEY);
  }

  /**
   * Sends a JSON POST as the logged-in account, with its CSRF token.
   *
   * @param string $path
   *   The path.
   * @param array $body
   *   The body.
   *
   * @return array
   *   The decoded 200 answer.
   */
  protected function post(string $path, array $body): array {
    $this->drupalGet('session/token');
    $response = $this->getHttpClient()->request('POST', $this->buildUrl($path), [
      'headers' => [
        'Content-Type' => 'application/json',
        'X-CSRF-Token' => $this->getSession()->getPage()->getContent(),
      ],
      'cookies' => $this->getSessionCookies(),
      'body' => (string) json_encode($body),
      'http_errors' => FALSE,
    ]);
    $this->assertSame(200, $response->getStatusCode(), (string) $response->getBody());
    return json_decode((string) $response->getBody(), TRUE);
  }

  /**
   * Tests an auditor sees the cabinet shown and fixed, with no Save.
   */
  public function testLockedWholeIsShownAndFixed(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['audit cabinet']));
    $this->drupalGet('surface-test/cabinet');
    $assert->statusCodeEquals(200);
    $assert->pageTextContains('This may be seen and not changed: The cabinet may be audited, not changed.');
    foreach (['surface[label]', 'surface[drawers]', 'surface[lock]', 'surface[lock_settings][digits]'] as $name) {
      $assert->fieldDisabled($name);
    }
    $assert->fieldValueEquals('surface[drawers]', '6');
    $assert->buttonNotExists('Save');

    // The served contract says it at the top, and refuses a submit.
    $this->drupalGet('surface-api/tool_test.cabinet/edit');
    $contract = json_decode($this->getSession()->getPage()->getContent(), TRUE);
    $this->assertTrue($contract['schema']['readOnly']);
    $this->assertTrue($contract['schema']['properties']['drawers']['readOnly']);
    $refused = $this->post('surface-api/tool_test.cabinet/edit/submit', ['values' => ['drawers' => 9]]);
    $this->assertFalse($refused['committed']);
    $this->assertStringContainsString('The cabinet may be audited, not changed.', $refused['violations'][0]['message']);
    $this->assertSame(self::STORED, $this->stored());

    // A keeper is not locked, and a keeper without codes is offered the
    // code lock fixed: its element disabled, the rest open.
    $this->drupalLogin($this->drupalCreateUser(['keep cabinet']));
    $this->drupalGet('surface-test/cabinet');
    $assert->buttonExists('Save');
    $assert->fieldEnabled('surface[drawers]');
    $assert->fieldEnabled('surface[lock]');
    $assert->optionExists('surface[lock]', 'code');
    $assert->fieldDisabled('surface[lock_settings][digits]');
    $this->submitForm(['surface[drawers]' => '3'], 'Save');
    $assert->pageTextContains('The changes have been saved.');
    $this->assertSame(['digits' => 5], $this->stored()['lock_settings']);
    $this->assertSame(3, $this->stored()['drawers']);
  }

  /**
   * Tests an operation that changes nothing writes nothing from its form.
   */
  public function testReadOnlyOperationForm(): void {
    $assert = $this->assertSession();
    $this->drupalLogin($this->drupalCreateUser(['keep cabinet']));
    $this->drupalGet('surface-test/cabinet/count');
    // Nothing is saved, so the button says what the operation does.
    $assert->buttonNotExists('Save');
    $this->submitForm(['surface[drawers]' => '9'], 'Count the drawers');
    $assert->pageTextContains('The values were accepted. Count the drawers changes nothing, so nothing was written.');
    $this->assertSame(self::STORED, $this->stored());
    $answer = $this->post('surface-api/tool_test.cabinet/count/submit', ['values' => ['drawers' => 9]]);
    $this->assertFalse($answer['committed']);
    $this->assertSame(self::STORED, $this->stored());
  }

}
