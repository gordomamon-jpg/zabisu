const { Client, LocalAuth, MessageMedia } = require('whatsapp-web.js');
const qrcode = require('qrcode-terminal');
const http   = require('http');
const path   = require('path');
const fs     = require('fs');
const crypto = require('crypto');

// Token compartido con el PHP que llama a este servicio (includes/enviar_whatsapp.php).
// Se genera solo una vez y se guarda en un archivo local (fuera de git) para que
// ambos lados lean el mismo valor sin tener que configurarlo a mano.
const SECRET_PATH = path.join(__dirname, '.secret');
let sharedSecret;
try {
    sharedSecret = fs.readFileSync(SECRET_PATH, 'utf8').trim();
} catch (e) {
    sharedSecret = crypto.randomBytes(24).toString('hex');
    // 0o644 (no 0o600): este proceso corre como root vía pm2, pero PHP
    // corre como www-data y necesita poder leer el archivo para mandar
    // el token en cada llamada — con 0o600 www-data se queda sin acceso
    // y todas las peticiones de PHP terminan rechazadas en silencio.
    fs.writeFileSync(SECRET_PATH, sharedSecret, { mode: 0o644 });
}

function autenticado(req) {
    return req.headers['x-wa-token'] === sharedSecret;
}

let clientReady = false;
let readyPoller = null;

// ─────────────────────────────────────────────────────────────
// Números con los que ya hay chat (les escribimos con éxito o ellos nos
// escribieron). La difusión SOLO se manda a estos: iniciar chats nuevos en
// masa es lo que hizo que WhatsApp restringiera la cuenta (2026-10-01).
// Clave = últimos 10 dígitos (en México WhatsApp alterna 52 / 521).
// ─────────────────────────────────────────────────────────────
// WA_CUENTA permite conectar temporalmente otro número sin tocar la sesión
// ni el registro de chats del número principal: cada cuenta tiene su propia
// carpeta de sesión y su propio contactados.json. Sin WA_CUENTA = principal.
const CUENTA = (process.env.WA_CUENTA || '').replace(/[^a-z0-9_-]/gi, '');
const sufijoCuenta = CUENTA ? '_' + CUENTA : '';
if (CUENTA) console.log('🔀 Cuenta de WhatsApp:', CUENTA);

// En el número temporal se avisa al cliente para que no lo tome como spam.
function textoCuenta(mensaje) {
    return CUENTA ? mensaje + '\n\n_Te escribimos desde un número temporal de Zabisu._' : mensaje;
}

const CONTACTADOS_PATH = path.join(__dirname, 'contactados' + sufijoCuenta + '.json');
const contactados = new Set();
try {
    for (const n of JSON.parse(fs.readFileSync(CONTACTADOS_PATH, 'utf8'))) contactados.add(String(n));
    console.log('📇 Contactos con chat previo:', contactados.size);
} catch (e) {
    console.warn('⚠️ Sin contactados.json — la difusión no enviará a nadie hasta que haya chats registrados');
}

function claveTel(phone) {
    return String(phone || '').replace(/\D/g, '').slice(-10);
}

let guardarContactadosTimer = null;
function marcarContactado(phone) {
    const clave = claveTel(phone);
    if (clave.length !== 10 || contactados.has(clave)) return;
    contactados.add(clave);
    if (guardarContactadosTimer) return;
    guardarContactadosTimer = setTimeout(() => {
        guardarContactadosTimer = null;
        try {
            const tmp = CONTACTADOS_PATH + '.tmp';
            fs.writeFileSync(tmp, JSON.stringify([...contactados]));
            fs.renameSync(tmp, CONTACTADOS_PATH);
        } catch (e) {
            console.error('❌ No se pudo guardar contactados.json:', e.message);
        }
    }, 5000);
}

// Pausa entre mensajes de la difusión: al azar entre 10 y 20 s, para que no
// salgan a ritmo de máquina.
const BROADCAST_PAUSA_MIN_MS = 10000;
const BROADCAST_PAUSA_MAX_MS = 20000;
let broadcastEnCurso = false;

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

// Trabajos de /send-bulk en curso — permite responder de inmediato al panel
// y que el envío real (que puede tardar varios minutos) ocurra en segundo
// plano sin dejar ocupado el proceso PHP que lo pidió.
const bulkJobs = new Map();

