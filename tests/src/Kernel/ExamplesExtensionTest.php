<?php

declare(strict_types=1);

namespace Drupal\Tests\surface\Kernel;

use Drupal\Core\Form\FormState;
use Drupal\Core\Routing\RouteObjectInterface;
use Drupal\surface\Form\SurfaceOperationForm;
use Drupal\surface\Refinement\Rule;
use Drupal\surface\Resolve;
use Drupal\surface_examples\ConceptPanel;
use Drupal\surface_examples\Resource\MailerResource;
use Drupal\surface_examples\Resource\WebhookResource;
use Drupal\surface_examples\WebhookSigner;
use Drupal\surface\Tool\OperationInputs;
use Drupal\Tests\user\Traits\UserCreationTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests example 14, an optional extension, the keys module off and on.
 *
 * The mailer is the owner's way: its credential's sources are variants a
 * closed provider lists, the managed one only while the module is on. The
 * webhook is the alter's way: it declares a pasted key, and the module's
 * alter mounts a managed key and rules the pasted key out once one is
 * chosen. Both resources' full schemas are pinned whole in both states;
 * then what each state draws and refuses, a pasted key kept across a
 * switch and back, the read tool, and the README quoting what it shows.
 *
 * Decision: see
 * docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter.
 */
#[Group('surface')]
#[RunTestsInSeparateProcesses]
class ExamplesExtensionTest extends SurfaceKernelTestBase {

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
   * The module that manages keys.
   */
  protected const KEYS = ConceptPanel::KEYS;

  /**
   * The mailer's full schema with the module off: one source, a const.
   */
  protected const MAILER_OFF = <<<'JSON'
    {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "Configure the mailer",
        "description": "Sets who the event's mail is sent from, the mail service it is sent through, and the credential that service is called with: a key pasted here, or a key the site manages.",
        "x-surface": {
            "contract": 4,
            "operation": {
                "id": "configure",
                "label": "Configure the mailer",
                "description": "Sets who the event's mail is sent from, the mail service it is sent through, and the credential that service is called with: a key pasted here, or a key the site manages.",
                "kind": "update",
                "creates": false,
                "destructive": false,
                "idempotent": true
            },
            "version": 1
        },
        "type": "object",
        "properties": {
            "sender": {
                "title": "Sender",
                "type": "string",
                "format": "idn-email",
                "default": "tickets@example.com"
            },
            "endpoint": {
                "title": "Mail service",
                "type": "string",
                "format": "uri",
                "default": "https://mail.example.com/v1/send"
            },
            "source": {
                "title": "Credential source",
                "type": "string",
                "const": "pasted",
                "default": "pasted",
                "x-surface": {
                    "fixed": "Pasted here"
                }
            },
            "credential": {
                "title": "Credential",
                "anyOf": [
                    {
                        "$ref": "#/$defs/event.mailer.credential.pasted"
                    },
                    {
                        "type": "null"
                    }
                ],
                "x-surface": {
                    "dependsOn": [
                        "source"
                    ],
                    "by": "source",
                    "variants": {
                        "pasted": "#/$defs/event.mailer.credential.pasted"
                    }
                }
            }
        },
        "additionalProperties": false,
        "required": [
            "sender",
            "endpoint",
            "source"
        ],
        "allOf": [
            {
                "if": {
                    "properties": {
                        "source": {
                            "const": "pasted"
                        }
                    },
                    "required": [
                        "source"
                    ]
                },
                "then": {
                    "properties": {
                        "credential": {
                            "title": "Credential",
                            "$ref": "#/$defs/event.mailer.credential.pasted"
                        }
                    }
                }
            }
        ],
        "$defs": {
            "event.mailer.credential.pasted": {
                "title": "Pasted credential",
                "description": "The mailer credential pasted in, kept as a secret and never read back.",
                "type": "object",
                "properties": {
                    "api_key": {
                        "title": "API key",
                        "description": "The key the mail service issued, pasted here.",
                        "type": "string",
                        "writeOnly": true
                    }
                },
                "additionalProperties": false
            }
        }
    }
    JSON;

