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
