import type { Contract, Created, Schema, Submission, Values, Violation } from './contract';
import { assertContract, assertSchema, CONTRACT, ContractRefused } from './contract';

/**
 * Whether a write sends the ETag the contract was loaded with.
 *
 * The server checks it only when it is sent, as `If-Match`, so the app
 * opts in: a submit after someone else saved is refused rather than
 * overwriting them. `settings.sendFingerprint: false` turns it off for
 * one page.
 */
export const SEND_FINGERPRINT = true;

/**
 * Where the operation is on the API, as the page wrote it.
 *
 * Each URL carries the base path, the identity in the path and any other
 * operation parameter in the query string: the app adds nothing to them
 * but `dryRun=true`.
 */
export interface Endpoints {
  /** The envelope: GET. */
  read: string;
  /** The full schema: GET; a narrowing: POST. */
  schema: string;
  /** A run of the operation. */
  write: { method: 'PATCH' | 'POST'; url: string };
  /**
   * The lookup of the path's reference keys: GET, `?key=&q=`; null for a
   * page whose operation GET does not serve, which has none.
   */
  lookup?: string | null;
}

/** One candidate a lookup answers. */
export interface Candidate {
  id: string | number;
  label: string;
}

/** What the page hands the app in drupalSettings.dataSurfaceReact. */
export interface Settings {
  apiBase: string;
  /** Where the React pages are, for moving to a created thing's page. */
  pageBase?: string;
  resource: string;
  operation: string;
  parameters?: Record<string, string | number | boolean>;
  endpoints: Endpoints;
  tokenUrl: string;
  mount?: string;
  /** Overrides SEND_FINGERPRINT. */
  sendFingerprint?: boolean;
}

export type Fetcher = (input: string, init?: RequestInit) => Promise<Response>;

/** An answer and the ETag it was served with, as received; null when it has none. */
export interface Tagged<T> {
  document: T;
  etag: string | null;
}

export interface Api {
  /** The envelope, and the ETag of what is stored. */
  contract(): Promise<Tagged<Contract>>;
  /** The full schema, which reads no value: fetched once, beside the contract. */
  schema(): Promise<Schema>;
  /**
   * The full schema with choices of deferred keys stated, at the URL their
   * filled `fetch` templates name: fetched once per URL for the page's
   * life, since it reads no value either.
   */
  chosen(url: string): Promise<Schema>;
  /** The envelope narrowed for these values. */
  narrow(values: Values, stale: string[]): Promise<Contract>;
  /** A dry run of the operation: nothing is written. */
  validate(values: Values, stale: string[], etag: string | null): Promise<Submission>;
  /** A run of the operation, and the ETag of what it stored. */
  submit(values: Values, stale: string[], etag: string | null): Promise<Tagged<Submission>>;
  /** The candidates of one reference key whose labels contain the text. */
  lookup(path: string, text: string, type?: string): Promise<Candidate[]>;
}

/**
 * Where a page's lookup is: the one the page names, or the read path's.
 *
 * The read path with any `:{operation}` and query taken off, then
 * `:lookup`: the custom read every resource path answers.
 */
export function lookupOf(endpoints: Endpoints): string | null {
  if (endpoints.lookup !== undefined) {
    return endpoints.lookup;
  }
  const path = endpoints.read.split('?')[0];
  const slash = path.lastIndexOf('/');
  const colon = path.indexOf(':', slash);
  return `${colon === -1 ? path : path.slice(0, colon)}:lookup`;
}

/** The React page a created thing now lives at. */
export function pageOf(settings: Settings, created: Created): string {
  const base = settings.pageBase ?? settings.apiBase.replace(/\/surface-api$/, '/surface-react');
  const query = new URLSearchParams(Object.entries(created.parameters).map(([key, value]) => [key, String(value)])).toString();
  return `${base}/${encodeURIComponent(created.resource)}/${encodeURIComponent(created.operation)}${query === '' ? '' : `?${query}`}`;
}

/** The response header every answer of the API states its contract version in. */
export const CONTRACT_HEADER = 'Contract-Version';

/** The request header naming the stale paths a request leaves empty. */
export const STALE_HEADER = 'X-Surface-Stale';

/** The query member that makes a write a dry run. */
export const DRY_RUN = 'dryRun';

