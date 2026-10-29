import { getToken } from './token.js';

const BASE_URL = import.meta.env.VITE_API_URL;

export class ApiError extends Error {
  constructor(status, { code = 'server_error', message = 'Unknown error', fields } = {}) {
    super(message);
    this.status = status;
    this.code = code;
    this.fields = fields;
  }
}

export async function request(path, { method = 'GET', query, body, auth = false } = {}) {
  const url = new URL(BASE_URL + path, location.origin);
  for (const [key, value] of Object.entries(query ?? {})) {
    if (value !== undefined && value !== null && value !== '') url.searchParams.set(key, value);
  }

  const headers = { Accept: 'application/json' };
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  if (auth) {
    const token = getToken();
    if (token) headers.Authorization = `Bearer ${token}`;
  }

  let response;
  try {
    response = await fetch(url, {
      method,
      headers,
      body: body !== undefined ? JSON.stringify(body) : undefined,
    });
  } catch {
    throw new ApiError(0, { code: 'network_error', message: 'Network error' });
  }

  if (response.status === 204) return null;

  const data = await response.json().catch(() => null);
  if (!response.ok) throw new ApiError(response.status, data?.error);
  return data;
}
