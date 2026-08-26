const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const http   = require('http');
const path   = require('path');

let clientReady = false;
let readyPoller = null;

// Tope por mensaje individual dentro de /send-bulk, para que un envío
// colgado (sesión degradada) no deje esperando al panel varios minutos —
// se reporta como fallido y se sigue con el resto del lote.
const BULK_PER_MESSAGE_TIMEOUT_MS = 20000;

function withTimeout(promise, ms) {
    return Promise.race([
        promise,
        new Promise((_, reject) => setTimeout(() => reject(new Error('Tiempo de espera agotado')), ms)),
    ]);
}

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
    puppeteer: {
        protocolTimeout: 300000, // 5 min — el default (180s) se quedaba corto y tronaba con "Runtime.callFunctionOn timed out"
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

client.on('change_state', state => {
    console.log('\nEstado WA:', state);
    if (state === 'CONNECTED' && !clientReady) {
        if (readyPoller) { clearInterval(readyPoller); readyPoller = null; }
        clientReady = true;
        console.log('🟢 WhatsApp listo (change_state)\n');
    }
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

            // POST /send-bulk — lotes chicos (notificación de llegada a un punto/horario),
            // espera a que terminen todos los envíos y regresa el resultado real por número.
            } else if (req.url === '/send-bulk') {
                if (!clientReady) {
                    res.writeHead(503, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'WhatsApp no está conectado' }));
                    return;
                }
                const messages = data.messages || [];
                const resultados = [];

                for (const msg of messages) {
                    try {
                        const numberId = await withTimeout(client.getNumberId(msg.phone), BULK_PER_MESSAGE_TIMEOUT_MS);
                        if (numberId) {
                            await withTimeout(client.sendMessage(numberId._serialized, msg.message), BULK_PER_MESSAGE_TIMEOUT_MS);
                            console.log('📤 Enviado a', msg.phone);
                            resultados.push({ phone: msg.phone, ok: true });
                        } else {
                            console.warn('⚠️ Número sin WhatsApp:', msg.phone);
                            resultados.push({ phone: msg.phone, ok: false, error: 'Número no registrado en WhatsApp' });
                        }
                    } catch (e) {
                        console.error('❌ Error enviando a', msg.phone + ':', e.message);
                        resultados.push({ phone: msg.phone, ok: false, error: e.message });
                    }
                    await new Promise(r => setTimeout(r, 1500));
                }

                const exitosos = resultados.filter(r => r.ok).length;
                console.log('✅ Bulk completado:', exitosos, 'de', messages.length, 'mensajes');

                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true, queued: messages.length, resultados }));

            // POST /send-broadcast — imagen + caption a lista de teléfonos
            } else if (req.url === '/send-broadcast') {
                if (!clientReady) {
                    res.writeHead(503, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'WhatsApp no está conectado' }));
                    return;
                }
                const phones  = data.phones  || [];
                const caption = data.caption || '';
                const img     = data.image;   // { data: base64, mimetype, filename }

                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true, queued: phones.length }));

                const media = img ? new MessageMedia(img.mimetype, img.data, img.filename) : null;

                for (const phone of phones) {
                    try {
                        const numberId = await client.getNumberId(phone);
                        if (!numberId) { console.warn('⚠️ Sin WA:', phone); continue; }
                        if (media) {
                            await client.sendMessage(numberId._serialized, media, { caption });
                        } else if (caption) {
                            await client.sendMessage(numberId._serialized, caption);
                        }
                        console.log('📤 Broadcast a', phone);
                    } catch (e) {
                        console.error('❌ Broadcast error', phone + ':', e.message);
                    }
                    await new Promise(r => setTimeout(r, 2500));
                }
                console.log('✅ Broadcast completado:', phones.length, 'contactos');

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

// /send-bulk ahora espera a que termine todo el lote antes de responder —
// sube el timeout del socket para que no se corte a media espera.
server.setTimeout(600000);

server.listen(3001, '127.0.0.1', () => {
    console.log('🚀 wa-service corriendo en localhost:3001');
    console.log('Iniciando WhatsApp Web...\n');
});
