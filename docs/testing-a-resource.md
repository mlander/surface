# Testing a resource

Read this page once a resource of yours works, to add one kernel test
that keeps it working as the code changes. The test checks what the
introduction promises: every door reads the same sealed result and gives
the same answer to the same values ([Sealed](introduction/16-sealed.md),
[A value sent in](introduction/17-a-value-sent-in.md)).

The pattern is safe to adopt because of a few invariants: the schema a
caller reads is never stricter than what the pipeline accepts, every
door gives the same answer, a static refiner and a static access answer
are what they claim to be, and a resource changes in the open. This
module holds them for its own fixtures. `ResourceTestBase` holds them
for yours: one kernel test, three things to write.

```php
namespace Drupal\Tests\my_module\Kernel;

use Drupal\Tests\surface\Kernel\ResourceTestBase;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;

#[Group('my_module')]
#[RunTestsInSeparateProcesses]
class MyResourcesTest extends ResourceTestBase {

  protected static $modules = ['my_module'];

  protected static array $resources = ['my_module.settings'];

  protected static array $values = [
    'my_module.settings' => [
      'a region of another country' => [['country' => 'US', 'region' => 'CA-ON'], FALSE],
    ],
  ];

  protected static string $baselines = __DIR__ . '/../../fixtures/contracts';

}
```

It lives in your module's `tests/src/Kernel/` and runs with the rest of
your kernel tests. The base class is found the way core's test base
classes are: every module's `tests/src` is autoloaded under
`Drupal\Tests\<module>\` when tests run, so `surface` only has to
be on the site, as it is for your module to work at all. It installs
`surface` and `tool` (the tool door it compares with the HTTP door,
which is the base module's), which need the [Tool](installation.md#tool)
module; `$modules` adds yours. The class attributes go on your class:
core reads none from an abstract one.

## The three things

- **`$modules`**: your module and what it needs. The base class
  installs the user entity, the config of every module that declares a
  listed resource, and an administrator as the current account. Install
  anything else in your own `setUp()`, after `parent::setUp()`.
- **`$resources`**: the ids to hold. Each is one data set of every test,
  `testNeverStricterThanThePipeline with data set "my_module.settings"`.
- **`$values`** (optional): rows of values, by resource id. A row is
  `[$input, $accepts]`, what is sent and whether the pipeline accepts
  it; `NULL` takes the pipeline's verdict as given. A refused row may
  add whether the full schema, and then the schema for these answers,
  accept its document anyway (`TRUE` where the schema is wider on
  purpose); left out, the schema's own marks decide. Rows are what the
  generated documents cannot know: a value one key allows only for
  another's, a rule a refiner states.

## What each check holds

For each resource, each operation that needs nothing (a structure with
no operation as a host builds it):

| Test | Holds |
| --- | --- |
| `testEveryOperationBuilds` | Every operation builds, for no account and for an administrator, who sees every key it declares, and its full schema is emitted. One that needs a subject is built with none. |
| `testNeverStricterThanThePipeline` | The full schema and the schema for these answers accept every document the pipeline accepts, post-coercion, and refuse every one it refuses where the schema is exact (why: [decision](decisions.md#two-documents-never-stricter-than-the-runtime)). The documents are the declared defaults, each key sent `null`, each key sent a value of another type, and your rows. A refusal at a key the schema marks wider (`refine`, `live`, `checkedOnServer`, a `format`, a secret's required, a stale value) may be accepted. |
| `testFullSchemaReadsBackToItself` | The full schema, read back by `SchemaReader`, builds a structure whose full schema is byte-equal ([Reading a schema](reading-a-schema.md#the-round-trip)). A document of a kind that cannot round-trip (a coded refiner, an access answer, an alter's mount, keys from the site, a computed key, a constraint only the server checks, a port, a runtime refiner) is reported as skipped, naming the kind and why; any other difference fails, printing both documents. |
| `testStaticRefinersAgree` | Each conditional the full schema states for a static refiner is what a fresh refinement answers, and each declared rule's is, over sampled values; then again for the schema of the defaults and of each accepted row ([Enforcing the kind](refinement.md#enforcing-the-kind)). |
| `testStaticAccessAgrees` | What the full schema states about access (read only where locked or computed, marked `live` where set aside, no key it leaves out) holds for an administrator, an account with the operation's permission and one with none, as installed and with each of your accepted rows stored. |
| `testEveryDoorAgrees` | The same documents, sent dry through the pipeline, the derived tool, the HTTP API and the operation's form, give one accepted document, one refusal list and one set of notes, each path a JSON Pointer and each code the pipeline's (`DoorAgreementTrait`). Where the Tool API refuses a value at its own input, before the tool runs, it holds the shape alone: both refuse, and the Tool API names its keys by pointer. The form is drawn with what the pipeline accepts of a document and posted as a browser posts it, so it carries no value of another type, no null, no secret and nothing for a key it does not draw; it posts every key it draws, so its document may hold keys storage never held, at their defaults ([No door-specific context](resources.md#no-door-specific-context)). |
| `testContractBaseline` | Today's full schemas keep the promise the committed baseline in `$baselines` made: a breaking change fails unless its owner's version was raised or the baseline allows it with a reason ([The contract diff](evolution.md#the-contract-diff)). |

## The baselines

`$baselines` names the directory your baselines are kept in,
`tests/fixtures/contracts/` by convention, one `<id>.json` per resource
or structure. Empty, the baseline check is skipped, saying so. A
resource with no baseline yet fails, as the contract diff does: pin it
once, and after every change you accept, with the write switch, then
commit the files.

```
SURFACE_BASELINES_WRITE=1 vendor/bin/phpunit -c web/core \
  --filter testContractBaseline web/modules/custom/my_module/tests/src/Kernel/MyResourcesTest.php
```

A write keeps the allowances already recorded. To accept a breaking
change with a reason instead, use `drush surface:contract-diff
--module=my_module --write --allow="..."`, which writes the same files.
The baselines are yours: they record what your clients were promised,
and only your run reads or writes them.

## How long it takes

About 7 seconds per resource on this module's development site: the
first resource and the store location, 14 tests, take 14 seconds, and
the honeypot settings port, 7 tests, takes 8. Every test is one
process, as every kernel test here is.

## See also

- `Drupal\Tests\surface\Kernel\FirstResourceInvariantsTest`, this
  module's own use of it, for the [first](first-resource.md) and
  [second](second-resource.md) resources.
- [The site's catalog](catalog-command.md), to find the ids, the doors
  and what did not build.
- An adopter's tests hold the invariants
  (why: [decision](decisions.md#an-adopters-tests-hold-the-invariants)).
