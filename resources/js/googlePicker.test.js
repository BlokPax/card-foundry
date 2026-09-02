import { describe, expect, it } from 'vitest';
import { pickedSheet } from './googlePicker';

const google = { picker: { Action: { PICKED: 'picked', CANCEL: 'cancel' } } };

describe('pickedSheet', () => {
    it('extracts the id and name of the picked document', () => {
        const data = { action: 'picked', docs: [{ id: 'abc-123', name: 'TBC Handoff' }] };

        expect(pickedSheet(data, google)).toEqual({ id: 'abc-123', name: 'TBC Handoff' });
    });

    it('tolerates a missing name', () => {
        expect(pickedSheet({ action: 'picked', docs: [{ id: 'x' }] }, google)).toEqual({ id: 'x', name: '' });
    });

    it('ignores cancel and empty picks', () => {
        expect(pickedSheet({ action: 'cancel' }, google)).toBeNull();
        expect(pickedSheet({ action: 'picked', docs: [] }, google)).toBeNull();
    });
});
