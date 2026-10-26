# Data Surface examples: managed keys

Example 14 of [the examples](../data_surface_examples/README.md): a
module that manages keys, standing in for the Key module, so a resource
that takes a credential pasted as a secret can offer a key the site
manages instead. Nothing contributed is installed.

## What it shows

A list of named keys and nothing to hold their values:
`data_surface_examples_keys.keys` lists each key's machine name and
label, two shipped, and `Keys\ManagedKeys` reads the list with the
cacheability of the configuration object. A key that names a managed
key declares the `ManagedKey` constraint, which `ManagedKeyOptions`
reads as the list, so a form offers the keys and both documents state
them, and its validator refuses a name the site does not manage
([Declare the constraint, resolve the list](../../docs/options.md#declare-the-constraint-resolve-the-list)).

The examples use it twice. The mailer, `/surface-examples/14`, knows
this module: its provider lists a managed source beside the pasted one
while this module is on. The webhook, `/surface-examples/14/webhook`,
does not: `Alter\WebhookKeyAlter` mounts a managed key on it and rules
the pasted key out once one is chosen,
`narrow('api_key')->when(Condition::present('key_id'))->doesNotApply()`,
so the form draws no pasted box, a pasted key sent is refused naming the
managed key, and what is stored for the pasted key is kept. The alter
cannot hand the webhook's code the managed key's value, since the
webhook reads only its own keys; the mailer, which reads its own
variant, can ([decision](../../docs/decisions.md#an-extension-is-a-variant-the-site-adds-or-an-alter)).

## Try it

```bash
ddev drush pm:install data_surface_examples_keys
```

Reload `/surface-examples/14` and `/surface-examples/14/webhook`, with no
cache rebuild: the mailer offers two sources where it had one, drawn
fixed, and the webhook a managed key after its pasted one.
`ddev drush pm:uninstall data_surface_examples_keys` takes it away again,
with the managed key it stored on the webhook (`hook_uninstall()`): once
the module is gone no config schema describes it. The webhook's pasted
key is the webhook's, and stays.

## Gates

`Kernel\ExamplesExtensionTest` and `Functional\ExamplesExtensionFormTest`
with the module off and on; `Kernel\ExamplesConceptsToolTest` makes its
calls and holds the examples' README to the answers.
