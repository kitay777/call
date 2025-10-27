// Kurento 一対一用シグナリング（Socket.IO）
// - IceCandidateFound はブラウザへプレーンな RTCIceCandidateInit を送る（超重要）
// - ブラウザ→サーバの ICE は Kurento complexType に変換して add
// - join-room は ack + peer-joined 通知でオーダーを安定化
// - sdp-offer を受けて Kurento で Answer を返す（caller/callee 両対応）
// - 両者の WebRtcEndpoint を双方向 connect
// - stop / disconnect で相手へ通知しつつ部屋を解放

const express = require('express')
const http = require('http')
const { Server } = require('socket.io')
const kurento = require('kurento-client')

// ===== 環境変数 =====
const APP_ORIGIN = process.env.APP_ORIGIN || 'https://dev.call.navi.jpn.com'
const KURENTO_URI = process.env.KURENTO_URI || 'ws://neoria.fun:8888/kurento'
const PORT = Number(process.env.PORT || 3001)

// STUN/TURN
const STUN_ADDR = process.env.STUN_ADDRESS || 'stun.l.google.com'
const STUN_PORT = Number(process.env.STUN_PORT || 19302)
// 例: 'kitayama:celica77@turn.picton.jp:3478?transport=udp'
const TURN_URL = process.env.TURN_URL || ''
const DISABLE_STUN = /^true$/i.test(process.env.DISABLE_STUN || '')
const DISABLE_TURN = /^true$/i.test(process.env.DISABLE_TURN || '')

// ===== 基本セットアップ =====
const app = express()
const server = http.createServer(app)
const io = new Server(server, {
  cors: {
    origin: [
      'https://dev.call.navi.jpn.com',
      'http://dev.call.navi.jpn.com'
    ],
    methods: ['GET', 'POST'],
    credentials: true
  }
})

app.get('/health', (_, res) => res.json({ ok: true }))

let kurentoClient = null
// roomId -> { pipeline, caller:{ webrtc, socketId }, callee:{ webrtc, socketId } }
const rooms = new Map()

const log = (...a) => console.log('[sig]', ...a)

// ===== Kurento ユーティリティ =====
async function getKurentoClient() {
  if (kurentoClient) return kurentoClient
  return new Promise((resolve, reject) => {
    kurento(KURENTO_URI, (err, client) => {
      if (err) return reject(err)
      kurentoClient = client
      resolve(client)
    })
  })
}

function createEndpoint(pipeline) {
  return new Promise((resolve, reject) => {
    pipeline.create('WebRtcEndpoint', (err, ep) => (err ? reject(err) : resolve(ep)))
  })
}

function toKurentoCandidate(candidate) {
  if (candidate && candidate.__module && candidate.__type) return candidate
  return kurento.getComplexType('IceCandidate')(candidate)
}

function applyIceServers(endpoint) {
  if (!DISABLE_STUN && STUN_ADDR && STUN_PORT) {
    endpoint.setStunServerAddress(STUN_ADDR, (e) => e && console.error('setStun addr', e))
    endpoint.setStunServerPort(STUN_PORT, (e) => e && console.error('setStun port', e))
  }
  if (!DISABLE_TURN && TURN_URL) {
    endpoint.setTurnUrl(TURN_URL, (e) => e && console.error('setTurnUrl', e))
  }
}

function attachEndpointHandlers(endpoint, socket) {
  endpoint.on('IceCandidateFound', (ev) => {
    try {
      io.to(socket.id).emit('ice-candidate', { candidate: ev.candidate })
    } catch (e) {
      console.error('[IceCandidateFound emit error]', e)
    }
  })
  endpoint.on('MediaStateChanged', (ev) => log('MediaStateChanged', ev.newState))
  endpoint.on('ConnectionStateChanged', (ev) => log('ConnectionStateChanged', ev.newState))
}

async function ensureRoom(roomId) {
  const client = await getKurentoClient()
  let room = rooms.get(roomId)
  if (!room) {
    room = { pipeline: null, caller: {}, callee: {} }
    rooms.set(roomId, room)
  }
  if (!room.pipeline) {
    room.pipeline = await new Promise((res, rej) => {
      client.create('MediaPipeline', (e, p) => (e ? rej(e) : res(p)))
    })
  }
  return room
}

function connectBothWays(room) {
  if (room.caller?.webrtc && room.callee?.webrtc) {
    room.caller.webrtc.connect(room.callee.webrtc, (e) => e && console.error('[connect caller->callee]', e))
    room.callee.webrtc.connect(room.caller.webrtc, (e) => e && console.error('[connect callee->caller]', e))
  }
}

