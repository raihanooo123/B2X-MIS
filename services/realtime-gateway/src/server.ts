import Fastify from 'fastify';
import { Server as SocketIoServer } from 'socket.io';
import { bridgeRedisToSockets } from './redis-bridge.js';

const PORT = Number(process.env.PORT ?? 4001);
const REDIS_URL = process.env.REDIS_URL ?? 'redis://127.0.0.1:6379';
const REDIS_REALTIME_CHANNEL = process.env.REDIS_REALTIME_CHANNEL ?? 'realtime-events';

const app = Fastify({ logger: true });

app.get('/health', async () => ({ status: 'ok' }));

const io = new SocketIoServer(app.server, {
    cors: { origin: process.env.SOCKET_CORS_ORIGIN ?? '*' },
});

io.on('connection', (socket) => {
    socket.on('join', (room: string) => {
        socket.join(room);
    });

    socket.on('leave', (room: string) => {
        socket.leave(room);
    });
});

const subscriber = bridgeRedisToSockets(io, REDIS_URL, REDIS_REALTIME_CHANNEL);

app.addHook('onClose', async () => {
    await subscriber.quit();
});

app.listen({ port: PORT, host: '0.0.0.0' }).catch((err) => {
    app.log.error(err);
    process.exit(1);
});
