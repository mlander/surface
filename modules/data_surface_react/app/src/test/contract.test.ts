import type { Extension, Schema } from '../contract';
import { assertContract, assertSchema, CONTRACT, ContractRefused, defName, inlineSchema, isPart, resolveSchema, resolveVariants, restored, watchedPaths, withoutComputed, withoutSuggested, isSuggested, narrow, narrowingStatus } from '../contract';
import example3 from './fixtures/example3.json';
import license from './fixtures/refine-example3-license.json';
import journal from './fixtures/refine-journal.json';

// The resolver, over schemas shaped as the emitter writes them: each
// child a def under `$defs`, each site a reference beside its own
// keywords, a site that says more composed with its narrowing.

const contact: Schema = {
  type: 'object',
  properties: {
    email: { title: 'Email', type: 'string', format: 'idn-email', 'x-surface': { widget: 'email' } },
    phone: { title: 'Phone', type: ['string', 'null'], 'x-surface': { widget: 'text' } },
  },
  additionalProperties: false,
  required: ['email'],
};

const fieldset = { widget: 'fieldset' as const };

describe('resolving a reference', () => {
  it('replaces a reference with its def, the keywords beside it kept in their places', () => {
    const root: Schema = {
      type: 'object',
      properties: { contact: { title: 'Contact', $ref: '#/$defs/registration.contact', 'x-surface': fieldset } },
      $defs: { 'registration.contact': contact },
    };
    const resolved = resolveSchema(root.properties!.contact, root);
    expect(Object.keys(resolved)).toEqual(['title', 'type', 'properties', 'additionalProperties', 'required', 'x-surface']);
    expect(resolved.title).toBe('Contact');
    expect(resolved.required).toEqual(['email']);
    expect(resolved['x-surface']?.widget).toBe('fieldset');
    expect(resolved).not.toHaveProperty('$ref');
  });

  it('applies an allOf narrowing: its value for each keyword, properties one by one, required as a union', () => {
    const bin: Schema = {
      type: 'object',
      properties: {
        bin: { title: 'Bin', type: 'string', 'x-surface': { widget: 'text' } },
        size: { title: 'Size', type: ['integer', 'null'], minimum: 1, maximum: 10, 'x-surface': { widget: 'number' } },
      },
      additionalProperties: false,
      required: ['bin'],
    };
    const site = {
      title: 'Left bin',
      allOf: [
        { $ref: '#/$defs/resource_test.cupboard.bin' },
        {
          properties: {
            bin: { readOnly: true, const: 'left', 'x-surface': { widget: 'text', refined: true } },
            size: { maximum: 4 },
          },
          required: ['size'],
        },
      ],
      'x-surface': fieldset,
    } as unknown as Schema;
    const root: Schema = { type: 'object', properties: { left: site }, $defs: { 'resource_test.cupboard.bin': bin } };
    const resolved = resolveSchema(site, root);
    expect(resolved).not.toHaveProperty('allOf');
    expect(resolved.title).toBe('Left bin');
    expect(resolved.properties?.bin).toMatchObject({ title: 'Bin', type: 'string', readOnly: true, const: 'left' });
    expect(resolved.properties?.bin['x-surface']?.refined).toBe(true);
    expect(resolved.properties?.size).toMatchObject({ title: 'Size', minimum: 1, maximum: 4 });
    expect(resolved.required).toEqual(['bin', 'size']);
    // The def itself is untouched.
    expect(bin.properties?.size.maximum).toBe(10);
    expect(bin.properties?.bin.readOnly).toBeUndefined();
    expect(bin.properties?.bin['x-surface']?.refined).toBeUndefined();
  });

  it('resolves a def inside a def, and two sites sharing one def each with their own label', () => {
    const shelf: Schema = {
      type: 'object',
      properties: { label: { title: 'Label', $ref: '#/$defs/pantry.label', 'x-surface': fieldset } },
      additionalProperties: false,
    };
    const label: Schema = { type: 'object', properties: { text: { title: 'Text', type: 'string' } }, additionalProperties: false };
    const root: Schema = {
      type: 'object',
      properties: {
        upper: { title: 'Upper shelf', $ref: '#/$defs/pantry.shelf', 'x-surface': fieldset },
        lower: { title: 'Lower shelf', $ref: '#/$defs/pantry.shelf', 'x-surface': fieldset },
      },
      $defs: { 'pantry.shelf': shelf, 'pantry.label': label },
    };
    const inline = inlineSchema(root);
    expect(inline).not.toHaveProperty('$defs');
    expect(inline.properties?.upper.title).toBe('Upper shelf');
    expect(inline.properties?.lower.title).toBe('Lower shelf');
    for (const key of ['upper', 'lower']) {
      expect(inline.properties?.[key].properties?.label).toMatchObject({ title: 'Label', type: 'object', properties: { text: { title: 'Text' } } });
      expect(inline.properties?.[key].properties?.label).not.toHaveProperty('$ref');
    }
    // A reference to a def that holds no shape of its own is left as it is.
    expect(resolveSchema({ $ref: '#/somewhere/else' }, root)).toEqual({ $ref: '#/somewhere/else' });
  });

  it('keeps a reference back into the def being read, so a recursive schema ends', () => {
    const root: Schema = {
      type: 'object',
      properties: { first: { title: 'First', $ref: '#/$defs/node' } },
      $defs: { node: { type: 'object', properties: { name: { type: 'string' }, next: { title: 'Next', $ref: '#/$defs/node' } } } },
    };
    const inline = inlineSchema(root);
    expect(inline.properties?.first.properties?.name).toEqual({ type: 'string' });
    expect(inline.properties?.first.properties?.next).toEqual({ title: 'Next', $ref: '#/$defs/node' });
  });

  it('reads a key escaped as JSON Pointer and percent-encoded in the fragment', () => {
    expect(defName('#/$defs/registration.contact')).toBe('registration.contact');
    expect(defName('#/$defs/a~1b~0c')).toBe('a/b~c');
    expect(defName('#/$defs/with%20space')).toBe('with space');
    expect(defName('#/definitions/x')).toBeNull();
  });
});