  /**
   * The mailer's full schema with the module on: two sources.
   */
  protected const MAILER_ON = <<<'JSON'
    {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "Configure the mailer",
        "description": "Sets who the event's mail is sent from, the mail service it is sent through, and the credential that service is called with: a key pasted here, or a key the site manages.",
        "x-surface": {
            "contract": 4,
            "operation": {
                "id": "configure",
                "label": "Configure the mailer",
                "description": "Sets who the event's mail is sent from, the mail service it is sent through, and the credential that service is called with: a key pasted here, or a key the site manages.",
                "kind": "update",
                "creates": false,
                "destructive": false,
                "idempotent": true
            },
            "version": 1
        },
        "type": "object",
        "properties": {
            "sender": {
                "title": "Sender",
                "type": "string",
                "format": "idn-email",
                "default": "tickets@example.com"
            },
            "endpoint": {
                "title": "Mail service",
                "type": "string",
                "format": "uri",
                "default": "https://mail.example.com/v1/send"
            },
            "source": {
                "title": "Credential source",
                "type": "string",
                "oneOf": [
                    {
                        "const": "pasted",
                        "title": "Pasted here"
                    },
                    {
                        "const": "managed",
                        "title": "A managed key"
                    }
                ],
                "default": "pasted"
            },
            "credential": {
                "title": "Credential",
                "anyOf": [
                    {
                        "$ref": "#/$defs/event.mailer.credential.pasted"
                    },
                    {
                        "$ref": "#/$defs/event.mailer.credential.managed"
                    },
                    {
                        "type": "null"
                    }
                ],
                "x-surface": {
                    "dependsOn": [
                        "source"
                    ],
                    "by": "source",
                    "variants": {
                        "pasted": "#/$defs/event.mailer.credential.pasted",
                        "managed": "#/$defs/event.mailer.credential.managed"
                    }
                }
            }
        },
        "additionalProperties": false,
        "required": [
            "sender",
            "endpoint",
            "source"
        ],
        "allOf": [
            {
                "if": {
                    "properties": {
                        "source": {
                            "const": "pasted"
                        }
                    },
                    "required": [
                        "source"
                    ]
                },
                "then": {
                    "properties": {
                        "credential": {
                            "title": "Credential",
                            "$ref": "#/$defs/event.mailer.credential.pasted"
                        }
                    }
                }
            },
            {
                "if": {
                    "properties": {
                        "source": {
                            "const": "managed"
                        }
                    },
                    "required": [
                        "source"
                    ]
                },
                "then": {
                    "properties": {
                        "credential": {
                            "title": "Credential",
                            "$ref": "#/$defs/event.mailer.credential.managed"
                        }
                    }
                }
            }
        ],
        "$defs": {
            "event.mailer.credential.pasted": {
                "title": "Pasted credential",
                "description": "The mailer credential pasted in, kept as a secret and never read back.",
                "type": "object",
                "properties": {
                    "api_key": {
                        "title": "API key",
                        "description": "The key the mail service issued, pasted here.",
                        "type": "string",
                        "writeOnly": true
                    }
                },
                "additionalProperties": false
            },
            "event.mailer.credential.managed": {
                "title": "Managed credential",
                "description": "The mailer credential as the name of a key the site manages, whose value is read where it is used.",
                "type": "object",
                "properties": {
                    "key_id": {
                        "title": "Key",
                        "description": "A key the site manages; its value is read where it is used, never stored here.",
                        "type": "string",
                        "oneOf": [
                            {
                                "const": "mail_service",
                                "title": "Mail service"
                            },
                            {
                                "const": "webhook_signing",
                                "title": "Webhook signing"
                            }
                        ],
                        "x-surface": {
                            "checkedOnServer": [
                                {
                                    "constraint": "ManagedKey",
                                    "message": "Managed key"
                                }
                            ]
                        }
                    }
                },
                "additionalProperties": false,
                "required": [
                    "key_id"
                ]
            }
        }
    }
    JSON;

