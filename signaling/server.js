// ==========================================================
// Kurento 一対一用 Signaling サーバ (Socket.IO + Express)
// ==========================================================
//
// ✅ 改善ポイント
//  - caller / callee が同じ MediaPipeline を確実に共有
//  - disconnect 時に pipeline を即破棄しない（両者離脱後に破棄）
//  - callee/caller の順序差異にも強い
//  - STUN/TURN 設定・ICE 処理完備
//  - phase-change イベントの双方向伝播対応
//  - ログの粒度を最適化
//

const fs = require("fs");
const express = require("express");
const https = require("https");
const { Server } = require("socket.io");
const kurento = require("kurento-client");
const SSL_KEY = process.env.SSL_KEY || "/etc/nginx/certs/server.key";
const SSL_CERT = process.env.SSL_CERT || "/etc/nginx/certs/server.pem";

// ===== 環境設定 =====
const APP_ORIGIN = process.env.APP_ORIGIN || "https://dev.call.navi.jpn.com";
const KURENTO_URI = process.env.KURENTO_URI || "ws://neoria.fun:8888/kurento";
const PORT = Number(process.env.PORT || 3001);

const STUN_ADDR = process.env.STUN_ADDRESS || "stun.l.google.com";
const STUN_PORT = Number(process.env.STUN_PORT || 19302);
const TURN_URL =
    process.env.TURN_URL ||
    "kitayama:celica77@turn.picton.jp:3478?transport=udp";
const DISABLE_STUN = /^true$/i.test(process.env.DISABLE_STUN || "");
const DISABLE_TURN = /^true$/i.test(process.env.DISABLE_TURN || "");

// ===== サーバ初期化 =====
const app = express();
const options = {
    key: fs.readFileSync(SSL_KEY),
    cert: fs.readFileSync(SSL_CERT),
};

// ===== HTTPSサーバー =====
const server = https.createServer(options, app);
const io = new Server(server, {
    cors: {
        origin: [
            "https://dev.call.navi.jpn.com",
            "http://dev.call.navi.jpn.com",
        ],
        methods: ["GET", "POST"],
        credentials: true,
    },
});

app.get("/health", (_, res) => res.json({ ok: true }));

let kurentoClient = null;
// roomId -> { pipeline, caller:{webrtc,socketId}, callee:{webrtc,socketId} }
const rooms = new Map();
const log = (...a) => console.log("[sig]", ...a);

// ==========================================================
// Kurento Utility
// ==========================================================

async function getKurentoClient() {
    if (kurentoClient) return kurentoClient;
    return new Promise((resolve, reject) => {
        kurento(KURENTO_URI, (err, client) => {
            if (err) return reject(err);
            kurentoClient = client;
            resolve(client);
        });
    });
}

function createEndpoint(pipeline) {
    return new Promise((resolve, reject) => {
        pipeline.create("WebRtcEndpoint", (err, ep) =>
            err ? reject(err) : resolve(ep)
        );
    });
}

function toKurentoCandidate(candidate) {
    if (!candidate) return null;
    if (candidate.__module && candidate.__type) return candidate;
    return kurento.getComplexType("IceCandidate")(candidate);
}

function applyIceServers(endpoint) {
    if (!DISABLE_STUN && STUN_ADDR && STUN_PORT) {
        endpoint.setStunServerAddress(
            STUN_ADDR,
            (e) => e && console.error("setStun addr", e)
        );
        endpoint.setStunServerPort(
            STUN_PORT,
            (e) => e && console.error("setStun port", e)
        );
    }
    if (!DISABLE_TURN && TURN_URL) {
        endpoint.setTurnUrl(
            TURN_URL,
            (e) => e && console.error("setTurnUrl", e)
        );
    }
}

function attachEndpointHandlers(endpoint, socket) {
    endpoint.on("IceCandidateFound", (ev) => {
        io.to(socket.id).emit("ice-candidate", { candidate: ev.candidate });
    });
    endpoint.on("MediaStateChanged", (ev) =>
        log("MediaStateChanged", ev.newState)
    );
    endpoint.on("ConnectionStateChanged", (ev) =>
        log("ConnectionStateChanged", ev.newState)
    );
}

