import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import type { Extension, Schema, Values } from '../contract';
import { setAt } from '../contract';
import { ContractPanel } from '../ContractPanel';
import { Properties } from '../widgets/Field';
import { LookupContext, type Lookup } from '../widgets/Shell';

// Each widget from a schema fragment the way the emitter writes one.

function x(widget: Extension['widget'], more: Partial<Extension> = {}): Extension {
  return { widget, locked: false, dependsOn: [], refined: false, stale: false, ...more };
}

/** Renders an object schema's properties over values, editable. */
function Form({ schema, initial, onValues }: { schema: Schema; initial: Values; onValues?: (values: Values) => void }) {
  const [values, setValues] = useState<Values>(initial);
  return (
    <form>
      <Properties
        schema={schema}
        values={values}
        prefix=""
        errors={{}}
        onChange={(path, value) => {
          const next = setAt(values, path, value);
          setValues(next);
          onValues?.(next);
        }}
      />
    </form>
  );
}

function object(properties: Record<string, Schema>, required: string[] = [], allOf?: Schema['allOf']): Schema {
  return { type: 'object', properties, required, ...(allOf ? { allOf } : {}) };
}

const venues: Schema['oneOf'] = [
  { const: 'riverside', title: 'Riverside Hall' },
  { const: 'library', title: 'Old Library' },
];