  /**
   * The webhook's full schema with the module off: a pasted key alone.
   */
  protected const WEBHOOK_OFF = <<<'JSON'
    {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "Configure the sales webhook",
        "description": "Sets the address the event's sales are posted to, and the key each post is signed with.",
        "x-surface": {
            "contract": 4,
            "operation": {
                "id": "configure",
                "label": "Configure the sales webhook",
                "description": "Sets the address the event's sales are posted to, and the key each post is signed with.",
                "kind": "update",
                "creates": false,
                "destructive": false,
                "idempotent": true
            },
            "version": 1
        },
        "type": "object",
        "properties": {
            "endpoint": {
                "title": "Endpoint",
                "type": "string",
                "format": "uri",
                "default": "https://hooks.example.com/sales"
            },
            "api_key": {
                "title": "API key",
                "description": "The key each post is signed with, pasted here.",
                "type": "string",
                "writeOnly": true
            }
        },
        "additionalProperties": false,
        "required": [
            "endpoint"
        ]
    }
    JSON;

  /**
   * The webhook's full schema with the module on: the alter's key and rule.
   */
  protected const WEBHOOK_ON = <<<'JSON'
    {
        "$schema": "https://json-schema.org/draft/2020-12/schema",
        "title": "Configure the sales webhook",
        "description": "Sets the address the event's sales are posted to, and the key each post is signed with.",
        "x-surface": {
            "contract": 4,
            "operation": {
                "id": "configure",
                "label": "Configure the sales webhook",
                "description": "Sets the address the event's sales are posted to, and the key each post is signed with.",
                "kind": "update",
                "creates": false,
                "destructive": false,
                "idempotent": true
            },
            "version": 1,
            "rules": [
                0
            ]
        },
        "type": "object",
        "properties": {
            "endpoint": {
                "title": "Endpoint",
                "type": "string",
                "format": "uri",
                "default": "https://hooks.example.com/sales"
            },
            "api_key": {
                "title": "API key",
                "description": "The key each post is signed with, pasted here.",
                "type": "string",
                "writeOnly": true,
                "x-surface": {
                    "dependsOn": [
                        "third_party_settings.surface_examples_keys.key_id"
                    ]
                }
            },
            "third_party_settings": {
                "type": [
                    "object",
                    "null"
                ],
                "properties": {
                    "surface_examples_keys": {
                        "title": "Managed key",
                        "type": [
                            "object",
                            "null"
                        ],
                        "properties": {
                            "key_id": {
                                "title": "Key",
                                "description": "A key the site manages, used in place of a pasted one.",
                                "type": [
                                    "string",
                                    "null"
                                ],
                                "oneOf": [
                                    {
                                        "const": "mail_service",
                                        "title": "Mail service"
                                    },
                                    {
                                        "const": "webhook_signing",
                                        "title": "Webhook signing"
                                    },
                                    {
                                        "const": null
                                    }
                                ],
                                "x-surface": {
                                    "checkedOnServer": [
                                        {
                                            "constraint": "ManagedKey",
                                            "message": "Managed key"
                                        }
                                    ]
                                }
                            }
                        },
                        "additionalProperties": false,
                        "x-surface": {
                            "after": "api_key"
                        }
                    }
                },
                "additionalProperties": false,
                "x-surface": {
                    "group": true
                }
            }
        },
        "additionalProperties": false,
        "required": [
            "endpoint"
        ],
        "allOf": [
            {
                "if": {
                    "properties": {
                        "third_party_settings": {
                            "type": [
                                "object",
                                "null"
                            ],
                            "properties": {
                                "surface_examples_keys": {
                                    "type": [
                                        "object",
                                        "null"
                                    ],
                                    "properties": {
                                        "key_id": {
                                            "not": {
                                                "type": "null"
                                            }
                                        }
                                    },
                                    "required": [
                                        "key_id"
                                    ]
                                }
                            },
                            "required": [
                                "surface_examples_keys"
                            ]
                        }
                    },
                    "required": [
                        "third_party_settings"
                    ]
                },
                "then": {
                    "properties": {
                        "api_key": false
                    }
                }
            }
        ]
    }
    JSON;