describe('example 3 as the emitter serves it', () => {
  const schema = example3.schema as unknown as Schema;

  it('defines the contact and both tickets once, and refers to them', () => {
    // Example 4's module is on: its structure and its named shape are defs too.
    expect(Object.keys(schema.$defs ?? {})).toEqual([
      'registration.step3.ticket.free',
      'registration.step3.ticket.paid',
      'registration.contact',
      'registration.compliance',
      'event.license',
    ]);
    expect(schema.properties?.contact.$ref).toBe('#/$defs/registration.contact');
    expect(schema.properties?.contact).not.toHaveProperty('properties');
  });

  it('reads inline as one schema: the contact\'s keys and each ticket\'s through the variants', () => {
    const inline = inlineSchema(schema);
    expect(Object.keys(inline.properties?.contact.properties ?? {})).toEqual(['email', 'phone']);
    expect(inline.properties?.contact.title).toBe('Contact');
    expect(inline.properties?.contact.required).toEqual(['email']);
    expect(Object.keys(resolveVariants(inline, 'ticket', { pricing: 'free' })?.properties ?? {})).toEqual(['note']);
    const paid = resolveVariants(inline, 'ticket', { pricing: 'paid' });
    expect(paid?.title).toBe('Ticket');
    // Nothing below a conditional carries `x-surface`: the key with
    // variants says which is chosen.
    expect(paid?.['x-surface']).toBeUndefined();
    expect(inline.properties?.ticket['x-surface']).toMatchObject({ widget: 'variants', by: 'pricing', chosen: 'free' });
    expect(paid?.required).toEqual(['price', 'currency']);
  });
});

describe('a collection', () => {
  const shelf: Schema = {
    type: 'object',
    properties: {
      unit: { title: 'Unit', type: 'string', 'x-surface': { widget: 'select' } },
      height: { title: 'Height', type: 'integer', 'x-surface': { widget: 'number', dependsOn: ['unit'] } },
    },
    additionalProperties: false,
  };
  const root: Schema = {
    type: 'object',
    properties: {
      shelves: {
        title: 'Shelves',
        type: ['array', 'null'],
        items: { $ref: '#/$defs/resource_test.pantry.shelf' },
        maxItems: 3,
        'x-surface': { widget: 'list', collection: true },
      },
    },
    $defs: { 'resource_test.pantry.shelf': shelf },
  };

  it('dereferences its items', () => {
    const inline = inlineSchema(root);
    expect(inline.properties!.shelves.items).toEqual(shelf);
    expect(inline.properties!.shelves.maxItems).toBe(3);
  });

  it('watches what refines inside each item, under its delta', () => {
    const watched = watchedPaths(inlineSchema(root), { shelves: [{ unit: 'cm' }, { unit: 'in' }] });
    expect([...watched].sort()).toEqual(['shelves.0.unit', 'shelves.1.unit']);
  });
});

