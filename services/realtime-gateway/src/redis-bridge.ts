import { Redis } from 'ioredis';
import type { Server as SocketIoServer } from 'socket.io';

interface BroadcastEnvelope {
    room: string;
    event: string;
    payload: unknown;
}

function isBroadcastEnvelope(value: unknown): value is BroadcastEnvelope {
    return (
        typeof value === 'object' &&
        value !== null &&
        typeof (value as BroadcastEnvelope).room === 'string' &&
        typeof (value as BroadcastEnvelope).event === 'string'
    );
}

/**
 * Laravel publishes to REDIS_REALTIME_CHANNEL after DB commit (never inside
 * an allocation/deallocation transaction — CLAUDE.md invariant 6). This
 * process only relays; it holds no domain state and never writes back to
 * Postgres or Redis.
 */
export function bridgeRedisToSockets(io: SocketIoServer, redisUrl: string, channel: string): Redis {
    const subscriber = new Redis(redisUrl);

    subscriber.subscribe(channel, (err) => {
        if (err) {
            throw err;
        }
    });

    subscriber.on('message', (_channel, message) => {
        let parsed: unknown;
        try {
            parsed = JSON.parse(message);
        } catch {
            return;
        }

        if (!isBroadcastEnvelope(parsed)) {
            return;
        }

        io.to(parsed.room).emit(parsed.event, parsed.payload);
    });

    return subscriber;
}
