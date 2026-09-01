import { describe, expect, it } from 'vitest';
import { cycleSelection, toggleRef } from './selection';

const ACTING = ['TBC331', 'TBC060', 'TBC325', 'TBC197', 'TBC391'];

describe('cycleSelection', () => {
    it('selects the whole acting set when nothing is selected', () => {
        const next = cycleSelection([], ACTING, false);

        expect(next.selected.sort()).toEqual([...ACTING].sort());
        expect(next.inverted).toBe(false);
    });

    it('clears the acting set when all of it is selected', () => {
        const next = cycleSelection([...ACTING], ACTING, false);

        expect(next.selected).toEqual([]);
        expect(next.inverted).toBe(false);
    });

    it('inverts a partial selection and flags the invert', () => {
        const next = cycleSelection(['TBC331', 'TBC197'], ACTING, false);

        expect(next.selected.sort()).toEqual(['TBC060', 'TBC325', 'TBC391'].sort());
        expect(next.inverted).toBe(true);
    });

    it('clears a partial selection on the click after an invert', () => {
        const inverted = cycleSelection(['TBC331'], ACTING, false);
        const next = cycleSelection(inverted.selected, ACTING, inverted.inverted);

        expect(next.selected).toEqual([]);
        expect(next.inverted).toBe(false);
    });

    it('acts only on the acting set, leaving outside selections alone', () => {
        // TBC331 is outside the (filtered) acting set; TBC060 inside it.
        const next = cycleSelection(['TBC331', 'TBC060'], ['TBC060', 'TBC197'], false);

        expect(next.selected.sort()).toEqual(['TBC331', 'TBC197'].sort());
        expect(next.inverted).toBe(true);
    });

    it('unselects within the acting set when it is fully selected', () => {
        const next = cycleSelection(['TBC060', 'TBC197'], ['TBC060', 'TBC197'], false);

        expect(next.selected).toEqual([]);
    });

    it('does nothing for an empty acting set', () => {
        const next = cycleSelection(['TBC331'], [], false);

        expect(next.selected).toEqual(['TBC331']);
        expect(next.inverted).toBe(false);
    });
});

describe('toggleRef', () => {
    it('adds an unselected ref', () => {
        expect(toggleRef(['TBC331'], 'TBC060').sort()).toEqual(['TBC060', 'TBC331']);
    });

    it('removes a selected ref', () => {
        expect(toggleRef(['TBC331', 'TBC060'], 'TBC331')).toEqual(['TBC060']);
    });
});