describe('a computed key', () => {
  // Example 15's venue, abbreviated: the slug is computed from the name.
  const venue: Schema = {
    type: 'object',
    properties: {
      label: { title: 'Name', type: 'string', 'x-surface': { widget: 'text' } },
      slug: {
        title: 'Slug',
        type: ['string', 'null'],
        readOnly: true,
        'x-surface': { widget: 'text', computed: { from: ['label'], when: 'create' } },
      },
    },
    additionalProperties: false,
    required: ['label'],
  };

  it('watches what it is computed from, which the server narrows', () => {
    expect([...watchedPaths(venue, {})]).toEqual(['label']);
  });

  it('sends a change of what it is computed from to the server', () => {
    const narrowed = narrow({ full: venue, previous: { schema: venue, values: {}, stale: [] }, stored: {}, values: { label: 'Town Hall' }, stale: [], changed: 'label' });
    expect(narrowed).toEqual({ local: false, key: 'slug', marker: 'computed' });
    expect(narrowingStatus(narrowed)).toBe('Asked the server: slug is computed on the server');
  });

  it('is never sent', () => {
    expect(withoutComputed(venue, { label: 'Town Hall', slug: 'town' })).toEqual({ label: 'Town Hall' });
  });
});

describe('a machine name computed unless sent', () => {
  // Example 15's venue, abbreviated: the machine name is suggested from the name.
  const venue: Schema = {
    type: 'object',
    properties: {
      label: { title: 'Name', type: 'string', 'x-surface': { widget: 'text' } },
      id: {
        title: 'Machine name',
        type: 'string',
        pattern: '^[a-z0-9_]+$',
        maxLength: 32,
        'x-surface': { widget: 'text', computed: { from: ['label'], when: 'unless_sent' } },
      },
    },
    additionalProperties: false,
    required: ['label'],
  };

  it('is suggested by the server while the name changes', () => {
    expect(isSuggested(venue, 'id')).toBe(true);
    expect(isSuggested(venue, 'label')).toBe(false);
    const narrowed = narrow({ full: venue, previous: { schema: venue, values: {}, stale: [] }, stored: {}, values: { label: 'Town Hall' }, stale: [], changed: 'label' });
    expect(narrowed).toEqual({ local: false, key: 'id', marker: 'computed' });
  });

  it('is left out until the person types it, then sent as typed', () => {
    const values = { label: 'Town Hall', id: 'town_hall' };
    expect(withoutSuggested(venue, values, new Set())).toEqual({ label: 'Town Hall' });
    expect(withoutComputed(venue, values)).toEqual({ label: 'Town Hall' });
    expect(withoutSuggested(venue, values, new Set(['id']))).toEqual(values);
    expect(withoutComputed(venue, values, new Set(['id']))).toEqual(values);
  });
});

describe('a key declared with a shape', () => {
  // A shape is written once, as a def that allows null (whether a key may
  // hold nothing is the key's), and each key that uses it refers to it
  // first, then says its title, what it narrows and its whole x-surface.
  const text = (dependsOn: string[] = []) => ({ widget: 'text' as const, dependsOn });
  const code: Schema = {
    title: 'Stock code',
    description: 'Letters and digits.',
    type: ['string', 'null'],
    maxLength: 12,
    pattern: '^[A-Z]{2}\\d+$',
    examples: ['AB12'],
    'x-surface': { patternMessage: 'A stock code is two capitals and digits, such as AB12.' },
  } as Schema;
  const root: Schema = {
    type: 'object',
    properties: {
      sku: { $ref: '#/$defs/resource_test.sku', title: 'Stock code', type: 'string', minLength: 4, 'x-surface': { ...text(), patternMessage: 'A stock code is two capitals and digits, such as AB12.' } },
      spare: { $ref: '#/$defs/resource_test.sku', title: 'Spare code', description: 'Printed on the back.', maxLength: 6, 'x-surface': { ...text(), patternMessage: 'A stock code is two capitals and digits, such as AB12.' } },
      tag: { title: 'Tag', $ref: '#/$defs/resource_test.shelf_tag', 'x-surface': fieldset },
    },
    required: ['sku'],
    $defs: {
      'resource_test.sku': code,
      'resource_test.shelf_tag': { type: 'object', properties: { label: { title: 'Label', type: ['string', 'null'], 'x-surface': text(['sku']) } }, additionalProperties: false },
    },
  };

  it('reads each key as the def with the key laid over it: its type, its bounds, its words', () => {
    const inline = inlineSchema(root);
    expect(inline.properties?.sku).toEqual({
      title: 'Stock code',
      description: 'Letters and digits.',
      type: 'string',
      maxLength: 12,
      pattern: '^[A-Z]{2}\\d+$',
      examples: ['AB12'],
      'x-surface': { ...text(), patternMessage: 'A stock code is two capitals and digits, such as AB12.' },
      minLength: 4,
    });
    expect(inline.properties?.spare).toMatchObject({ title: 'Spare code', description: 'Printed on the back.', type: ['string', 'null'], maxLength: 6, pattern: '^[A-Z]{2}\\d+$' });
    expect(inline.properties?.spare).not.toHaveProperty('$ref');
  });

  it('is a value, never a frame: only an attached child is a part', () => {
    const inline = inlineSchema(root);
    expect(isPart(inline.properties!.sku)).toBe(false);
    expect(isPart(inline.properties!.spare)).toBe(false);
    expect(isPart(inline.properties!.tag)).toBe(true);
  });

  it('reads the compliance license the server refers to its def, in both documents', () => {
    const recorded = license as unknown as { schema: Schema; contract: { schema: Schema } };
    for (const document of [recorded.schema, recorded.contract.schema]) {
      expect(document.$defs?.['event.license']).toMatchObject({ type: ['string', 'null'], pattern: '^\\d{4}$', examples: ['2048'] });
      const mount = inlineSchema(document).properties?.third_party_settings.properties?.data_surface_examples_compliance as Schema;
      expect(isPart(mount)).toBe(true);
      expect(isPart(mount.properties!.license)).toBe(false);
      expect(mount.properties?.license).toMatchObject({ title: 'Event license', type: ['string', 'null'], pattern: '^\\d{4}$' });
      expect(mount.properties?.license['x-surface']).toMatchObject({ widget: 'text', patternMessage: 'A license number is four digits, such as 2048.' });
    }
  });
});

