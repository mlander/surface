import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { useState } from 'react';
import type { Extension, Schema, Values } from '../contract';
import { setAt } from '../contract';
import { ContractPanel } from '../ContractPanel';
import { Properties } from '../widgets/Field';

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
          { headline: { title: 'Headline', description: 'Shown above.', type: 'string', maxLength: 50, examples: ['Quarterly report'], 'x-surface': x('text') } },
          ['headline'],
        )}
        initial={{ headline: 'Featured' }}
      />,
    );
    const input = screen.getByLabelText(/Headline/);
    expect(input).toHaveAttribute('type', 'text');
    expect(input).toHaveAttribute('maxlength', '50');
    expect(input).toHaveAttribute('placeholder', 'Quarterly report');
    expect(input).toBeRequired();
    expect(input).toHaveValue('Featured');
    expect(input).toHaveAccessibleDescription('Shown above.');
    expect(screen.getByText('*')).toBeInTheDocument();
  });

  it('never puts a Regex on the input as a bare pattern: the server says what is wrong, in its message', () => {
    const message = 'A licence number is four digits, such as 2048.';
    render(
      <Form
        schema={object({
          licence: {
            title: 'Event licence',
            type: 'string',
            pattern: '^\\d{4}$',
            examples: ['2048'],
            'x-surface': x('text', { patternMessage: message }),
          },
        })}
        initial={{ licence: 'EV-2048' }}
      />,
    );
    const input = screen.getByLabelText(/Event licence/);
    // A bare pattern makes the browser ask for "the requested format",
    // which is vaguer than the message the server answers with.
    expect(input).not.toHaveAttribute('pattern');
    expect(input).toHaveAttribute('placeholder', '2048');
  });

  it('renders a textarea with neither maxlength nor placeholder', () => {
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
          email: { title: 'Email', type: 'string', format: 'email', 'x-surface': x('email') },
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
});

describe('fieldset, slot and list', () => {
  const ticketSlot: Schema = { title: 'Ticket', type: 'object', 'x-surface': x('slot', { by: 'pricing', variants: ['free', 'paid'], dependsOn: ['pricing'] }) };
  const branches: Schema['allOf'] = [
    {
      if: { properties: { pricing: { const: 'free' } }, required: ['pricing'] },
      then: { properties: { ticket: { title: 'Ticket', type: 'object', properties: { note: { title: 'Note', type: ['string', 'null'], 'x-surface': x('text') } }, 'x-surface': x('fieldset') } } },
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
            'x-surface': x('fieldset'),
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
        schema={object({ contact: { title: 'Contact', type: 'object', required: ['email'], properties: { email: { title: 'Email', type: 'string', format: 'email', 'x-surface': x('email') } }, 'x-surface': x('fieldset') } })}
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
      properties: { licence: { title: 'Event licence', type: ['string', 'null'], 'x-surface': x('text') } },
      'x-surface': x('fieldset'),
    };
    render(
      <Form
        schema={object({ third_party_settings: { type: 'object', properties: { compliance }, 'x-surface': x('fieldset', { group: true }) } })}
        initial={{ third_party_settings: { compliance: { licence: '2048' } } }}
      />,
    );
    const groups = screen.getAllByRole('group');
    expect(groups.map((group) => group.querySelector('legend')?.textContent)).toEqual(['Compliance']);
    expect(within(groups[0]).getByLabelText('Event licence')).toHaveValue('2048');
  });

  it('renders the variant the deciding key chose, and switches with it', async () => {
    render(<Form schema={object({ pricing, ticket: ticketSlot }, [], branches)} initial={{ pricing: 'free', ticket: { note: 'Donations welcome' } }} />);
    expect(screen.getByRole('group', { name: 'Ticket' })).toBeInTheDocument();
    expect(screen.getByLabelText('Note')).toHaveValue('Donations welcome');
    await userEvent.selectOptions(screen.getByLabelText('Pricing'), 'paid');
    expect(screen.queryByLabelText('Note')).not.toBeInTheDocument();
    expect(screen.getByLabelText(/Price/)).toBeRequired();
  });

  it('renders nothing for a slot nothing has chosen', () => {
    render(<Form schema={object({ pricing, ticket: ticketSlot }, [], branches)} initial={{ pricing: null, ticket: null }} />);
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

describe('the contract panel', () => {
  it('explains a Regex by its message, and only a Regex without one by its pattern, in code', () => {
    const message = 'A licence number is four digits, such as 2048.';
    render(
      <ContractPanel
        contract={{
          surface: 'example',
          situation: 'configure',
          label: null,
          stale: [],
          values: {},
          schema: object({
            licence: { title: 'Event licence', type: 'string', pattern: '^\\d{4}$', 'x-surface': x('text', { patternMessage: message }) },
            code: { title: 'Code', type: 'string', pattern: '^[a-z]+$', 'x-surface': x('text') },
          }),
        }}
        values={{}}
      />,
    );
    const licence = document.querySelector('tr[data-surface-key="licence"]') as HTMLElement;
    expect(within(licence).getByText(message)).toBeInTheDocument();
    expect(licence.textContent).not.toContain('\\d{4}');
    const code = document.querySelector('tr[data-surface-key="code"]') as HTMLElement;
    expect(code.textContent).toContain('matches a required format: ^[a-z]+$');
    expect(within(code).getByText('^[a-z]+$').tagName).toBe('CODE');
  });
});