  /**
   * The mailer's managed source, its key chosen.
   */
  protected const MANAGED = ['source' => 'managed', 'credential' => ['key_id' => 'mail_service']];

  /**
   * The webhook's managed key, at the keys module's mount.
   */
  protected const MOUNTED = ['third_party_settings' => ['surface_examples_keys' => ['key_id' => 'webhook_signing']]];

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->installEntitySchema('user');
    // Uninstalling a module clears its user data.
    $this->installSchema('user', ['users_data']);
    $this->installConfig(['surface_examples']);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Turns the module that manages keys on or off.
   *
   * @param bool $on
   *   TRUE to install it, FALSE to uninstall it.
   */
  protected function keys(bool $on): void {
    $installer = $this->container->get('module_installer');
    $on ? $installer->install([self::KEYS]) : $installer->uninstall([self::KEYS]);
    $this->setUpCurrentUser(admin: TRUE);
  }

  /**
   * Emits a resource's full schema for the current account.
   *
   * @param string $id
   *   The resource id.
   *
   * @return array
   *   The schema.
   */
  protected function full(string $id): array {
    $resources = $this->container->get('surface.resources');
    $registry = $this->container->get('surface.resource_registry');
    $operation = $registry->getOperation($id, 'configure');
    $schema = $this->container->get('surface.contract_emitter')
      ->emitFull($resources->buildStatic($id, $resources->operation($id, 'configure')), $id, 'configure', $operation->label, operation: $operation->facts())
      ->document['schema'];
    // As a client reads it.
    return json_decode((string) json_encode($schema), TRUE);
  }

  /**
   * Emits a resource's schema for the values stored now, with the widgets.
   *
   * @param string $id
   *   The resource id.
   *
   * @return array
   *   The schema.
   */
  protected function now(string $id): array {
    $resources = $this->container->get('surface.resources');
    $built = $resources->buildOperation($id, 'configure');
    $values = array_replace($built->getDefaultValues(), $resources->storage($id, $resources->operation($id, 'configure'), $built)->load($built));
    return $this->container->get('surface.contract_emitter')->emit($built, $values, $id, 'configure', NULL, TRUE)->document['schema'];
  }

  /**
   * Calls a resource's tool, or its read tool.
   *
   * @param string $tool
   *   The tool id, after .
   * @param array|null $values
   *   The values sent, or NULL for the read tool.
   * @param bool $dry_run
   *   Whether to rehearse it.
   *
   * @return array{0: bool, 1: array}
   *   Whether it succeeded, and its outputs.
   */
  protected function call(string $tool, ?array $values = NULL, bool $dry_run = FALSE): array {
    $instance = $this->container->get('plugin.manager.tool')->createInstance('surface:' . $tool);
    if ($values !== NULL) {
      $instance->setInputValue(OperationInputs::VALUES, $values);
      $instance->setInputValue(OperationInputs::DRY_RUN, $dry_run);
    }
    $instance->execute();
    $result = $instance->getResult();
    return [$result->isSuccess(), $result->getContextValues() + ['message' => (string) $result->getMessage()]];
  }

  /**
   * Gives the message of each violation an answer names, by pointer.
   *
   * @param array $outputs
   *   The tool's outputs.
   *
   * @return array<string, string>
   *   Each message, by its JSON Pointer.
   */
  protected static function refusals(array $outputs): array {
    $refusals = [];
    foreach ($outputs[OperationInputs::VIOLATIONS] ?? [] as $violation) {
      $refusals[$violation['path']] = (string) $violation['message'];
    }
    return $refusals;
  }

  /**
   * Reads a config object as stored, less what core adds.
   *
   * @param string $name
   *   The config object.
   *
   * @return array
   *   The stored values.
   */
  protected function stored(string $name): array {
    $this->container->get('config.factory')->reset($name);
    return array_diff_key($this->config($name)->getRawData(), ['_core' => TRUE]);
  }