// グローバル変数
const pipelineLocks = new Map();

async function ensureRoom(roomId) {
    const client = await getKurentoClient();

    // === 既存の pipeline があればそれを使う ===
    const existing = rooms.get(roomId);
    if (existing?.pipeline) return existing;

    // === rooms に仮エントリを最初に確保（重要）===
    if (!rooms.has(roomId)) {
        rooms.set(roomId, { pipeline: null, caller: {}, callee: {} });
    }
    const room = rooms.get(roomId);

    // === 他が同時に作成中なら待機 ===
    if (pipelineLocks.has(roomId)) {
        log(`[sig] waiting for existing pipeline lock for ${roomId}`);
        await pipelineLocks.get(roomId);
        return rooms.get(roomId);
    }

    // === 自分が pipeline 作成担当 ===
    let resolver;
    const lockPromise = new Promise((res) => (resolver = res));
    pipelineLocks.set(roomId, lockPromise);

    try {
        log(`[sig] 🏗️ creating pipeline for room ${roomId}`);
        room.pipeline = await new Promise((res, rej) => {
            client.create("MediaPipeline", (err, p) =>
                err ? rej(err) : res(p)
            );
        });
        log(`[sig] ✅ pipeline created (${room.pipeline.id}) for ${roomId}`);
    } catch (err) {
        console.error(`[sig] ❌ pipeline creation failed for ${roomId}`, err);
        rooms.delete(roomId);
        throw err;
    } finally {
        resolver(); // ロック解除
        pipelineLocks.delete(roomId);
    }

    return room;
}

async function connectBothWays(room) {
    const { caller, callee } = room;
    if (!caller?.webrtc || !callee?.webrtc) {
        log(`[sig] connect skipped — missing endpoint(s)`);
        return;
    }

    try {
        // 双方向connectの順序を保証（Kurentoの非同期競合防止）
        await new Promise((resolve, reject) => {
            caller.webrtc.connect(callee.webrtc, (err) => {
                if (err) return reject(err);
                log(`[sig] connected caller -> callee`);
                resolve();
            });
        });

        await new Promise((resolve, reject) => {
            callee.webrtc.connect(caller.webrtc, (err) => {
                if (err) return reject(err);
                log(`[sig] connected callee -> caller`);
                resolve();
            });
        });

        log(
            `[sig] ✅ endpoints fully connected for room ${
                room.roomId || "(unknown)"
            }`
        );
    } catch (e) {
        console.error("[connectBothWays error]", e);
    }
}

// ✅ 安全な破棄（両者離脱後のみ削除）
function releaseRoom(roomId) {
    const room = rooms.get(roomId);
    if (!room) return;

    const hasCaller = !!room.caller?.socketId;
    const hasCallee = !!room.callee?.socketId;

    if (hasCaller || hasCallee) {
        log(`[sig] skip releaseRoom ${roomId} — someone still connected`);
        return;
    }

    log("[sig] releaseRoom", roomId);
    try {
        room.caller?.webrtc?.release();
    } catch {}
    try {
        room.callee?.webrtc?.release();
    } catch {}
    try {
        room.pipeline?.release();
    } catch {}
    rooms.delete(roomId);
}

// ==========================================================
// Socket.IO
// ==========================================================

