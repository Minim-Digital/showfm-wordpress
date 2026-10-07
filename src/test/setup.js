/**
 * Test setup: DOM matchers, browser APIs jsdom lacks, and cleanup between tests.
 */
import '@testing-library/jest-dom/vitest';
import { cleanup } from '@testing-library/react';
import { afterEach } from 'vitest';

// Placeholder measures itself; jsdom has no ResizeObserver.
globalThis.ResizeObserver ??= class {
	observe() {}
	unobserve() {}
	disconnect() {}
};

// ComboboxControl scrolls the selected suggestion into view; jsdom does not lay out.
globalThis.Element.prototype.scrollIntoView ??= function () {};

afterEach( cleanup );