  /**
   * Builds the generic form at one of the examples' routes.
   *
   * @param string $route_name
   *   The route.
   *
   * @return array
   *   The data surface's container in the form.
   */
  protected function formAt(string $route_name): array {
    $route = $this->container->get('router.route_provider')->getRouteByName($route_name);
    $stack = $this->container->get('request_stack');
    $request = Request::create($route->getPath());
    $request->setSession($stack->getSession());
    $request->attributes->set(RouteObjectInterface::ROUTE_NAME, $route_name);
    $request->attributes->set(RouteObjectInterface::ROUTE_OBJECT, $route);
    $stack->push($request);
    $form_state = new FormState();
    return $this->container->get('form_builder')->buildForm(SurfaceOperationForm::class, $form_state)[SurfaceOperationForm::SURFACE_KEY];
  }

  /**
   * Tests both resources' full schemas, whole, with the module off, then on.
   *
   * The resources are built once before the module is installed, and
   * built again after with no cache cleared: the provider's list and the
   * alter are there at the next build.
   */
  public function testBothDocumentsWithTheModuleOffThenOn(): void {
    $this->assertSame(json_decode(self::MAILER_OFF, TRUE), $this->full('event.mailer'));
    $this->assertSame(json_decode(self::WEBHOOK_OFF, TRUE), $this->full('event.webhook'));
    [$ok, $outputs] = $this->call('event.mailer:configure', self::MANAGED, TRUE);
    $this->assertFalse($ok, 'With the module off, the managed source is no value the source takes.');
    $this->assertArrayHasKey('/source', self::refusals($outputs));

    $this->keys(TRUE);
    $this->assertSame(json_decode(self::MAILER_ON, TRUE), $this->full('event.mailer'));
    $this->assertSame(json_decode(self::WEBHOOK_ON, TRUE), $this->full('event.webhook'));
    [$ok] = $this->call('event.mailer:configure', self::MANAGED, TRUE);
    $this->assertTrue($ok, 'With the module on, the tool takes the managed source.');

    $this->keys(FALSE);
    $this->assertSame(json_decode(self::MAILER_OFF, TRUE), $this->full('event.mailer'), 'Off again, one source again.');
    $this->assertSame(json_decode(self::WEBHOOK_OFF, TRUE), $this->full('event.webhook'));
  }

  /**
   * Tests one source is drawn as that source and stated as const.
   */
  public function testOneSourceIsFixedValue(): void {
    $source = $this->now('event.mailer')['properties']['source'];
    $this->assertSame('pasted', $source['const']);
    $this->assertArrayNotHasKey('oneOf', $source);
    $this->assertSame('fixed', $source['x-surface']['widget']);
    $this->assertSame('Pasted here', $source['x-surface']['fixed']);
    $this->assertArrayNotHasKey('emptyOption', $source['x-surface']);
    $element = $this->formAt('surface_examples.step14')['source'];
    $this->assertSame('item', $element['#type']);
    $this->assertSame('Pasted here', $element['#plain_text']);
    $this->assertSame('pasted', $element['#value']);
    $this->assertFalse($element['#required']);
    $this->assertArrayNotHasKey('#ajax', $element, 'A value drawn fixed triggers no rebuild.');

    $this->keys(TRUE);
    $source = $this->now('event.mailer')['properties']['source'];
    $this->assertArrayNotHasKey('const', $source);
    $this->assertSame(['pasted', 'managed'], array_column($source['oneOf'], 'const'));
    $this->assertSame('select', $source['x-surface']['widget']);
    $element = $this->formAt('surface_examples.step14')['source'];
    $this->assertSame('select', $element['#type']);
    $this->assertSame(['pasted' => 'Pasted here', 'managed' => 'A managed key'], array_map('strval', $element['#options']));
    $this->assertArrayHasKey('#ajax', $element);
  }

