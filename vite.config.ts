import { defineConfig } from 'vite';
import nette from '@nette/vite-plugin';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
	plugins: [
		nette({
			entry: 'main.js',
		}),
		tailwindcss(),
	],

	build: {
		emptyOutDir: true,
	},

	css: {
		devSourcemap: true,
	},
});