/**
 * Reads a JSON Pointer (RFC 6901) as the dotted path the app holds.
 *
 * `/ticket/price` is `ticket.price`; `~1` is read as `/` and `~0` as `~`;
 * the empty pointer, the whole call, is the empty path. A path that does
 * not start with `/` is dotted already.
 */
export function pointerToDotted(pointer: string): string {
  if (pointer === '' || !pointer.startsWith('/')) {
    return pointer;
  }
  return pointer
    .slice(1)
    .split('/')
    .map((segment) => segment.replace(/~1/g, '/').replace(/~0/g, '~'))
    .join('.');
}

/** Writes a dotted path as a JSON Pointer: `~` as `~0`, `/` as `~1`. */
export function dottedToPointer(dotted: string): string {
  if (dotted === '') {
    return '';
  }
  return `/${dotted
    .split('.')
    .map((segment) => segment.replace(/~/g, '~0').replace(/\//g, '~1'))
    .join('/')}`;
}

const dottedEntry = <T extends Violation>(entry: T): T => ({ ...entry, path: pointerToDotted(entry.path) });

/** An envelope with its paths dotted: `stale`, `discarded`, `notes`. */
function dottedContract(contract: Contract): Contract {
  const read: Contract = { ...contract, stale: (contract.stale ?? []).map(pointerToDotted) };
  if (contract.notes !== undefined) {
    read.notes = contract.notes.map(dottedEntry);
  }
  if (contract.discarded !== undefined) {
    read.discarded = contract.discarded.map(pointerToDotted);
  }
  return read;
}

/** A run's result with its paths dotted, its contract's too. */
function dottedSubmission(submission: Submission): Submission {
  return {
    ...submission,
    changes: (submission.changes ?? []).map(pointerToDotted),
    violations: (submission.violations ?? []).map(dottedEntry),
    notes: (submission.notes ?? []).map(dottedEntry),
    stale: (submission.stale ?? []).map(dottedEntry),
    outputs: submission.outputs ?? {},
    contract: (submission.contract ?? null) === null ? null : dottedContract(submission.contract as Contract),
    created: submission.created ?? null,
  };
}

/** The problem details (RFC 9457) a refused run is answered with. */
interface Problem {
  type?: string;
  title?: string;
  status?: number;
  detail?: string;
  errors?: Violation[];
  notes?: Violation[];
  stale?: Submission['stale'];
}

/**
 * The statuses a refusal of the values is answered with: 422 for a value
 * refused, 412 when the stored values changed since the ETag sent, and
 * 403 when the problem names what access refused.
 */
const REFUSALS = [403, 412, 422];

/**
 * Refuses an answer whose header names a contract this app does not read.
 *
 * A missing header is no refusal by itself: the document it carries is
 * checked all the same.
 */
function assertHeader(response: Response): void {
  const version = response.headers.get(CONTRACT_HEADER);
  if (version !== null && version.trim() !== String(CONTRACT)) {
    throw new ContractRefused(version.trim());
  }
}

/** The URL with `name=value` added to its query string. */
function withQuery(url: string, name: string, value: string): string {
  const hash = url.indexOf('#');
  const [path, fragment] = hash === -1 ? [url, ''] : [url.slice(0, hash), url.slice(hash)];
  return `${path}${path.includes('?') ? '&' : '?'}${encodeURIComponent(name)}=${encodeURIComponent(value)}${fragment}`;
}

/** The problem document a response carries, or null when it carries none. */
async function problemOf(response: Response): Promise<Problem | null> {
  try {
    const body: unknown = await response.clone().json();
    return typeof body === 'object' && body !== null ? (body as Problem) : null;
  }
  catch {
    return null;
  }
}

/** Throws for an answer that is no success: its status, and the problem's detail when it says one. */
async function fail(response: Response): Promise<never> {
  const detail = (await problemOf(response))?.detail ?? '';
  throw new Error(`The server answered ${response.status} ${response.statusText}`.trim() + (detail === '' ? '' : `: ${detail}`));
}

/**
 * The operation on the API, at the endpoints the page names.
 *
 * Four calls: GET the envelope, GET the full schema, POST values to the
 * schema for the envelope narrowed for them, and a write with the
 * method the page names, `?dryRun=true` for one that writes nothing. A
 * POST or PATCH carries the session's CSRF token, fetched once from
 * /session/token, and the stale paths it leaves empty in
 * `X-Surface-Stale`; a write carries the ETag the values were read with
 * as `If-Match`, when it is given one. The API writes every path as a
 * JSON Pointer, and the app holds them dotted: they are converted here,
 * both ways. A refusal of the values (422, 412, or a 403 that names
 * what was refused) is problem details, read back into the result shape
 * a run answers; any other status that is not a success is thrown. Every
 * answer is refused unless it is of contract 3, by its
 * `Contract-Version` header, and every document it carries by the
 * document.
 */