  /**
   * Tests a pasted key survives a switch to the managed key and back.
   *
   * Kept as stored beside the managed key's name, read by no door, and
   * taken up again when the pasted source is chosen back with nothing
   * sent for it; each source's stored value holds to its config schema.
   */
  public function testPastedKeySurvivesSwitching(): void {
    $this->keys(TRUE);
    [$ok] = $this->call('event.mailer:configure', ['source' => 'pasted', 'credential' => ['api_key' => 'mk-kept-1']]);
    $this->assertTrue($ok);
    $this->assertSame(['api_key' => 'mk-kept-1'], $this->stored('surface_examples.mailer')['credential']);

    [$ok, $outputs] = $this->call('event.mailer:configure', self::MANAGED);
    $this->assertTrue($ok);
    $this->assertSame(['key_id' => 'mail_service'], $outputs[OperationInputs::VALUES]['credential'], 'The answer holds the managed key alone.');
    $this->assertSame(['key_id' => 'mail_service', 'api_key' => 'mk-kept-1'], $this->stored('surface_examples.mailer')['credential'], 'The pasted key is kept beside it.');
    $this->assertConfigHoldsToItsSchema('surface_examples.mailer');
    [, $read] = $this->call('event.mailer:configure:read');
    $this->assertSame('managed', $read[OperationInputs::VALUES]['source']);
    $this->assertSame(['key_id' => 'mail_service'], (array) $read[OperationInputs::VALUES]['credential'], 'The read names the managed key, and leaves the pasted one out.');
    $this->assertStringNotContainsString('mk-kept-1', (string) json_encode($this->now('event.mailer')));

    // A save that changes nothing else keeps it too.
    [$ok] = $this->call('event.mailer:configure', ['sender' => 'box@example.com']);
    $this->assertTrue($ok);
    $this->assertSame('mk-kept-1', $this->stored('surface_examples.mailer')['credential']['api_key']);

    [$ok, $outputs] = $this->call('event.mailer:configure', ['source' => 'pasted']);
    $this->assertTrue($ok, 'Chosen back with nothing sent for it, the pasted key is the one kept.');
    $this->assertSame(['api_key' => 'mk-kept-1'], $this->stored('surface_examples.mailer')['credential'], 'The managed key, a plain value, goes; the pasted key is the source\'s again.');
    $this->assertConfigHoldsToItsSchema('surface_examples.mailer');
    [, $read] = $this->call('event.mailer:configure:read');
    $this->assertSame([], (array) $read[OperationInputs::VALUES]['credential']);
  }

  /**
   * Tests the refusals both ways name what they depend on.
   */
  public function testTheRefusals(): void {
    $this->keys(TRUE);
    $both = self::MANAGED;
    $both['credential']['api_key'] = 'mk-1';
    [$ok, $outputs] = $this->call('event.mailer:configure', $both, TRUE);
    $this->assertFalse($ok);
    $this->assertSame(['/credential/api_key' => 'credential.api_key belongs to the pasted variant, but source chose managed.'], self::refusals($outputs));
    $vault = self::MANAGED;
    $vault['credential']['key_id'] = 'vault';
    [$ok, $outputs] = $this->call('event.mailer:configure', $vault, TRUE);
    $this->assertFalse($ok);
    $this->assertArrayHasKey('/credential/key_id', self::refusals($outputs));
    [$ok, $outputs] = $this->call('event.mailer:configure', ['source' => 'managed'], TRUE);
    $this->assertFalse($ok);
    $this->assertSame(['/credential/key_id' => 'Key is required.'], self::refusals($outputs));
    [$ok, $outputs] = $this->call('event.webhook:configure', ['api_key' => 'wh-1'] + self::MOUNTED, TRUE);
    $this->assertFalse($ok);
    $this->assertSame(['/api_key' => 'API key does not apply when Managed key: Key is set.'], self::refusals($outputs));
    [$ok, $outputs] = $this->call('event.webhook:configure', ['third_party_settings' => ['surface_examples_keys' => ['key_id' => 'vault']]], TRUE);
    $this->assertFalse($ok);
    $this->assertSame(["The site manages no key named 'vault'."], array_values(self::refusals($outputs)));
  }