describe('text, textarea and email', () => {
  it('renders a text input with its title, marker, description, maxlength and placeholder', () => {
    render(
      <Form
        schema={object(
          {
            headline: {
              title: 'Headline',
              description: 'Shown above.',
              type: 'string',
              maxLength: 50,
              examples: ['Quarterly report'],
              'x-surface': x('text', { arrangement: { placeholder: 'A short headline' } }),
            },
          },
          ['headline'],
        )}
        initial={{ headline: 'Featured' }}
      />,
    );
    const input = screen.getByLabelText(/Headline/);
    expect(input).toHaveAttribute('type', 'text');
    expect(input).toHaveAttribute('maxlength', '50');
    // The declared placeholder, never the example.
    expect(input).toHaveAttribute('placeholder', 'A short headline');
    expect(input).toBeRequired();
    expect(input).toHaveValue('Featured');
    expect(input).toHaveAccessibleDescription('Shown above.');
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('never puts a Regex on the input as a bare pattern: the server says what is wrong, in its message', () => {
    const message = 'A license number is four digits, such as 2048.';
    render(
      <Form
        schema={object({
          license: {
            title: 'Event license',
            type: 'string',
            pattern: '^\\d{4}$',
            examples: ['2048'],
            'x-surface': x('text', { patternMessage: message }),
          },
        })}
        initial={{ license: 'EV-2048' }}
      />,
    );
    const input = screen.getByLabelText(/Event license/);
    // A bare pattern makes the browser ask for "the requested format",
    // which is vaguer than the message the server answers with.
    expect(input).not.toHaveAttribute('pattern');
    // An example is no placeholder: an empty field showing a value reads
    // as filled.
    expect(input).not.toHaveAttribute('placeholder');
  });

  it('renders a textarea with no maxlength, and only a declared placeholder', () => {
    render(
      <Form
        schema={object({ help: { title: 'Help', type: ['string', 'null'], maxLength: 10, examples: ['x'], 'x-surface': x('textarea') } })}
        initial={{ help: null }}
      />,
    );
    const textarea = screen.getByLabelText('Help');
    expect(textarea.tagName).toBe('TEXTAREA');
    expect(textarea).not.toHaveAttribute('maxlength');
    expect(textarea).not.toHaveAttribute('placeholder');
    expect(textarea).not.toBeRequired();
  });

  it('renders an email input for the email widget, and a password for a secret', () => {
    render(
      <Form
        schema={object({
          email: { title: 'Email', type: 'string', format: 'idn-email', 'x-surface': x('email') },
          token: { title: 'Token', type: ['string', 'null'], writeOnly: true, 'x-surface': x('text') },
        })}
        initial={{ email: 'events@example.com', token: null }}
      />,
    );
    expect(screen.getByLabelText('Email')).toHaveAttribute('type', 'email');
    const token = screen.getByLabelText('Token');
    expect(token).toHaveAttribute('type', 'password');
    expect(token).toHaveAccessibleDescription('Leave blank to keep the current value.');
  });
});

describe('number and checkbox', () => {
  it('renders a number with Range as min and max, an integer stepping by one', async () => {
    let seen: Values = {};
    render(
      <Form
        schema={object({ capacity: { title: 'Capacity', type: ['integer', 'null'], minimum: 1, maximum: 60, 'x-surface': x('number', { dependsOn: ['room'] }) } })}
        initial={{ capacity: 50 }}
        onValues={(values) => (seen = values)}
      />,
    );
    const input = screen.getByLabelText('Capacity');
    expect(input).toHaveAttribute('min', '1');
    expect(input).toHaveAttribute('max', '60');
    expect(input).toHaveAttribute('step', '1');
    await userEvent.clear(input);
    await userEvent.type(input, '45');
    expect(seen.capacity).toBe(45);
  });

  it('steps a float by any', () => {
    render(<Form schema={object({ price: { title: 'Price', type: 'number', minimum: 0.01, 'x-surface': x('number') } })} initial={{ price: 12.5 }} />);
    expect(screen.getByLabelText(/Price/)).toHaveAttribute('step', 'any');
  });

  it('shows a stale number or text as nothing, not required to the browser, the marker kept', async () => {
    let seen: Values = {};
    render(
      <Form
        schema={object(
          {
            capacity: { title: 'Capacity', type: ['integer', 'null'], maximum: 1000, 'x-surface': x('number', { stale: true, dependsOn: ['room'] }) },
            code: { title: 'Code', type: 'string', 'x-surface': x('text', { stale: true }) },
          },
          ['capacity', 'code'],
        )}
        initial={{ capacity: null, code: null }}
        onValues={(values) => (seen = values)}
      />,
    );
    const capacity = screen.getByLabelText(/Capacity/);
    expect(capacity).toHaveValue(null);
    expect(capacity).not.toBeRequired();
    const code = screen.getByLabelText(/Code/);
    expect(code).toHaveValue('');
    expect(code).not.toBeRequired();
    expect(screen.getAllByText('*')).toHaveLength(2);
    // Typing is an answer, sent as typed.
    await userEvent.type(capacity, '80');
    expect(seen.capacity).toBe(80);
  });

  it('renders a checkbox that is never marked required', () => {
    render(<Form schema={object({ open: { title: 'Registration open', type: 'boolean', 'x-surface': x('checkbox') } }, ['open'])} initial={{ open: true }} />);
    const box = screen.getByLabelText('Registration open');
    expect(box).toBeChecked();
    expect(box).not.toBeRequired();
    expect(screen.queryByText('*')).not.toBeInTheDocument();
  });
});

describe('select and the empty option rule', () => {
  it('titles its options from oneOf and shows no empty option when required and validly chosen', () => {
    render(
      <Form
        schema={object({ venue: { title: 'Venue', type: 'string', oneOf: venues, 'x-surface': x('select', { emptyOption: { show: false, label: '- Select -' } }) } }, ['venue'])}
        initial={{ venue: 'library' }}
      />,
    );
    const select = screen.getByLabelText(/Venue/);
    const options = within(select).getAllByRole('option').map((option) => option.textContent);
    expect(options).toEqual(['Riverside Hall', 'Old Library']);
    expect(select).toHaveDisplayValue('Old Library');
    expect(select).toBeRequired();
  });

  it('comes up on - Select - when required and nothing is chosen', () => {
    render(
      <Form
        schema={object({ venue: { title: 'Venue', type: 'string', oneOf: venues, 'x-surface': x('select', { emptyOption: { show: true, label: '- Select -' } }) } }, ['venue'])}
        initial={{ venue: null }}
      />,
    );
    expect(screen.getByLabelText(/Venue/)).toHaveDisplayValue('- Select -');
  });

  it('keeps - None - on an optional select, which clears the key', async () => {
    let seen: Values = {};
    render(
      <Form
        schema={object({ venue: { title: 'Venue', type: ['string', 'null'], oneOf: [...venues!, { const: null }], 'x-surface': x('select', { emptyOption: { show: true, label: '- None -' } }) } })}
        initial={{ venue: 'library' }}
        onValues={(values) => (seen = values)}
      />,
    );
    const select = screen.getByLabelText('Venue');
    expect(within(select).getAllByRole('option').map((option) => option.textContent)).toEqual(['- None -', 'Riverside Hall', 'Old Library']);
    await userEvent.selectOptions(select, '- None -');
    expect(seen.venue).toBeNull();
  });

  it('shows a stale value on the empty option, selected, not required to the browser, the marker kept', () => {
    render(
      <Form
        schema={object(
          { room: { title: 'Room', type: 'string', oneOf: [{ const: 'riverside_main', title: 'Main hall' }], 'x-surface': x('select', { stale: true, dependsOn: ['venue'], emptyOption: { show: true, label: '- Select -' } }) } },
          ['room'],
        )}
        initial={{ room: null }}
      />,
    );
    const select = screen.getByLabelText(/Room/);
    expect(select).toHaveDisplayValue('- Select -');
    expect(select).not.toBeRequired();
    expect(within(select).queryByText('library_reading')).not.toBeInTheDocument();
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('hands back an integer value as an integer', async () => {
    let seen: Values = {};
    render(
      <Form
        schema={object({ preview: { title: 'Preview', type: 'integer', oneOf: [{ const: 0, title: 'Disabled' }, { const: 1, title: 'Optional' }], 'x-surface': x('select', { emptyOption: { show: false, label: '- Select -' } }) } }, ['preview'])}
        initial={{ preview: 1 }}
        onValues={(values) => (seen = values)}
      />,
    );
    await userEvent.selectOptions(screen.getByLabelText(/Preview/), 'Disabled');
    expect(seen.preview).toBe(0);
  });

  it('renders radios over the same options', async () => {
    let seen: Values = {};
    render(
      <Form
        schema={object({ venue: { title: 'Venue', type: 'string', oneOf: venues, 'x-surface': x('radios', { emptyOption: { show: false, label: '- Select -' } }) } }, ['venue'])}
        initial={{ venue: 'library' }}
        onValues={(values) => (seen = values)}
      />,
    );
    expect(screen.getByLabelText('Old Library')).toBeChecked();
    await userEvent.click(screen.getByLabelText('Riverside Hall'));
    expect(seen.venue).toBe('riverside');
  });
});

describe('checkboxes, radios and a time', () => {
  const days: Schema = {
    title: 'Days open',
    type: 'array',
    items: { type: 'string', oneOf: [{ const: 'mon', title: 'Monday' }, { const: 'tue', title: 'Tuesday' }, { const: 'sat', title: 'Saturday' }] },
    'x-surface': x('checkboxes'),
  };

  it('maps `checkboxes` to a box per option, checked by the list, sent in option order', async () => {
    let seen: Values = {};
    render(<Form schema={object({ days }, ['days'])} initial={{ days: ['tue'] }} onValues={(values) => (seen = values)} />);
    expect(screen.getAllByRole('checkbox')).toHaveLength(3);
    expect(screen.getByLabelText('Tuesday')).toBeChecked();
    expect(screen.getByLabelText('Monday')).not.toBeChecked();
    expect(screen.getByLabelText('Monday')).not.toBeRequired();
    await userEvent.click(screen.getByLabelText('Saturday'));
    await userEvent.click(screen.getByLabelText('Monday'));
    expect(seen.days).toEqual(['mon', 'tue', 'sat']);
  });

  it('sends the empty list when every box is unchecked', async () => {
    let seen: Values = {};
    render(<Form schema={object({ days }, ['days'])} initial={{ days: ['tue'] }} onValues={(values) => (seen = values)} />);
    await userEvent.click(screen.getByLabelText('Tuesday'));
    expect(seen.days).toEqual([]);
  });

  it('draws "- None -" as a radio only where the contract shows it, and clears with it', async () => {
    let seen: Values = {};
    const handling: Schema = {
      title: 'Handling',
      type: ['string', 'null'],
      oneOf: [{ const: 'fragile', title: 'Fragile' }, { const: 'upright', title: 'This way up' }, { const: null }],
      'x-surface': x('radios', { emptyOption: { show: true, label: '- None -' } }),
    };
    render(<Form schema={object({ handling })} initial={{ handling: 'fragile' }} onValues={(values) => (seen = values)} />);
    expect(screen.getAllByRole('radio')).toHaveLength(3);
    await userEvent.click(screen.getByLabelText('- None -'));
    expect(seen.handling).toBeNull();
  });

  it('draws no empty radio for a key that holds a value, and nothing checked while it holds no valid choice', () => {
    const holidays: Schema = {
      title: 'On public holidays',
      type: 'string',
      oneOf: [{ const: 'closed', title: 'Closed' }, { const: 'open', title: 'Open as usual' }],
      'x-surface': x('radios', { stale: true, emptyOption: { show: false, label: '- None -' } }),
    };
    render(<Form schema={object({ holidays })} initial={{ holidays: null }} />);
    expect(screen.getAllByRole('radio')).toHaveLength(2);
    expect(screen.queryByLabelText('- None -')).toBeNull();
    for (const radio of screen.getAllByRole('radio')) {
      expect(radio).not.toBeChecked();
    }
  });

  it('maps `time` to a time input', () => {
    render(<Form schema={object({ opens: { title: 'Opens at', type: 'string', 'x-surface': x('time') } })} initial={{ opens: '09:00' }} />);
    const input = screen.getByLabelText(/Opens at/);
    expect(input).toHaveAttribute('type', 'time');
    expect(input).toHaveValue('09:00');
  });

  it('maps `list` to rows to add and remove, each the item\'s own field', async () => {
    let seen: Values = {};
    const keywords: Schema = { title: 'Keywords', type: 'array', items: { type: 'string', 'x-surface': x('text') }, 'x-surface': x('list') };
    render(<Form schema={object({ keywords })} initial={{ keywords: ['maps'] }} onValues={(values) => (seen = values)} />);
    await userEvent.click(screen.getByRole('button', { name: 'Add keywords' }));
    expect(seen.keywords).toEqual(['maps', '']);
    await userEvent.click(screen.getByRole('button', { name: 'Remove 1' }));
    expect(seen.keywords).toEqual(['']);
  });
});

describe('locked', () => {
  it('renders a locked key disabled, saying why', () => {
    render(
      <Form
        schema={object({ type: { title: 'Machine name', description: 'Unique.', type: 'string', readOnly: true, const: 'article', 'x-surface': x('text', { locked: true }) } }, ['type'])}
        initial={{ type: 'article' }}
      />,
    );
    const input = screen.getByLabelText(/Machine name/);
    expect(input).toBeDisabled();
    expect(input).toHaveValue('article');
    expect(input).toHaveAccessibleDescription('Unique. Fixed for this operation.');
  });

  it('renders a child its access locked as a fieldset of disabled keys, holding what is stored', () => {
    // As the emitter writes a locked child: the fieldset and every key in
    // it readOnly and locked, each holding its stored value.
    const locked = (title: string, value: unknown, widget: Extension['widget']): Schema => ({
      title,
      type: widget === 'number' ? 'integer' : 'string',
      readOnly: true,
      const: value,
      'x-surface': x(widget, { locked: true }),
    });
    render(
      <Form
        schema={object({
          rack: {
            title: 'Spice rack',
            type: 'object',
            readOnly: true,
            properties: { spices: locked('Spices', 7, 'number'), label: locked('Label', 'Everyday', 'text') },
            'x-surface': x('fieldset', { locked: true }),
          },
        })}
        initial={{ rack: { spices: 7, label: 'Everyday' } }}
      />,
    );
    const rack = screen.getByRole('group', { name: 'Spice rack' });
    expect(within(rack).getByLabelText(/Spices/)).toBeDisabled();
    expect(within(rack).getByLabelText(/Spices/)).toHaveValue(7);
    expect(within(rack).getByLabelText(/Label/)).toBeDisabled();
    expect(within(rack).getByLabelText(/Label/)).toHaveValue('Everyday');
  });
});

describe('fieldset, variants and list', () => {
  const ticketVariants: Schema = { title: 'Ticket', type: 'object', 'x-surface': x('variants', { by: 'pricing', variants: { free: '#/$defs/registration.step3.ticket.free', paid: '#/$defs/registration.step3.ticket.paid' }, dependsOn: ['pricing'] }) };
  const branches: Schema['allOf'] = [
    {
      if: { properties: { pricing: { const: 'free' } }, required: ['pricing'] },
      then: { properties: { ticket: { title: 'Ticket', type: 'object', properties: { note: { title: 'Note', type: ['string', 'null'], 'x-surface': x('text') } } } } },
    },
    {
      if: { properties: { pricing: { const: 'paid' } }, required: ['pricing'] },
      then: {
        properties: {
          ticket: {
            title: 'Ticket',
            type: 'object',
            required: ['price'],
            properties: { price: { title: 'Price', type: 'number', minimum: 0.01, 'x-surface': x('number') } },
          },
        },
      },
    },
  ];
  const pricing: Schema = {
    title: 'Pricing',
    type: ['string', 'null'],
    oneOf: [{ const: 'free', title: 'free' }, { const: 'paid', title: 'paid' }, { const: null }],
    'x-surface': x('select', { emptyOption: { show: true, label: '- None -' } }),
  };

  it('renders a fieldset with its legend and its properties', () => {
    render(
      <Form
        schema={object({ contact: { title: 'Contact', type: 'object', required: ['email'], properties: { email: { title: 'Email', type: 'string', format: 'idn-email', 'x-surface': x('email') } }, 'x-surface': x('fieldset') } })}
        initial={{ contact: { email: 'events@example.com' } }}
      />,
    );
    const group = screen.getByRole('group', { name: 'Contact' });
    expect(within(group).getByLabelText(/Email/)).toHaveValue('events@example.com');
    expect(within(group).getByLabelText(/Email/)).toBeRequired();
  });

  it('draws nothing around an object that only groups, and each module\'s fieldset inside it', () => {
    const compliance: Schema = {
      title: 'Compliance',
      type: 'object',
      properties: { license: { title: 'Event license', type: ['string', 'null'], 'x-surface': x('text') } },
      'x-surface': x('fieldset'),
    };
    render(
      <Form
        schema={object({ third_party_settings: { type: 'object', properties: { compliance }, 'x-surface': x('fieldset', { group: true }) } })}
        initial={{ third_party_settings: { compliance: { license: '2048' } } }}
      />,
    );
    const groups = screen.getAllByRole('group');
    expect(groups.map((group) => group.querySelector('legend')?.textContent)).toEqual(['Compliance']);
    expect(within(groups[0]).getByLabelText('Event license')).toHaveValue('2048');
  });

  it('draws a placed fieldset after the sibling it names, and posts its values under its group', async () => {
    const compliance: Schema = {
      title: 'Compliance',
      type: 'object',
      properties: { license: { title: 'Event license', type: ['string', 'null'], 'x-surface': x('text') } },
      'x-surface': x('fieldset', { after: 'capacity' }),
    };
    const badges: Schema = {
      title: 'Badges',
      type: 'object',
      properties: { badge: { title: 'Badge', type: ['string', 'null'], 'x-surface': x('text') } },
      'x-surface': x('fieldset'),
    };
    const seen: Values[] = [];
    render(
      <Form
        schema={object({
          title: { title: 'Title', type: 'string', 'x-surface': x('text') },
          capacity: { title: 'Capacity', type: 'integer', 'x-surface': x('number') },
          pricing: { title: 'Pricing', type: 'string', 'x-surface': x('text') },
          third_party_settings: { type: 'object', properties: { compliance, badges }, 'x-surface': x('fieldset', { group: true }) },
        })}
        initial={{ title: 'Meetup', capacity: 50, pricing: 'free', third_party_settings: { compliance: { license: '' }, badges: { badge: 'star' } } }}
        onValues={(values) => seen.push(values)}
      />,
    );
    const placed = screen.getByRole('group', { name: 'Compliance' });
    // Right after the capacity, before the pricing; the unplaced module's
    // fieldset stays in its group, where the group sits.
    expect(placed.previousElementSibling).toContainElement(screen.getByLabelText('Capacity'));
    expect(placed.nextElementSibling).toContainElement(screen.getByLabelText('Pricing'));
    const group = document.querySelector('.dsr-group');
    expect(group).toContainElement(screen.getByRole('group', { name: 'Badges' }));
    expect(group).not.toContainElement(placed);
    // Its id is its path's, as everything inside it is.
    expect(placed.id).toBe('dsr-third_party_settings-compliance');
    await userEvent.type(within(placed).getByLabelText('Event license'), '2048');
    expect(seen[seen.length - 1]).toEqual({
      title: 'Meetup',
      capacity: 50,
      pricing: 'free',
      third_party_settings: { compliance: { license: '2048' }, badges: { badge: 'star' } },
    });
  });

  it('renders the variant the deciding key chose, and switches with it', async () => {
    render(<Form schema={object({ pricing, ticket: ticketVariants }, [], branches)} initial={{ pricing: 'free', ticket: { note: 'Donations welcome' } }} />);
    expect(screen.getByRole('group', { name: 'Ticket' })).toBeInTheDocument();
    expect(screen.getByLabelText('Note')).toHaveValue('Donations welcome');
    await userEvent.selectOptions(screen.getByLabelText('Pricing'), 'paid');
    expect(screen.queryByLabelText('Note')).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Price/)).toBeRequired();
  });

  it('renders nothing for a variant that declares no keys, and the other variants as they are', async () => {
    const schedule: Schema = { title: 'Schedule', type: ['object', 'null'], 'x-surface': x('variants', { by: 'frequency', variants: { daily: '#/$defs/schedule.daily', weekly: '#/$defs/schedule.weekly' }, dependsOn: ['frequency'] }) };
    const frequency: Schema = { title: 'Frequency', type: 'string', oneOf: [{ const: 'daily', title: 'Daily' }, { const: 'weekly', title: 'Weekly' }], 'x-surface': x('select') };
    const days: Schema['allOf'] = [
      {
        if: { properties: { frequency: { const: 'daily' } }, required: ['frequency'] },
        then: { properties: { schedule: { title: 'Daily schedule', type: 'object' } } },
      },
      {
        if: { properties: { frequency: { const: 'weekly' } }, required: ['frequency'] },
        then: { properties: { schedule: { title: 'Weekly schedule', type: 'object', properties: { send_day: { title: 'Send day', type: 'string', 'x-surface': x('text') } } } } },
      },
    ];
    render(<Form schema={object({ frequency, schedule }, ['frequency'], days)} initial={{ frequency: 'daily', schedule: {} }} />);
    expect(screen.queryByRole('group')).not.toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText(/Frequency/), 'Weekly');
    expect(screen.getByRole('group', { name: 'Weekly schedule' })).toBeInTheDocument();
    await userEvent.selectOptions(screen.getByLabelText(/Frequency/), 'Daily');
    expect(screen.queryByRole('group')).not.toBeInTheDocument();
  });

  it('locks the chosen variant\'s fieldset with the key with variants, which says it', () => {
    // A variant's conditional carries no `x-surface`: the lock is the key's.
    const locked: Schema = { ...ticketVariants, 'x-surface': { ...(ticketVariants['x-surface'] as Extension), locked: true } };
    render(<Form schema={object({ pricing, ticket: locked }, [], branches)} initial={{ pricing: 'free', ticket: { note: 'Donations welcome' } }} />);
    expect(screen.getByLabelText('Note')).toBeDisabled();
    expect(screen.getByRole('group', { name: 'Ticket' })).toHaveTextContent('Fixed for this operation.');
  });

  it('renders nothing for variants nothing has chosen yet', () => {
    render(<Form schema={object({ pricing, ticket: ticketVariants }, [], branches)} initial={{ pricing: null, ticket: null }} />);
    expect(screen.queryByRole('group', { name: 'Ticket' })).not.toBeInTheDocument();
  });

  it('adds and removes the rows of a list', async () => {
    let seen: Values = {};
    render(
      <Form
        schema={object({ tags: { title: 'Tags', type: ['array', 'null'], items: { title: 'Tag', type: 'string', 'x-surface': x('text') }, 'x-surface': x('list') } })}
        initial={{ tags: ['one'] }}
        onValues={(values) => (seen = values)}
      />,
    );
    expect(screen.getByLabelText('Tag 1')).toHaveValue('one');
    await userEvent.click(screen.getByRole('button', { name: 'Add tags' }));
    await userEvent.type(screen.getByLabelText('Tag 2'), 'two');
    expect(seen.tags).toEqual(['one', 'two']);
    await userEvent.click(screen.getByRole('button', { name: 'Remove 1' }));
    expect(seen.tags).toEqual(['two']);
    expect(screen.getByLabelText('Tag 1')).toHaveValue('two');
  });
});

describe('a collection', () => {
  const rack: Schema = {
    type: 'object',
    properties: {
      label: { title: 'Label', type: ['string', 'null'], 'x-surface': x('text') },
      spices: { title: 'Spices', type: ['integer', 'null'], maximum: 40, 'x-surface': x('number') },
    },
    additionalProperties: false,
  };
  const racks = (more: Partial<Schema> = {}, extension: Partial<Extension> = {}): Schema => ({
    title: 'Spice racks',
    type: ['array', 'null'],
    items: rack,
    minItems: 1,
    maxItems: 3,
    'x-surface': x('list', { list: true, ...extension }),
    ...more,
  });

  it('draws each item as the child\'s fieldset, titled with its number, and adds and removes them', async () => {
    let seen: Values = {};
    render(<Form schema={object({ racks: racks() })} initial={{ racks: [{ label: 'Everyday', spices: 7 }] }} onValues={(values) => (seen = values)} />);
    const first = screen.getByRole('group', { name: 'Spice racks 1' });
    expect(within(first).getByLabelText('Label')).toHaveValue('Everyday');
    // The one item there is the minimum: it cannot be removed.
    expect(screen.getByRole('button', { name: 'Remove 1' })).toBeDisabled();
    await userEvent.click(screen.getByRole('button', { name: 'Add another' }));
    const second = screen.getByRole('group', { name: 'Spice racks 2' });
    await userEvent.type(within(second).getByLabelText('Label'), 'Spare');
    expect(seen.racks).toEqual([{ label: 'Everyday', spices: 7 }, { label: 'Spare' }]);
    await userEvent.click(screen.getByRole('button', { name: 'Add another' }));
    // Three is the most: adding stops there.
    expect(screen.getByRole('button', { name: 'Add another' })).toBeDisabled();
    await userEvent.click(screen.getByRole('button', { name: 'Remove 1' }));
    expect(seen.racks).toEqual([{ label: 'Spare' }, {}]);
    expect(within(screen.getByRole('group', { name: 'Spice racks 1' })).getByLabelText('Label')).toHaveValue('Spare');
  });

  it('draws the minimum when it holds less, and an item\'s error under its delta', () => {
    render(
      <Properties
        schema={object({ racks: racks() })}
        values={{ racks: null }}
        prefix=""
        onChange={() => undefined}
        errors={{ 'racks.0.spices': ['This value should be 40 or less.'] }}
      />,
    );
    const first = screen.getByRole('group', { name: 'Spice racks 1' });
    expect(within(first).getByText('This value should be 40 or less.')).toBeInTheDocument();
    expect(screen.queryByRole('group', { name: 'Spice racks 2' })).not.toBeInTheDocument();
  });

  it('draws a collection inside an item as one, each item\'s list its own', async () => {
    let seen: Values = {};
    const aisles: Schema = {
      title: 'Aisles',
      type: ['array', 'null'],
      items: {
        type: 'object',
        properties: {
          label: { title: 'Aisle label', type: ['string', 'null'], 'x-surface': x('text') },
          racks: racks({ minItems: 0 }),
        },
        additionalProperties: false,
      },
      'x-surface': x('list', { list: true }),
    };
    render(<Form schema={object({ aisles })} initial={{ aisles: [{ label: 'North', racks: [{ label: 'Everyday' }] }, { label: 'South', racks: [] }] }} onValues={(values) => (seen = values)} />);
    const south = screen.getByRole('group', { name: 'Aisles 2' });
    expect(within(screen.getByRole('group', { name: 'Aisles 1' })).getByLabelText('Label')).toHaveValue('Everyday');
    await userEvent.click(within(south).getByRole('button', { name: 'Add another' }));
    await userEvent.type(within(within(south).getByRole('group', { name: 'Spice racks 1' })).getByLabelText('Label'), 'Spare');
    expect(seen.aisles).toEqual([{ label: 'North', racks: [{ label: 'Everyday' }] }, { label: 'South', racks: [{ label: 'Spare' }] }]);
  });

  it('locks every item and both controls with a locked list', () => {
    render(<Form schema={object({ racks: racks({ readOnly: true }, { locked: true }) })} initial={{ racks: [{ label: 'Everyday' }] }} />);
    expect(within(screen.getByRole('group', { name: 'Spice racks 1' })).getByLabelText('Label')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Add another' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Remove 1' })).toBeDisabled();
  });
});