export function createApi(settings: Settings, fetcher: Fetcher): Api {
  const { endpoints } = settings;
  let token: Promise<string> | null = null;
  // The full schema for each set of choices, by URL, for the page's life.
  const chosen = new Map<string, Promise<Schema>>();

  const read = async <T>(response: Response): Promise<T> => {
    assertHeader(response);
    if (!response.ok) {
      return fail(response);
    }
    return (await response.json()) as T;
  };

  const get = (url: string, accept: string): Promise<Response> => fetcher(url, { credentials: 'same-origin', headers: { Accept: accept } });

  const send = async (method: string, url: string, type: string, values: Values, stale: string[], more: Record<string, string> = {}): Promise<Response> => {
    token ??= fetcher(settings.tokenUrl, { credentials: 'same-origin' }).then((response) => response.text());
    const headers: Record<string, string> = {
      'Content-Type': type,
      Accept: 'application/json',
      'X-CSRF-Token': await token,
      ...more,
    };
    if (stale.length > 0) {
      headers[STALE_HEADER] = stale.map(dottedToPointer).join(',');
    }
    return fetcher(url, { method, credentials: 'same-origin', headers, body: JSON.stringify(values) });
  };

  const run = async (values: Values, stale: string[], etag: string | null, dryRun: boolean): Promise<Tagged<Submission>> => {
    const { method, url } = endpoints.write;
    const response = await send(
      method,
      dryRun ? withQuery(url, DRY_RUN, 'true') : url,
      method === 'PATCH' ? 'application/merge-patch+json' : 'application/json',
      values,
      stale,
      etag === null ? {} : { 'If-Match': etag },
    );
    assertHeader(response);
    if (!response.ok) {
      const problem = REFUSALS.includes(response.status) ? await problemOf(response) : null;
      if (problem === null || !Array.isArray(problem.errors)) {
        return fail(response);
      }
      // Refused: nothing was written, and the result says what was refused.
      const refused: Submission = {
        ok: false,
        committed: false,
        changed: false,
        changes: [],
        values: {},
        violations: problem.errors,
        notes: problem.notes ?? [],
        stale: problem.stale ?? [],
        outputs: {},
        contract: null,
        created: null,
      };
      return { document: dottedSubmission(refused), etag: null };
    }
    const submission = (await response.json()) as Submission;
    // A write carries the contract it rebuilt.
    if ((submission.contract ?? null) !== null) {
      assertContract(submission.contract as Contract);
    }
    return { document: dottedSubmission(submission), etag: response.headers.get('ETag') };
  };

  return {
    contract: async () => {
      const response = await get(endpoints.read, 'application/json');
      const contract = assertContract(await read<Contract>(response));
      return { document: dottedContract(contract), etag: response.headers.get('ETag') };
    },
    schema: async () => assertSchema(await read<Schema>(await get(endpoints.schema, 'application/schema+json, application/json'))),
    chosen: (url) => {
      let answer = chosen.get(url);
      if (answer === undefined) {
        answer = get(url, 'application/schema+json, application/json').then(async (response) => assertSchema(await read<Schema>(response)));
        // A failure is not kept: the next choice asks again.
        answer.catch(() => chosen.delete(url));
        chosen.set(url, answer);
      }
      return answer;
    },
    narrow: async (values, stale) => dottedContract(assertContract(await read<Contract>(await send('POST', endpoints.schema, 'application/json', values, stale)))),
    validate: async (values, stale, etag) => (await run(values, stale, etag, true)).document,
    submit: async (values, stale, etag) => run(values, stale, etag, false),
    lookup: async (path, text, type) => {
      const at = lookupOf(endpoints);
      if (at === null) {
        return [];
      }
      let url = withQuery(withQuery(at, 'key', dottedToPointer(path)), 'q', text);
      if (type !== undefined) {
        url = withQuery(url, 'type', type);
      }
      const answer = await read<{ items?: Candidate[] }>(await get(url, 'application/json'));
      return Array.isArray(answer.items) ? answer.items : [];
    },
  };
}
