import { cleanup, configure } from '@testing-library/react';
import { afterEach } from 'vitest';

// The app marks elements for tests with `data-test` (the starter kit's
// convention), not Testing Library's default `data-testid`.
configure({ testIdAttribute: 'data-test' });

// Testing Library only cleans up automatically when test globals are on; they
// are off here, so unmount rendered components after every test.
afterEach(() => {
    cleanup();
});

// Recharts measures its container with ResizeObserver, which jsdom does not have.
// In jsdom everything measures zero, so charts draw no marks, but they mount.
class ResizeObserverStub {
    observe() {}
    unobserve() {}
    disconnect() {}
}

globalThis.ResizeObserver ??= ResizeObserverStub;

// jsdom has no matchMedia; the theme hook asks it whether the system is dark.
// Here the system is always light.
window.matchMedia ??= (query: string) =>
    ({
        matches: false,
        media: query,
        onchange: null,
        addEventListener: () => {},
        removeEventListener: () => {},
        addListener: () => {},
        removeListener: () => {},
        dispatchEvent: () => false,
    }) as MediaQueryList;