  /**
   * Tests the webhook's pasted key, ruled out once a key is chosen, and back.
   */
  public function testTheWebhooksPastedKeyIsRuledOutThenBack(): void {
    [$ok] = $this->call('event.webhook:configure', ['api_key' => 'wh-kept-1']);
    $this->assertTrue($ok);
    $this->keys(TRUE);
    $this->assertArrayHasKey('api_key', $this->now('event.webhook')['properties'], 'No key chosen yet: the pasted key applies.');
    [$ok] = $this->call('event.webhook:configure', ['third_party_settings' => ['surface_examples_keys' => ['key_id' => 'webhook_signing']]]);
    $this->assertTrue($ok);
    $this->assertArrayNotHasKey('api_key', $this->now('event.webhook')['properties'], 'A key chosen: the pasted key does not apply.');
    $this->assertNotSame('password', $this->formAt('surface_examples.step14_webhook')['api_key']['#type'] ?? NULL, 'Nor is it drawn: its wrapper alone.');
    [$ok] = $this->call('event.webhook:configure', ['api_key' => ''], TRUE);
    $this->assertTrue($ok, 'Nothing sent for it is still nothing.');
    $this->assertSame('wh-kept-1', $this->stored('surface_examples.webhook')['api_key'], 'What it holds is kept.');
    [, $read] = $this->call('event.webhook:configure:read');
    $this->assertSame(['surface_examples_keys' => ['key_id' => 'webhook_signing']], json_decode((string) json_encode($read[OperationInputs::VALUES]['third_party_settings']), TRUE));
    $this->assertArrayNotHasKey('api_key', (array) $read[OperationInputs::VALUES]);

    $this->keys(FALSE);
    $this->assertArrayNotHasKey('third_party_settings', $this->stored('surface_examples.webhook'), 'The module took the managed key it stored away with it.');
    $this->assertSame('password', $this->formAt('surface_examples.step14_webhook')['api_key']['#type'], 'Off: the pasted key is back.');
    [$ok, $outputs] = $this->call('event.webhook:configure', ['endpoint' => 'https://hooks.example.com/other']);
    $this->assertTrue($ok, 'A save with the module off, its stored key under the webhook: ' . json_encode($outputs));
    $this->assertSame('wh-kept-1', $this->stored('surface_examples.webhook')['api_key'], 'With its value.');
  }

  /**
   * Tests the code that uses the webhook signs with the key that stands.
   *
   * The pasted key with the module off; the managed key's value once one is
   * chosen, the pasted key kept in config as it was and the managed value
   * stored nowhere; the pasted key again once none is chosen; and nothing
   * for a name the site no longer manages, which the pasted key does not
   * stand in for.
   */
  public function testTheSignerUsesTheKeyThatStandsAtUse(): void {
    $payload = ['sold' => 3, 'event' => 'spring'];
    $signed = static fn (string $key): string => hash_hmac('sha256', (string) json_encode($payload), $key);
    $signer = fn (): WebhookSigner => $this->container->get('surface_examples.webhook_signer');
    [$ok] = $this->call('event.webhook:configure', ['api_key' => 'wh-pasted-1']);
    $this->assertTrue($ok);
    $this->assertSame($signed('wh-pasted-1'), $signer()->sign($payload), 'Off: the pasted key.');
    $this->assertSame('https://hooks.example.com/sales', $signer()->endpoint());

    $this->keys(TRUE);
    $this->assertSame($signed('wh-pasted-1'), $signer()->sign($payload), 'On, with no key chosen: the pasted key still.');
    $built = $this->container->get('surface.resources')->buildOperation('event.webhook', 'configure');
    $this->assertEquals(['key_id' => 'third_party_settings.' . self::KEYS . '.key_id'], Resolve::of($built->getDefinition('api_key'))?->from, 'The alter\'s own key, read from its mount.');
    [$ok] = $this->call('event.webhook:configure', self::MOUNTED);
    $this->assertTrue($ok);
    $this->assertSame($signed('wh-managed-9a1f2c'), $signer()->sign($payload), 'A key chosen: its value.');
    $stored = $this->stored('surface_examples.webhook');
    $this->assertSame('wh-pasted-1', $stored['api_key'], 'The pasted key is kept as stored.');
    $this->assertSame(self::MOUNTED['third_party_settings'], $stored['third_party_settings']);
    $this->assertStringNotContainsString('wh-managed-9a1f2c', (string) json_encode($stored), 'The managed key\'s value is stored nowhere on the webhook.');
    [, $read] = $this->call('event.webhook:configure:read');
    $this->assertStringNotContainsString('wh-', (string) json_encode($read), 'The read names neither key.');
    $this->assertStringNotContainsString('wh-', (string) json_encode($this->now('event.webhook')));

    [$ok] = $this->call('event.webhook:configure', ['third_party_settings' => [self::KEYS => ['key_id' => NULL]]]);
    $this->assertTrue($ok);
    $this->assertSame($signed('wh-pasted-1'), $signer()->sign($payload), 'None chosen again: the pasted key.');

    [$ok] = $this->call('event.webhook:configure', self::MOUNTED);
    $this->assertTrue($ok);
    $keys = $this->config('surface_examples_keys.keys');
    $keys->set('keys', array_values(array_filter($keys->get('keys'), static fn (array $key): bool => $key['id'] !== 'webhook_signing')))->save();
    $this->assertNull($this->container->get('surface.resources')->resolved('event.webhook')['api_key'], 'A name the site no longer manages resolves to nothing.');
    $this->assertSame('wh-pasted-1', $this->stored('surface_examples.webhook')['api_key'], 'What is stored for the pasted key is kept, and not used.');
    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessage('The webhook has no key to sign a post with.');
    $signer()->sign($payload);
  }

