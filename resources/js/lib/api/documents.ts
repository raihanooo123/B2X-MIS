/**
 * 05.17 §4 — trade documents: preparing a PDF (queue, poll, retry) and
 * generating a statement. Only the explicit POSTs queue a render; polling
 * is a GET and never does. Branch on `ApiError.code`, never the message.
 */
import { apiRequest } from './client';

export type DocumentType = 'invoice' | 'credit_note' | 'statement';
export type RenderStatus = 'none' | 'pending' | 'rendering' | 'ready' | 'failed';

/** A document's PDF state, as the source page and the API both return it. */
export interface DocumentState {
    id: string | null;
    status: RenderStatus;
    message: string | null;
    download_url: string | null;
}

export function prepareDocument(type: DocumentType, source: string): Promise<{ data: DocumentState }> {
    return apiRequest('/document-renders', { method: 'POST', body: { type, source } });
}

export function documentStatus(id: string, signal?: AbortSignal): Promise<{ data: DocumentState }> {
    return apiRequest(`/document-renders/${id}`, { signal });
}

export function retryDocument(id: string): Promise<{ data: DocumentState }> {
    return apiRequest(`/document-renders/${id}/retry`, { method: 'POST' });
}

export function generateStatement(fromOn: string, toOn: string): Promise<{ data: { id: string; url: string; document: DocumentState } }> {
    return apiRequest('/trade/statements', { method: 'POST', body: { from_on: fromOn, to_on: toOn } });
}
