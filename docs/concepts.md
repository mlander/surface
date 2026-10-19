# Concepts

Every idea in the module, one line each: the question that makes you
need it, and the sentence that lets you skip it. The four marked **1**
are all [Your first resource](first-resource.md) uses; a working settings
page needs nothing else. Read a row only when its question is yours. A
time of day, a multi-select, a text area and the other keys a settings
page often asks for are [Common settings recipes](recipes.md), one
snippet each.

| Concept | You need it when you ask | Skip it when |
| --- | --- | --- |
| **1** Resource, `#[Resource]` | What thing do I want a form, a tool and a schema for? | Never: everything starts here. |
| **1** Operation, `#[Operation]` | How is it asked for, which permission gates it, and does a call change nothing (`readOnly`) or destroy what is there (`destructive`)? ([Resources](resources.md#operations-add-edit-and-the-rest)) | Never: a route and a tool are made from one. Leave `readOnly` and `destructive` out when a call does neither; whether it is safe to repeat is derived. |
| **1** Storage, `ConfigObjectStorage` | Where do its values live? | A settings page: subclass `ConfigObjectStorage`, name the config object, done. |
| **1** `defineInputs()` and `add()` | What keys does it take, of what type, allowing what (a constraint), defaulting to what? | Never. |
| Shape, `#[Shape]` | Is one value, such as an event licence, asked for at several keys with the same type, pattern and words? ([Declaring a resource](declaring-a-resource.md#named-shapes)) | The value is asked for at one key: write its constraints there. |
| Declared rule, `narrow()->when(Condition::...)` | Does one key narrow when another holds a value, by a condition a schema can state, such as no licence, no more than a hundred? ([Refinement](refinement.md#declaring-a-rule)) | Declare the rule when its condition and effect fit the vocabulary; write a refiner when the effect must be computed (the stewards for a capacity, `ceil(capacity / 50)`) or the condition is runtime. |
| Refiner, `#[RefinesInput]` | Do one key's allowed values depend on another key's value? ([Your second resource](second-resource.md)) | Every key stands alone. |
| A key that does not apply, `DoesNotApply::here()` | Does a key mean nothing for some values of another, as a send day for a monthly bulletin? ([Refinement](refinement.md#when-a-key-does-not-apply)) | Every key applies, whatever the others hold. |
| Runtime refiner, `runtime: TRUE` | Does a rule read site state or do a lookup no schema can state, such as uniqueness? | The rule depends only on the values it watches. |
| Identity and known keys | Is there more than one of it, each with a machine name or id? | It is a singleton, as a settings page is. |
| Operation context options (`creates`, `known`, `withConstraint()`, `withStarting()`) | Does an operation create, know part of the identity, or narrow by where it is asked? | It is one `new OperationContext('configure')`. |
| Access class, `access:` | Does who may change or see some keys depend on the account's permissions or roles (static: stated in the full schema and the tool listing for that account), or on the thing itself (runtime: marked there, settled once it is known)? ([Resources](resources.md#static-and-runtime-access)) | The operation's permission is the whole answer; narrowing a key's values by account is a runtime refiner. |
| Structure, `#[Structure]` | Is there a group of keys something else holds, or several resources share? | The keys are all one resource's own. |
| Attached child, `attach()` | Should a group of keys sit under one key, as a fieldset? | Every key is a top level key. |
| Mixin, `with()` | Are some keys shared members that are part of this thing, such as timestamps or an owner, which other resources have too? ([Resources](resources.md#mixins)) | They are a thing of their own (attach them) or this resource's alone. |
| Port, `reads()` and `attach(..., reads:)` | Does a structure need a value its container holds, such as the capacity it counts stewards for? ([Resources](resources.md#ports)) | The structure's keys depend only on each other. |
| Slot and variant, `attachBy()`, `#[Variant]` | Does one key's value choose which further keys apply? | No answer changes which keys there are. |
| Collection, `attachList()` | Does it hold a list of repeated groups of keys? | Nothing repeats. |
| Alter, `#[Alters]` | Am I adding keys or rules to a resource another module owns, or attaching a structure of mine to it? | You own it: change the class. |
| Mounted keys and their config schema | Does my alter's module store keys on someone else's config object, or may another module's alter store keys on mine? Both sides declare config schema: the owner of a simple config object declares `third_party_settings` up front, a sequence typed by the module key, before any alter exists, and the mounting module declares its part ([Storage](storage.md#keys-another-module-mounts), [a whole alter](resources.md#a-whole-alter)). | You write no alter, and no other module will mount keys on your config object. An owner that skips it leaves every later alter needing an edit of the owner's schema; an owner that is a config entity declares nothing, since core describes its `third_party_settings`. |
| `describe()` and `extendChoices()` | Must my alter reword or place an owner's key, or offer one more value on its list? | Your alter only adds keys of its own. |
| Secret, `DefinitionMetadata::setSecret()` | Is a value write-only, such as an API key? | Every value may be read back. |
| Examples, `DefinitionMetadata::setExamples()` | Should the form show what a valid value looks like? | The label says enough. |
| Settings layout, `SettingsLayoutInterface` | Is a value stored differently from how a person is asked for it? | It is stored as asked. |
| Outputs, `defineOutputs()` | Does running the host emit values a caller relies on? | Nothing is emitted: settings pages emit nothing. |
| Plugin host, `#[UsesStructure]` | Is it a block's, formatter's, condition's, action's or field type's settings? | No plugin holds it. |
| Cosmetic layer, `_data_surface_cosmetics` | Does the form need grouping, weights or a borrowed element, with nothing meaning something else? | The generated form is the form you want. |
| Panel, `_data_surface_panel` | Should the contract be shown beside the form? | Nobody needs to see it there. |
| Options resolver | Is the list of allowed values the site's (entity types, bundles), not a literal? | It is a literal list: `LabeledChoice`. |
| Widget plugin | Does a data type need an element no stock widget draws? | The stock widgets draw it. |
| Stale values | What happens to a stored value a refiner no longer allows? ([Value semantics](semantics.md#stale-values-the-third-state)) | No refiner narrows a key that has a stored value. |
| Tool and read tool | Should an agent, MCP or Drush call it? | You do not enable `data_surface_tool`; there is nothing to write either way. |
| Served contract and React | Will a client with no PHP render or validate it? ([The served contract](served-contract.md)) | The form and the tool are enough. |
| Pipeline, dry run | Do I submit values from my own code? ([The pipeline](pipeline.md)) | The form and the tool submit for you. |
| Data surface | Do I call the build or the pipeline myself? | You write resources: the build is the module's. |
| Child operations, `withChild()` | Should a parent's operation hand a child a context of its own, or invoke the child's operation? | No child has storage of its own. |
| Derived resource, `#[DerivesResources]` | Are the keys the site's (one per bundle, one per field), not the code's? | Your class says every key. |
| Derived variants | Can a slot's variants be read from a description that exists, such as config schema? | Each variant is a class. |
| Catalogue | What resources and operations does this site have? ([Catalogue](catalogue.md)) | You know your own. |