async function procesarBulkEnSegundoPlano(jobId, messages) {
    const resultados = [];

    for (const msg of messages) {
        try {
            const numberId = await withTimeout(client.getNumberId(msg.phone), BULK_PER_MESSAGE_TIMEOUT_MS);
            if (numberId) {
                await withTimeout(client.sendMessage(numberId._serialized, textoCuenta(msg.message)), BULK_PER_MESSAGE_TIMEOUT_MS);
                marcarContactado(msg.phone);
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
    console.log('✅ Bulk completado:', exitosos, 'de', messages.length, 'mensajes (job ' + jobId + ')');

    bulkJobs.set(jobId, { status: 'done', total: messages.length, resultados });
    // Limpieza — nadie debería tardar más de unos minutos en consultarlo.
    setTimeout(() => bulkJobs.delete(jobId), 15 * 60 * 1000);
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
    authStrategy: new LocalAuth({ dataPath: path.join(__dirname, 'wa_session' + sufijoCuenta) }),
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

// Si un cliente nos escribe, ya hay chat con él
client.on('message', async msg => {
    try {
        const contacto = await msg.getContact();
        if (contacto && contacto.number) marcarContactado(contacto.number);
    } catch (e) { /* no es crítico */ }
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

    if (!autenticado(req)) {
        res.writeHead(401, { 'Content-Type': 'application/json' });
        res.end(JSON.stringify({ ok: false, error: 'No autorizado' }));
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
                await client.sendMessage(numberId._serialized, textoCuenta(data.message));
                marcarContactado(data.phone);
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true }));

            // POST /send-bulk — lotes chicos (notificación de llegada a un punto/horario).
            // Responde de inmediato con un job_id; el envío real (que puede tardar varios
            // minutos si la sesión está degradada) ocurre en segundo plano para no dejar
            // esperando al proceso PHP que lo pidió. El resultado real se consulta en
            // /bulk-status.
            } else if (req.url === '/send-bulk') {
                if (!clientReady) {
                    res.writeHead(503, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'WhatsApp no está conectado' }));
                    return;
                }
                const messages = data.messages || [];
                const jobId = crypto.randomBytes(8).toString('hex');
                bulkJobs.set(jobId, { status: 'processing', total: messages.length, resultados: [] });

                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true, job_id: jobId, total: messages.length }));

                procesarBulkEnSegundoPlano(jobId, messages);

            // GET /bulk-status (vía POST, como el resto de este servicio) — consulta el
            // resultado de un job de /send-bulk.
            } else if (req.url === '/bulk-status') {
                const job = bulkJobs.get(data.job_id);
                if (!job) {
                    res.writeHead(404, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'job_id no encontrado o ya expiró' }));
                    return;
                }
                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({ ok: true, status: job.status, total: job.total, resultados: job.resultados }));

            // POST /send-broadcast — imagen + texto personalizado por contacto.
            // Recibe recipients: [{ phone, caption }]. Solo envía a números con
            // chat previo, uno cada 10–20 s al azar, y una difusión a la vez.
            } else if (req.url === '/send-broadcast') {
                if (!clientReady) {
                    res.writeHead(503, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'WhatsApp no está conectado' }));
                    return;
                }
                if (CUENTA) {
                    res.writeHead(403, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'La difusión está desactivada en el número temporal.' }));
                    return;
                }
                if (broadcastEnCurso) {
                    res.writeHead(409, { 'Content-Type': 'application/json' });
                    res.end(JSON.stringify({ ok: false, error: 'Ya hay una difusión enviándose. Espera a que termine.' }));
                    return;
                }
                const recipients = data.recipients || [];
                const img        = data.image;   // { data: base64, mimetype, filename }

                const aEnviar  = recipients.filter(r => contactados.has(claveTel(r.phone)));
                const omitidos = recipients.length - aEnviar.length;
                const pausaPromedio = (BROADCAST_PAUSA_MIN_MS + BROADCAST_PAUSA_MAX_MS) / 2;

                res.writeHead(200, { 'Content-Type': 'application/json' });
                res.end(JSON.stringify({
                    ok: true,
                    queued: aEnviar.length,
                    omitidos,
                    minutos_estimados: Math.ceil(aEnviar.length * pausaPromedio / 60000),
                }));

                if (omitidos > 0) console.log('⏭️ Difusión: se omiten', omitidos, 'números sin chat previo');

                broadcastEnCurso = true;
                const media = img ? new MessageMedia(img.mimetype, img.data, img.filename) : null;
                let enviados = 0;
                try {
                    for (const r of aEnviar) {
                        try {
                            const numberId = await client.getNumberId(r.phone);
                            if (!numberId) { console.warn('⚠️ Sin WA:', r.phone); continue; }
                            if (media) {
                                await client.sendMessage(numberId._serialized, media, { caption: r.caption });
                            } else if (r.caption) {
                                await client.sendMessage(numberId._serialized, r.caption);
                            }
                            enviados++;
                            console.log('📤 Broadcast a', r.phone);
                        } catch (e) {
                            console.error('❌ Broadcast error', r.phone + ':', e.message);
                        }
                        const pausa = BROADCAST_PAUSA_MIN_MS + Math.random() * (BROADCAST_PAUSA_MAX_MS - BROADCAST_PAUSA_MIN_MS);
                        await new Promise(res => setTimeout(res, pausa));
                    }
                } finally {
                    broadcastEnCurso = false;
                }
                console.log('✅ Broadcast completado:', enviados, 'de', aEnviar.length, 'contactos');

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
