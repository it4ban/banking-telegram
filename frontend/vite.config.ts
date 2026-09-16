import vue from '@vitejs/plugin-vue';
import { defineConfig } from 'vite';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
	plugins: [vue(), tailwindcss()],
	server: {
		host: true,
		port: 5173,
		strictPort: true,
		watch: {
			usePolling: true,
			interval: 100,
		},
		hmr: {
			host: 'localhost',
			clientPort: 5173,
		},
	},
});