describe('the contract panel', () => {
  it('lists a collection, its bounds, and its item keys once, under a star', () => {
    render(
      <ContractPanel
        contract={{ resource: 'cellar', operation: 'edit', label: null, stale: [], values: {}, schema: object({}) }}
        schema={object({
          racks: {
            title: 'Spice racks',
            type: ['array', 'null'],
            minItems: 1,
            maxItems: 4,
            items: object({ label: { title: 'Label', type: 'string', 'x-surface': x('text') } }),
            'x-surface': x('list', { list: true }),
          },
        })}
        values={{}}
      />,
    );
    const racks = document.querySelector('tr[data-surface-key="racks"]') as HTMLElement;
    expect(racks.textContent).toContain('collection');
    expect(racks.textContent).toContain('from 1 to 4 items');
    expect(document.querySelector('tr[data-surface-key="racks.*.label"]')).not.toBeNull();
  });

  it('says what each constraint only the server checks checks, in its message, and an email by either format', () => {
    render(
      <ContractPanel
        contract={{ resource: 'example', operation: 'configure', label: null, stale: [], values: {}, schema: object({}) }}
        schema={object({
          handle: {
            title: 'Handle',
            type: 'string',
            'x-surface': x('text', { checkedOnServer: [{ constraint: 'UniqueHandle', message: 'No other account uses this handle.' }] }),
          },
          email: { title: 'Email', type: 'string', format: 'idn-email', 'x-surface': x('email') },
        })}
        values={{}}
      />,
    );
    const handle = document.querySelector('tr[data-surface-key="handle"]') as HTMLElement;
    expect(handle.textContent).toContain('checked on the server: No other account uses this handle.');
    expect(handle.textContent).not.toContain('UniqueHandle');
    expect(document.querySelector('tr[data-surface-key="email"]')?.textContent).toContain('an email address');
  });

  it('explains a Regex by its message, and only a Regex without one by its pattern, in code', () => {
    const message = 'A license number is four digits, such as 2048.';
    render(
      <ContractPanel
        contract={{
          resource: 'example',
          operation: 'configure',
          label: null,
          stale: [],
          values: {},
          schema: object({
            license: { title: 'Event license', type: 'string', pattern: '^\\d{4}$', 'x-surface': x('text', { patternMessage: message }) },
            code: { title: 'Code', type: 'string', pattern: '^[a-z]+$', 'x-surface': x('text') },
          }),
        }}
        values={{}}
      />,
    );
    const license = document.querySelector('tr[data-surface-key="license"]') as HTMLElement;
    expect(within(license).getByText(message)).toBeInTheDocument();
    expect(license.textContent).not.toContain('\\d{4}');
    const code = document.querySelector('tr[data-surface-key="code"]') as HTMLElement;
    expect(code.textContent).toContain('matches a required format: ^[a-z]+$');
    expect(within(code).getByText('^[a-z]+$').tagName).toBe('CODE');
  });
});

