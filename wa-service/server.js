const { Client, LocalAuth } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const http   = require('http');
const path   = require('path');

let clientReady = false;
let readyPoller = null;

function startReadyPoller() {
    if (readyPoller) return;
    readyPoller = setInterval(async () => {
        if (clientReady) { clearInterval(readyPoller); readyPoller = null; return; }
        try {
            const state = await client.getState();
            if (state === 'CONNECTED') {
                clearInterval(readyPoller);
                readyPoller = null;
                clientReady = true;
                console.log('\n🟢 WhatsApp listo\n');
            }
        } catch (e) { /* aún no listo */ }
    }, 4000);
    setTimeout(() => { if (readyPoller) { clearInterval(readyPoller); readyPoller = null; } }, 300000);
}

const client = new Client({
    authStrategy: new LocalAuth({ dataPath: path.join(__dirname, 'wa_session') }),
    webVersion: '2.3000.1039772387-alpha',
    webVersionCache: {
        type: 'local',
        path: path.join(__dirname, 'waweb.html'),
    },
    puppeteer: {
        args: [
            '--no-sandbox',
            '--disable-setuid-sandbox',
            '--disable-dev-shm-usage',
            '--disable-accelerated-2d-canvas',
            '--no-first-run',
            '--no-zygote',
            '--disable-gpu'
        ],
    }
});

client.on('qr', qr => {
    console.log('\n=== ESCANEA ESTE QR CON WHATSAPP BUSINESS ===\n');
    qrcode.generate(qr, { small: true });
    console.log('\n');
});

client.on('loading_screen', (percent, message) => {
    process.stdout.write('\rCargando WhatsApp... ' + percent + '%  ');
    if (parseInt(percent) >= 100) setTimeout(startReadyPoller, 5000);
});

client.on('authenticated', () => {
    console.log('\n✅ Sesión autenticada');
    setTimeout(startReadyPoller, 10000);
});

client.on('ready', () => {
    if (readyPoller) { clearInterval(readyPoller); readyPoller = null; }
    clientReady = true;
    console.log('🟢 WhatsApp listo para enviar mensajes\n');
});

client.on('disconnected', reason => {
    clientReady = false;
    console.log('🔴 WhatsApp desconectado:', reason);
});

client.initialize();

// ─────────────────────────────────────────────────────────────
// Servidor HTTP — solo escucha en localhost (no expuesto al exterior)
// ─────────────────────────────────────────────────────────────
const server = http.createServer((req, res) => {

    // GET /status
    if (req.method === 'GET' && req.url === '/status') {
        res.writeHead(200, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: true, ready: clientReady }));
        return;
    }

    if (req.method !== 'POST') {
        res.writeHead(405);
        res.end();
        return;
    }

    let body = '';
    req.on('data', chunk => { body += chunk.toString(); });
    req.on('end', async () => {
        try {
            const data = JSON.parse(body);

            // POST /send — mensaje individual
            if (req.url === '/send') {
                if (!clientReady) {
                    res.writeHead(503, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'WhatsApp no está conectado' }));
                    return;
                }
                const numberId = await client.getNumberId(data.phone);
                if (!numberId) {
                    res.writeHead(404, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'Número no registrado en WhatsApp: ' + data.phone }));
                    return;
                }
                await client.sendMessage(numberId._serialized, data.message);
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true }));

            // POST /send-bulk — envío masivo (responde inmediato, envía en segundo plano)
            } else if (req.url === '/send-bulk') {
                if (!clientReady) {
                    res.writeHead(503, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'WhatsApp no está conectado' }));
                    return;
                }
                const messages = data.messages || [];
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true, queued: messages.length }));

                for (const msg of messages) {
                    try {
                        const numberId = await client.getNumberId(msg.phone);
                        if (numberId) {
                            await client.sendMessage(numberId._serialized, msg.message);
                            console.log('📤 Enviado a', msg.phone);
                        } else {
                            console.warn('⚠️ Número sin WhatsApp:', msg.phone);
                        }
                    } catch (e) {
                        console.error('❌ Error enviando a', msg.phone + ':', e.message);
                    }
                    await new Promise(r => setTimeout(r, 1500));
                }
                console.log('✅ Bulk completado:', messages.length, 'mensajes');

            } else {
                res.writeHead(404);
                res.end();
            }

        } catch (e) {
            console.error('Error en el servidor:', e.message);
            if (!res.headersSent) {
                res.writeHead(500, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: false, error: e.message }));
            }
        }
    });
});

server.listen(3001, '127.0.0.1', () => {
    console.log('🚀 wa-service corriendo en localhost:3001');
    console.log('Iniciando WhatsApp Web...\n');
});
