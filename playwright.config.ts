import { defineConfig, devices } from '@playwright/test';

export default defineConfig({
	testDir: './tests/e2e',
	fullyParallel: false,
	forbidOnly: !! process.env.CI,
	retries: process.env.CI ? 1 : 0,
	workers: 1,
	reporter: 'html',

	use: {
		baseURL: process.env.AXELLCORE_BASE_URL ?? 'http://localhost:8880',
		trace: 'on-first-retry',
		screenshot: 'only-on-failure',
	},

	projects: [
		{
			name: 'setup',
			testMatch: '**/auth.setup.ts',
		},
		{
			name: 'chromium',
			use: { ...devices['Desktop Chrome'] },
			dependencies: [ 'setup' ],
		},
	],
});