function releaseRoom(roomId) {
  const room = rooms.get(roomId)
  if (!room) return
  log('releaseRoom', roomId)

  try { room.caller?.webrtc && room.caller.webrtc.release() } catch (e) { console.error(e) }
  try { room.callee?.webrtc && room.callee.webrtc.release() } catch (e) { console.error(e) }
  try { room.pipeline && room.pipeline.release() } catch (e) { console.error(e) }

  try { room.caller?.socketId && io.to(room.caller.socketId).emit('stop', { roomId }) } catch (e) { console.error(e) }
  try { room.callee?.socketId && io.to(room.callee.socketId).emit('stop', { roomId }) } catch (e) { console.error(e) }

  rooms.delete(roomId)
}

// ===== Socket.IO ハンドラ =====
io.on('connection', (socket) => {
  log('connected', socket.id)

  // 入室
  socket.on('join-room', ({ roomId, role }, ack) => {
    try {
      if (!roomId)
        return typeof ack === 'function' && ack({ ok: false, error: 'no roomId' })

      socket.join(roomId)
      socket.to(roomId).emit('peer-joined', { roomId, role })
      log('peer-joined emitted', roomId, role)

      // ★ すでに相手が入っている場合、自分にも通知（順序ずれ対策）
      const room = io.sockets.adapter.rooms.get(roomId)
      if (room && room.size > 1) {
        socket.emit('peer-joined', { roomId, role: role === 'caller' ? 'callee' : 'caller' })
        log('peer-joined echo to self', roomId, role)
      }

      if (typeof ack === 'function') ack({ ok: true })
    } catch (e) {
      if (typeof ack === 'function') ack({ ok: false, error: String(e) })
    }
  })

  // SDP Offer
  socket.on('sdp-offer', async ({ roomId, role, sdp }) => {
    try {
      if (!roomId || !sdp || !/^(caller|callee)$/.test(role)) throw new Error('Invalid offer payload')
      const room = await ensureRoom(roomId)
      const side = role === 'caller' ? 'caller' : 'callee'

      if (!room[side].webrtc) {
        room[side].webrtc = await createEndpoint(room.pipeline)
        applyIceServers(room[side].webrtc)
        attachEndpointHandlers(room[side].webrtc, socket)
      }

      const answer = await new Promise((res, rej) => {
        room[side].webrtc.processOffer(sdp, (e, a) => (e ? rej(e) : res(a)))
      })

      room[side].webrtc.gatherCandidates(() => {})
      connectBothWays(room)
      room[side].socketId = socket.id

      io.to(socket.id).emit('sdp-answer', { sdp: answer, roomId })
      log('offer handled', { roomId, role, socket: socket.id })
    } catch (e) {
      console.error('sdp-offer error', e)
      io.to(socket.id).emit('stop', { roomId })
    }
  })

  // ICE Candidate
  socket.on('ice-candidate', ({ roomId, candidate }) => {
    try {
      const room = rooms.get(roomId)
      if (!room || !candidate) return

      for (const side of ['caller', 'callee']) {
        if (room[side]?.socketId === socket.id && room[side]?.webrtc) {
          const cand = toKurentoCandidate(candidate)
          room[side].webrtc.addIceCandidate(cand)
          break
        }
      }
    } catch (e) {
      console.error('addIceCandidate error', e)
    }
  })

  // stop（片側終了）
  socket.on('stop', ({ roomId }) => {
    if (roomId) {
      socket.to(roomId).emit('peer-left', { roomId })
      log('peer-left emitted', roomId)
      releaseRoom(roomId)
    }
  })

  // 切断
  socket.on('disconnect', () => {
    log('disconnect', socket.id)
    for (const [rid, room] of rooms.entries()) {
      if (room.caller?.socketId === socket.id || room.callee?.socketId === socket.id) {
        log('releasing room due to disconnect', rid)
        releaseRoom(rid)
      }
    }
  })
})

// ===== 起動 =====
process.on('uncaughtException', (err) => console.error('[uncaughtException]', err))
process.on('unhandledRejection', (reason) => console.error('[unhandledRejection]', reason))

server.listen(PORT, () => {
  console.log(`signaling :${PORT} origin=${APP_ORIGIN} -> KMS ${KURENTO_URI}`)
  if (!DISABLE_STUN) console.log(`STUN ${STUN_ADDR}:${STUN_PORT}`)
  if (!DISABLE_TURN && TURN_URL) console.log(`TURN ${TURN_URL}`)
})