describe('the arrangement a key declares (x-surface.arrangement)', () => {
  const hours = { id: 'hours', label: 'Opening hours', as: 'fieldset' as const };
  const pricing = { id: 'pricing', label: 'Pricing', as: 'details' as const, collapsed: true };
  const studio = object({
    name: { title: 'Studio name', type: 'string', 'x-surface': x('text', { arrangement: { placeholder: 'Riverside studio', help: 'Shown on the door.' } }) },
    opens: { title: 'Opens at', type: 'string', 'x-surface': x('text', { arrangement: { group: hours, weight: 2 } }) },
    closes: { title: 'Closes at', type: 'string', 'x-surface': x('text', { arrangement: { group: hours, weight: 1 } }) },
    rate: {
      title: 'Hourly rate',
      description: 'What an hour costs.',
      type: ['integer', 'null'],
      examples: [40],
      'x-surface': x('number', { arrangement: { group: pricing, placeholder: 'For example, 40', prefix: '$', suffix: 'per hour', help: 'Whole dollars.' } }),
    },
    deposit: { title: 'Deposit asked', type: 'boolean', 'x-surface': x('checkbox', { arrangement: { group: pricing, weight: -1 } }) },
    notes: { title: 'Notes', type: ['string', 'null'], 'x-surface': x('textarea', { arrangement: { weight: -10 } }) },
  });

  it('draws each group where its first key is declared, its keys inside it, each by its weight', () => {
    const { container } = render(<Form schema={studio} initial={{ name: '', opens: '09:00', closes: '17:00', rate: null, deposit: false, notes: null }} />);
    // The frame: the notes weighed first, then the name, then each group
    // where its first key is declared.
    const drawn = Array.from(container.querySelectorAll('form > *')).map((element) => element.querySelector(':scope > legend, :scope > summary, :scope > label')?.textContent ?? element.tagName);
    expect(drawn).toEqual(['Notes', 'Studio name', 'Opening hours', 'Pricing']);

    const fieldset = screen.getByRole('group', { name: 'Opening hours' });
    expect(fieldset.tagName).toBe('FIELDSET');
    // Inside its group, the closing time is weighed before the opening time.
    expect(within(fieldset).getAllByRole('textbox').map((input) => input.getAttribute('name'))).toEqual(['closes', 'opens']);

    const details = container.querySelector('details') as HTMLDetailsElement;
    expect(details.querySelector('summary')).toHaveTextContent('Pricing');
    // It starts closed, as declared, and holds its keys, the deposit
    // weighed first.
    expect(details.open).toBe(false);
    expect(Array.from(details.querySelectorAll('input')).map((input) => input.getAttribute('name'))).toEqual(['deposit', 'rate']);
  });

  it('draws the placeholder, the prefix, the suffix and the help it declares', () => {
    render(<Form schema={studio} initial={{ name: '', opens: '09:00', closes: '17:00', rate: null, deposit: false, notes: null }} />);
    const rate = screen.getByLabelText('Hourly rate');
    expect(rate).toHaveAttribute('placeholder', 'For example, 40');
    expect(rate.previousElementSibling).toHaveTextContent('$');
    expect(rate.nextElementSibling).toHaveTextContent('per hour');
    // The help after the description, read with the field.
    expect(rate).toHaveAccessibleDescription('What an hour costs. Whole dollars.');
    const name = screen.getByLabelText('Studio name');
    expect(name).toHaveAttribute('placeholder', 'Riverside studio');
    expect(name).toHaveAccessibleDescription('Shown on the door.');
    // Only drawing: every key keeps its own path.
    expect(screen.getByLabelText('Deposit asked')).toHaveAttribute('name', 'deposit');
  });

  it('draws a fieldset placed after a grouped key inside that group, right after it, as the form does', async () => {
    const booking: Schema = {
      title: 'Booking',
      type: 'object',
      properties: { booking_fee: { title: 'Booking fee', type: ['integer', 'null'], 'x-surface': x('number') } },
      'x-surface': x('fieldset', { after: 'deposit' }),
    };
    const seen: Values[] = [];
    const { container } = render(
      <Form
        schema={object({ ...studio.properties, third_party_settings: { type: 'object', properties: { data_surface_resource_test: booking }, 'x-surface': x('fieldset', { group: true }) } })}
        initial={{ name: '', opens: '09:00', closes: '17:00', rate: null, deposit: false, notes: null, third_party_settings: { data_surface_resource_test: { booking_fee: 0 } } }}
        onValues={(values) => seen.push(values)}
      />,
    );
    const details = container.querySelector('details') as HTMLDetailsElement;
    // Inside the pricing group, after the deposit and before the rate:
    // ArrangementFormTest holds the Form API page to the same order.
    expect(Array.from(details.querySelectorAll('input')).map((input) => input.getAttribute('name'))).toEqual(['deposit', 'third_party_settings.data_surface_resource_test.booking_fee', 'rate']);
    expect(within(details).getByRole('group', { name: 'Booking' })).toContainElement(screen.getByLabelText('Booking fee'));
    // Posted under its mount, where it is stored.
    await userEvent.clear(screen.getByLabelText('Booking fee'));
    await userEvent.type(screen.getByLabelText('Booking fee'), '15');
    expect(seen[seen.length - 1].third_party_settings).toEqual({ data_surface_resource_test: { booking_fee: 15 } });
  });
});

