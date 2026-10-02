import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Testing Library only cleans up automatically when test globals are on; they
// are off here, so unmount rendered components after every test.
afterEach(() => {
    cleanup();
});
