# Surface API examples: workflow

Example 10 of [the examples](../surface_examples/README.md),
extended: another module answers access for a resource it does not own.

## What it shows

Two alters, each implementing `OperationAccessInterface` (`#[Alters]`
on the class makes it an alter's answer), and nothing else:
no form alter, no change to example 10's program or its fees.
`Alter\FeesWorkflowAlter` names the fees structure. An account without
"see event fees", this module's permission, does not see the fees at
all: a static answer, read from the account's permissions alone, so the
full schema and the tool listing leave the fees out for that account.
For any other account the fees are fixed once the program's workflow
state, which this module keeps in State, is published: a runtime
answer, marked in the full schema and settled at call time.
`Alter\ProgramWorkflowAlter` names the program, in the operation that
changes it: once the workflow state is published, the program is
locked whole, its headline and its fees with every other key, and a call
is refused by name.

Both are asked after the program's own answers (the operation's
permission, then the fees' access class), and the stricter answer
stands, so neither can open what the fees' own access locked
(why: [decision](../../docs/decisions.md#an-alter-may-answer-access-and-the-stricter-stands)).

## Try it

```bash
drush pm:install surface_examples_workflow
drush state:set surface_examples_workflow.state published
```

Reload `/surface-examples/10`: the program is shown and fixed, with no
Save. `drush state:delete surface_examples_workflow.state`, or
Reset to defaults on the examples' landing page, takes it back. The
panel above the form says what the module answers and makes its call.

## Gates

`Kernel\ExamplesWorkflowTest`; `Kernel\ExamplesConceptsToolTest` makes
its call and holds the examples' README to the answer.
