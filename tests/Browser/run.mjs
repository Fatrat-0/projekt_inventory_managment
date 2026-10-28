import { spawn } from 'node:child_process';
import { createServer } from 'node:net';
import { createRequire } from 'node:module';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { setTimeout as pause } from 'node:timers/promises';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const require = createRequire(import.meta.url);
const cli = resolve(dirname(require.resolve('@playwright/test/package.json')), 'cli.js');
const waitExit = child => new Promise((resolve, reject) => {
    child.once('error', reject);
    child.once('exit', code => resolve(code));
});
let server;
try {
    // Refuse to reuse any pre-existing application on the test port.
    await new Promise((resolve, reject) => {
        const probe = createServer();
        probe.once('error', reject);
        probe.listen(8019, '127.0.0.1', () => probe.close(resolve));
    });
    const prepared = await waitExit(spawn('php', ['tests/Browser/prepare.php'], { cwd: root, stdio: 'inherit', windowsHide: true }));
    if (prepared !== 0) throw new Error('Could not prepare the isolated browser database.');
    let serverError;
    let log = '';
    server = spawn('php', ['-S', '127.0.0.1:8019', '-t', 'public', 'tests/Browser/router.php'], { cwd: root, stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
    server.once('error', error => { serverError = error; });
    server.stderr.on('data', data => { log = (log + data.toString()).slice(-4000); });
    const deadline = Date.now() + 30000;
    let ready = false;
    while (Date.now() < deadline) {
        if (serverError || server.exitCode !== null) throw serverError || new Error(log);
        try {
            const response = await fetch('http://127.0.0.1:8019/login', { signal: AbortSignal.timeout(1000) });
            if (response.ok) { ready = true; break; }
        } catch {}
        await pause(100);
    }
    if (!ready) throw new Error('Browser test server did not start. '+log);
    process.exitCode = await waitExit(spawn(process.execPath, [cli, 'test', ...process.argv.slice(2)], { cwd: root, stdio: 'inherit', windowsHide: true }));
} catch (error) {
    console.error(error.message);
    process.exitCode = 1;
} finally {
    if (server && server.exitCode === null) {
        const stopped = waitExit(server);
        server.kill();
        await stopped;
    }
}