describe('a select standing for a stale value', () => {
  it('says on its empty option that it keeps the value, and keeps the marker without asking the browser', () => {
    render(
      <Form
        schema={object(
          {
            room: {
              title: 'Room',
              type: 'string',
              oneOf: [{ const: 'main', title: 'Main hall' }],
              'x-surface': x('select', { stale: true, emptyOption: { show: true, label: '- Keep annex (no longer offered) -' } }),
            },
          },
          ['room'],
        )}
        initial={{ room: null }}
      />,
    );
    const select = screen.getByLabelText(/Room/);
    expect(select).toHaveValue('');
    expect(within(select).getAllByRole('option')[0]).toHaveTextContent('- Keep annex (no longer offered) -');
    // Empty there means keep, so the browser is not asked to refuse it,
    // and the label still says the key is required.
    expect(select).not.toBeRequired();
    expect(screen.getByText('*')).toBeInTheDocument();
  });
});

describe('a value drawn fixed', () => {
  it('draws the one value a deciding key may hold as its label, with no select and no input', () => {
    const { container } = render(
      <Form
        schema={object(
          {
            source: {
              title: 'Credential source',
              type: 'string',
              const: 'pasted',
              default: 'pasted',
              'x-surface': x('fixed', { fixed: 'Pasted here' }),
            },
          },
          ['source'],
        )}
        initial={{ source: 'pasted' }}
      />,
    );
    expect(screen.getByText('Credential source')).toBeInTheDocument();
    expect(screen.getByText('Pasted here')).toBeInTheDocument();
    expect(container.querySelector('select')).toBeNull();
    expect(container.querySelector('input')).toBeNull();
  });
});