describe('contract 4 on the wire', () => {
  const said = (widget: Extension['widget'], more: Partial<Extension> = {}): Extension => ({ widget, ...more });

  it('refuses a document of contract 1, which states no version, and one of any other', () => {
    expect(CONTRACT).toBe(4);
    expect(() => assertContract({ schema: { type: 'object' } })).toThrow(new ContractRefused('1'));
    expect(() => assertContract({ schema: { type: 'object' } })).toThrow('This page reads contract 4; the server sent contract 1.');
    expect(() => assertContract({ schema: { 'x-surface': { contract: 3 } as Extension } })).toThrow('This page reads contract 4; the server sent contract 3.');
    expect(assertContract(example3 as unknown as { schema: Schema }).schema['x-surface']?.contract).toBe(4);
  });

  it('refuses a full schema, served bare, of any other contract', () => {
    const recorded = license as unknown as { schema: Schema };
    expect(assertSchema(recorded.schema)['x-surface']?.contract).toBe(4);
    expect(() => assertSchema({ ...recorded.schema, 'x-surface': { ...recorded.schema['x-surface'], contract: 3 } as Extension })).toThrow(
      'This page reads contract 4; the server sent contract 3.',
    );
  });

  it('puts each `within` entry back at its pointer, a property left out or a `true` made an object again, and drops the map', () => {
    const bin: Schema = {
      title: 'pantry.bin',
      type: 'object',
      properties: {
        size: { title: 'Size', type: ['integer', 'null'], maximum: 10, 'x-surface': said('number') },
        'a/b': { title: 'Odd', type: 'string', 'x-surface': said('text') },
        tag: { title: 'Tag', type: 'string', 'x-surface': said('text') },
        note: { title: 'Note', type: 'string', 'x-surface': said('text') },
      },
      additionalProperties: false,
    };
    const site = {
      title: 'Left bin',
      allOf: [
        { $ref: '#/$defs/pantry.bin' },
        { type: 'object', properties: { size: { type: ['integer', 'null'], maximum: 4 }, tag: [], note: { readOnly: true, const: 'kept' } } },
      ],
      'x-surface': {
        ...said('fieldset', { refined: true }),
        within: {
          '/allOf/1/properties/size': said('number', { refined: true }),
          '/allOf/1/properties/a~1b': said('text', { dependsOn: ['size'] }),
          '/allOf/1/properties/tag': said('text', { dependsOn: ['size'] }),
          '/allOf/1/properties/note': said('text', { dependsOn: ['size'] }),
        },
      },
    } as unknown as Schema;
    // A narrowing that said nothing but what its keys' `x-surface` says: `true`.
    const right = {
      title: 'Right bin',
      allOf: [{ $ref: '#/$defs/pantry.bin' }, true],
      'x-surface': { ...said('fieldset'), within: { '/allOf/1/properties/tag': said('text', { dependsOn: ['size'] }) } },
    } as unknown as Schema;
    const root: Schema = { type: 'object', properties: { left: site, right }, $defs: { 'pantry.bin': bin } };
    const back = restored(root).properties!.left as unknown as { allOf: { properties: Record<string, Schema> }[]; 'x-surface': Extension };
    expect(back['x-surface']).toEqual(said('fieldset', { refined: true }));
    expect(back.allOf[1].properties.size['x-surface']).toEqual(said('number', { refined: true }));
    // A property that said nothing else was left out of the narrowing.
    expect(back.allOf[1].properties['a/b']).toEqual({ 'x-surface': said('text', { dependsOn: ['size'] }) });
    // An empty narrowing encoded as an empty list.
    expect(back.allOf[1].properties.tag).toEqual({ 'x-surface': said('text', { dependsOn: ['size'] }) });
    expect(restored(root).properties?.right.allOf?.[1]).toEqual({ properties: { tag: { 'x-surface': said('text', { dependsOn: ['size'] }) } } });
    // What was served is left as it was.
    expect((site as unknown as { 'x-surface': Extension })['x-surface'].within).toBeDefined();

    const left = inlineSchema(root).properties!.left;
    expect(left['x-surface']).toEqual(said('fieldset', { refined: true }));
    expect(left.title).toBe('Left bin');
    expect(left.properties?.size).toMatchObject({ title: 'Size', maximum: 4, 'x-surface': { refined: true } });
    expect(left.properties?.['a/b']['x-surface']?.dependsOn).toEqual(['size']);
    expect(left.properties?.tag['x-surface']?.dependsOn).toEqual(['size']);
    expect(left.properties?.note).toMatchObject({ readOnly: true, const: 'kept', 'x-surface': { dependsOn: ['size'] } });
    const rightBin = inlineSchema(root).properties!.right;
    expect(rightBin.properties?.tag).toMatchObject({ title: 'Tag', 'x-surface': { dependsOn: ['size'] } });
    expect(rightBin.properties?.size['x-surface']?.dependsOn).toBeUndefined();
  });

  it('reads the compliance mount\'s stewards with what the site says of them', () => {
    const mount = inlineSchema(example3.schema as unknown as Schema).properties?.third_party_settings.properties?.data_surface_examples_compliance as Schema;
    expect(mount['x-surface']).not.toHaveProperty('within');
    expect(mount.properties?.stewards).toMatchObject({ type: 'integer', minimum: 1, description: 'At least 1 steward for 50 attendees.' });
    expect(mount.properties?.stewards['x-surface']).toEqual(said('number', { dependsOn: ['capacity'], refined: true }));
  });

  it('marks each conditional `x-surface.rules` lists as a declared rule, at the top and in a def', () => {
    const raw = (journal as unknown as { schema: Schema }).schema;
    const rules = (schema: Schema): number[] => (schema.allOf ?? []).flatMap((condition, i) => (condition['x-surface']?.declared ? [i] : []));
    expect(raw['x-surface']?.rules).toEqual([2, 4, 5]);
    const back = restored(raw);
    expect(rules(back)).toEqual([2, 4, 5]);
    expect(back['x-surface']).not.toHaveProperty('rules');
    expect(rules(back.$defs!['resource_test.timestamps'])).toEqual([0]);
    expect(rules(back.$defs!['resource_test.archive'])).toEqual([1]);
    // Applied twice, nothing more changes.
    expect(restored(back)).toEqual(back);
    // Composed, each rule stays marked where the frame's conditionals move to.
    const full = inlineSchema(raw);
    const marked = (full.allOf ?? []).filter((condition) => condition['x-surface']?.declared);
    expect(marked.length).toBeGreaterThan(rules(back).length);
  });

  it('drops a key\'s union of its variants and a def\'s own title when inlining', () => {
    const raw = example3.schema as unknown as Schema;
    expect(raw.properties?.ticket.anyOf).toEqual([
      { $ref: '#/$defs/registration.step3.ticket.free' },
      { $ref: '#/$defs/registration.step3.ticket.paid' },
      { type: 'null' },
    ]);
    expect(raw.properties?.ticket['x-surface']?.variants).toEqual({ free: '#/$defs/registration.step3.ticket.free', paid: '#/$defs/registration.step3.ticket.paid' });
    for (const [id, def] of Object.entries(raw.$defs ?? {})) {
      expect(def.title).toBe(id);
    }
    const inline = inlineSchema(raw);
    expect(inline.properties?.ticket).not.toHaveProperty('anyOf');
    expect(resolveVariants(inline, 'ticket', { pricing: 'paid' })?.title).toBe('Ticket');
    expect(inline.properties?.contact.title).toBe('Contact');
    // A def followed with no title at its site says no title of its own.
    expect(resolveSchema({ $ref: '#/$defs/registration.contact' }, raw)).not.toHaveProperty('title');
  });
});
