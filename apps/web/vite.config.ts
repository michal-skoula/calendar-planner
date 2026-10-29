import path from "node:path";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { defineConfig } from "vite";

// In development the browser only ever talks to this server, which forwards
// the backend's paths to `php artisan serve`. That keeps the page and the API
// on one origin, exactly as in production, so the session cookie works without
// CORS.
const api = process.env.PLANNER_API_URL ?? "http://localhost:8100";

export default defineConfig({
    plugins: [react(), tailwindcss()],
    resolve: {
        alias: { "@": path.resolve(__dirname, "./src") },
    },
    server: {
        port: 5180,
        strictPort: true,
        proxy: {
            "/api": api,
            "/sanctum": api,
        },
    },
});