  /**
   * Tests the README quotes the documents, the use and the lock not shipped.
   *
   * The lock is quoted, never run: it is no effect a declared rule has. A
   * rule that gains one fails here, so the alternative is weighed again.
   */
  public function testTheReadmeQuotesWhatThePageShows(): void {
    $readme = (string) file_get_contents(dirname(__DIR__, 3) . '/modules/surface_examples/README.md');
    $flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION;
    $keys = array_flip(['source', 'credential']);
    $excerpt = static fn (array $full): string => (string) json_encode(array_intersect_key($full['properties'], $keys), $flags);
    $this->assertStringContainsString("```json\n" . $excerpt(json_decode(self::MAILER_OFF, TRUE)) . "\n```", $readme);
    $this->assertStringContainsString("```json\n" . $excerpt(json_decode(self::MAILER_ON, TRUE)) . "\n```", $readme);
    $this->assertStringContainsString("```json\n" . json_encode(self::rules(json_decode(self::WEBHOOK_ON, TRUE)), $flags) . "\n```", $readme);
    $this->assertStringContainsString("```php\n" . ConceptPanel::LOCKING . "\n```", $readme);
    $this->assertFalse((new \ReflectionClass(Rule::class))->hasMethod('lock'), 'A rule may lock now: weigh the alternative the page quotes again.');
    $use = (string) preg_replace('#<code>(.*?)</code>#', '`$1`', htmlspecialchars_decode((string) $this->container->get('surface_examples.concept_panel')->atUse(), ENT_QUOTES));
    $this->assertStringContainsString($use, (string) preg_replace('/\s+/', ' ', $readme));
    $this->assertSame(MailerResource::class, $this->container->get('surface.resource_registry')->getDefinition('event.mailer')->class);
    $this->assertSame(WebhookResource::class, $this->container->get('surface.resource_registry')->getDefinition('event.webhook')->class);
  }

  /**
   * Gives the conditionals a frame lists under `x-surface.rules`.
   *
   * @param array $schema
   *   A full schema.
   *
   * @return array
   *   Its declared rules' conditionals, in order.
   */
  protected static function rules(array $schema): array {
    return array_values(array_intersect_key($schema['allOf'] ?? [], array_flip($schema['x-surface']['rules'] ?? [])));
  }

  /**
   * Asserts a config object, as stored, holds to its schema.
   *
   * @param string $name
   *   The config object.
   */
  protected function assertConfigHoldsToItsSchema(string $name): void {
    $messages = [];
    foreach ($this->container->get('config.typed')->createFromNameAndData($name, $this->config($name)->getRawData())->validate() as $violation) {
      $messages[] = $violation->getPropertyPath() . ': ' . strip_tags((string) $violation->getMessage());
    }
    $this->assertSame([], $messages, $name);
  }

}
