import type { Schema } from '../contract';
import { defName, inlineSchema, resolveSchema, resolveSlot, watchedPaths } from '../contract';
import example3 from './fixtures/example3.json';

// The resolver, over schemas shaped as the emitter writes them: each
// child a def under `$defs`, each site a reference beside its own
// keywords, a site that says more composed with its narrowing.

const contact: Schema = {
  type: 'object',
  properties: {
    email: { title: 'Email', type: 'string', format: 'email', 'x-surface': { widget: 'email', locked: false, dependsOn: [], refined: false, stale: false } },
    phone: { title: 'Phone', type: ['string', 'null'], 'x-surface': { widget: 'text', locked: false, dependsOn: [], refined: false, stale: false } },
  },
  additionalProperties: false,
  required: ['email'],
};

const fieldset = { widget: 'fieldset' as const, locked: false, dependsOn: [], refined: false, stale: false };

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
        bin: { title: 'Bin', type: 'string', 'x-surface': { widget: 'text', locked: false, dependsOn: [], refined: false, stale: false } },
        size: { title: 'Size', type: ['integer', 'null'], minimum: 1, maximum: 10, 'x-surface': { widget: 'number', locked: false, dependsOn: [], refined: false, stale: false } },
      },
      additionalProperties: false,
      required: ['bin'],
    };
    const site = {
      title: 'Left bin',
      allOf: [
        { $ref: '#/$defs/surface_test.cupboard.bin' },
        {
          properties: {
            bin: { readOnly: true, const: 'left', 'x-surface': { widget: 'text', locked: true, dependsOn: [], refined: false, stale: false } },
            size: { maximum: 4 },
          },
          required: ['size'],
        },
      ],
      'x-surface': fieldset,
    } as unknown as Schema;
    const root: Schema = { type: 'object', properties: { left: site }, $defs: { 'surface_test.cupboard.bin': bin } };
    const resolved = resolveSchema(site, root);
    expect(resolved).not.toHaveProperty('allOf');
    expect(resolved.title).toBe('Left bin');
    expect(resolved.properties?.bin).toMatchObject({ title: 'Bin', type: 'string', readOnly: true, const: 'left' });
    expect(resolved.properties?.bin['x-surface']?.locked).toBe(true);
    expect(resolved.properties?.size).toMatchObject({ title: 'Size', minimum: 1, maximum: 4 });
    expect(resolved.required).toEqual(['bin', 'size']);
    // The def itself is untouched.
    expect(bin.properties?.size.maximum).toBe(10);
    expect(bin.properties?.bin['x-surface']?.locked).toBe(false);
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
    expect(Object.keys(schema.$defs ?? {})).toEqual(['registration.step3.ticket.free', 'registration.step3.ticket.paid', 'registration.contact']);
    expect(schema.properties?.contact.$ref).toBe('#/$defs/registration.contact');
    expect(schema.properties?.contact).not.toHaveProperty('properties');
  });

  it('reads inline as one schema: the contact\'s keys and each ticket\'s through the slot', () => {
    const inline = inlineSchema(schema);
    expect(Object.keys(inline.properties?.contact.properties ?? {})).toEqual(['email', 'phone']);
    expect(inline.properties?.contact.title).toBe('Contact');
    expect(inline.properties?.contact.required).toEqual(['email']);
    expect(Object.keys(resolveSlot(inline, 'ticket', { pricing: 'free' })?.properties ?? {})).toEqual(['note']);
    const paid = resolveSlot(inline, 'ticket', { pricing: 'paid' });
    expect(paid?.title).toBe('Ticket');
    expect(paid?.['x-surface']?.variant).toBe('paid');
    expect(paid?.required).toEqual(['price', 'currency']);
  });
});

describe('a collection', () => {
  const shelf: Schema = {
    type: 'object',
    properties: {
      unit: { title: 'Unit', type: 'string', 'x-surface': { widget: 'select', locked: false, dependsOn: [], refined: false, stale: false } },
      height: { title: 'Height', type: 'integer', 'x-surface': { widget: 'number', locked: false, dependsOn: ['unit'], refined: false, stale: false } },
    },
    additionalProperties: false,
  };
  const root: Schema = {
    type: 'object',
    properties: {
      shelves: {
        title: 'Shelves',
        type: ['array', 'null'],
        items: { $ref: '#/$defs/surface_test.pantry.shelf' },
        maxItems: 3,
        'x-surface': { widget: 'list', list: true, locked: false, dependsOn: [], refined: false, stale: false },
      },
    },
    $defs: { 'surface_test.pantry.shelf': shelf },
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