describe('a key that refers to an entity (x-surface.widget: autocomplete)', () => {
  const reference = { entityType: 'event_venue' };

  it('draws the listed candidates as a select, as the form does up to the limit', () => {
    render(
      <Form
        schema={object({ venue: { title: 'Venue', type: 'string', oneOf: venues, 'x-surface': x('autocomplete', { reference }) } })}
        initial={{ venue: 'library' }}
      />,
    );
    expect(screen.getByRole('combobox', { name: 'Venue' }).tagName).toBe('SELECT');
    expect(screen.getByRole('option', { name: 'Old Library' })).toBeInTheDocument();
  });

  it('past the limit, looks the typed text up and takes the id of the candidate chosen', async () => {
    const asked: [string, string, string | undefined][] = [];
    const lookup: Lookup = async (path, text, type) => {
      asked.push([path, text, type]);
      return [{ id: 'riverside', label: 'Riverside Hall' }];
    };
    let last: Values = {};
    render(
      <LookupContext.Provider value={lookup}>
        <Form
          schema={object({ venue: { title: 'Venue', type: 'string', 'x-surface': x('autocomplete', { reference }) } })}
          initial={{ venue: null }}
          onValues={(values) => {
            last = values;
          }}
        />
      </LookupContext.Provider>,
    );
    const input = screen.getByRole('combobox', { name: 'Venue' });
    expect(input.tagName).toBe('INPUT');
    await userEvent.type(input, 'r');
    await userEvent.click(await screen.findByRole('button', { name: 'Riverside Hall (riverside)' }));
    expect(asked[0]).toEqual(['venue', 'r', undefined]);
    expect(last).toEqual({ venue: 'riverside' });
  });

  it("looks a bare reference's id up of the entity type its sibling holds", async () => {
    const asked: [string, string, string | undefined][] = [];
    const lookup: Lookup = async (path, text, type) => {
      asked.push([path, text, type]);
      return [];
    };
    render(
      <LookupContext.Provider value={lookup}>
        <Form
          schema={object({
            thing: {
              ...object({
                entity_type: { title: 'Entity type', type: 'string', 'x-surface': x('text') },
                id: { title: 'ID', type: 'string', 'x-surface': x('autocomplete', { reference: { by: 'entity_type' } }) },
              }),
              title: 'Featured beside',
              'x-surface': x('fieldset'),
            },
          })}
          initial={{ thing: { entity_type: 'node', id: null } }}
        />
      </LookupContext.Provider>,
    );
    await userEvent.type(screen.getByRole('combobox', { name: 'ID' }), '5');
    expect(asked[0]).toEqual(['thing.id', '5', 'node']);
  });
});