io.on("connection", (socket) => {
    log("connected", socket.id);

    // === Phase Change ===
socket.on("phase-change", (payload) => {
    const { roomId } = payload;
    if (!roomId) return;

    console.log("[sig] phase-change", payload);

    // 画像を含む payload を丸ごと送る
    socket.to(roomId).emit("phase-change", payload);
});


    // === Join Room ===
socket.on("join-room", ({ roomId, role }, ack) => {
    try {
        if (!roomId) return ack?.({ ok: false, error: "no roomId" });

        // 🔥 そのまま部屋に join
        socket.join(roomId);

        // 🔥 相手側へ「誰が join したか」を通知（反転しない！）
        socket.to(roomId).emit("peer-joined", { roomId, role });

        // オプション：必要なら caller/callee を記録しても良いが反転はしない
        ack?.({ ok: true });
    } catch (e) {
        ack?.({ ok: false, error: String(e) });
    }
});


    // === SDP Offer ===
    socket.on("sdp-offer", async ({ roomId, role, sdp }) => {
        try {
            if (!roomId || !sdp || !/^(caller|callee)$/.test(role))
                throw new Error("Invalid offer payload");

            const room = await ensureRoom(roomId);
            const side = role === "caller" ? "caller" : "callee";

            if (!room[side].webrtc) {
                room[side].webrtc = await createEndpoint(room.pipeline);
                applyIceServers(room[side].webrtc);
                attachEndpointHandlers(room[side].webrtc, socket);
            }

            const answer = await new Promise((res, rej) =>
                room[side].webrtc.processOffer(sdp, (e, a) =>
                    e ? rej(e) : res(a)
                )
            );

            room[side].webrtc.gatherCandidates(() => {});
            room[side].socketId = socket.id;

            io.to(socket.id).emit("sdp-answer", { sdp: answer, roomId });
            log("[sig] offer handled", { roomId, role, socket: socket.id });

            // 両者揃ったら connect
            if (room.caller?.webrtc && room.callee?.webrtc) {
                log(
                    `[sig] both endpoints ready for room ${roomId}, connecting...`
                );
                connectBothWays(room);
            }
        } catch (e) {
            console.error("[sdp-offer error]", e);
            io.to(socket.id).emit("stop", { roomId });
        }
    });

    // === ICE Candidate ===
    socket.on("ice-candidate", ({ roomId, candidate }) => {
        try {
            const room = rooms.get(roomId);
            if (!room || !candidate) return;
            for (const side of ["caller", "callee"]) {
                if (room[side]?.socketId === socket.id && room[side]?.webrtc) {
                    const cand = toKurentoCandidate(candidate);
                    room[side].webrtc.addIceCandidate(cand);
                    break;
                }
            }
        } catch (e) {
            console.error("addIceCandidate error", e);
        }
    });

socket.on("stop", ({ roomId }) => {
  if (!roomId) return;
  
  // ✅ 両者に stop を通知する
  io.to(roomId).emit("stop", { roomId });
  log("[sig] stop emitted to both clients", roomId);

  const room = rooms.get(roomId);
  if (!room) return;

  // 接続状態を解除
  if (room.caller?.socketId === socket.id) room.caller.socketId = null;
  if (room.callee?.socketId === socket.id) room.callee.socketId = null;

  releaseRoom(roomId);
});



    // === Disconnect ===
    socket.on("disconnect", () => {
        log("disconnect", socket.id);
        for (const [rid, room] of rooms.entries()) {
            let changed = false;
            if (room.caller?.socketId === socket.id) {
                room.caller.socketId = null;
                changed = true;
            }
            if (room.callee?.socketId === socket.id) {
                room.callee.socketId = null;
                changed = true;
            }
            if (changed) releaseRoom(rid);
        }
    });

    socket.onAny((event, ...args) => log("[sig:onAny]", event, args.length));
});

// ==========================================================
// 起動
// ==========================================================

process.on("uncaughtException", (err) =>
    console.error("[uncaughtException]", err)
);
process.on("unhandledRejection", (reason) =>
    console.error("[unhandledRejection]", reason)
);

server.listen(PORT, () => {
    console.log(
        `signaling :${PORT} origin=${APP_ORIGIN} -> KMS ${KURENTO_URI}`
    );
    if (!DISABLE_STUN) console.log(`STUN ${STUN_ADDR}:${STUN_PORT}`);
    if (!DISABLE_TURN && TURN_URL) console.log(`TURN ${TURN_URL}`);
});
