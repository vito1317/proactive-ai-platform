#!/usr/bin/env python3
"""
PAI 的獨立 IRC bridge daemon（原創，僅用 Python 標準庫）。

IRC 是常駐 socket 連線，不是 webhook —— 所以用這支獨立行程連上 IRC，收到訊息就打 PAI 既有的
/api/chat/send，再把回覆 PRIVMSG 回去。每個頻道維持各自的對話上下文。

設定（環境變數）：
  PAI_BASE      PAI 站台（預設 https://pai.vito1317.com）
  PAI_SECRET    gateway 註冊密鑰（X-Register-Secret；後台「設定」可查 / artisan tinker GatewayController::registerSecret()）
  IRC_SERVER    IRC 伺服器主機（如 irc.libera.chat）
  IRC_PORT      連接埠（預設 6697）
  IRC_TLS       1=TLS（預設 1；對應 port 6697）
  IRC_NICK      bot 暱稱（預設 pai-bot）
  IRC_CHANNELS  逗號分隔的頻道（如 "#pai,#test"）
  IRC_PREFIX    只回應以此前綴開頭的訊息（預設空=頻道內所有訊息都回；建議設 "!pai "）

跑法：
  PAI_SECRET=xxx IRC_SERVER=irc.libera.chat IRC_CHANNELS='#pai' python3 irc_bridge.py
  （建議用 systemd / supervisor 常駐；範例見檔尾註解）
"""
import json
import os
import socket
import ssl
import sys
import time
import urllib.request

PAI_BASE = os.environ.get("PAI_BASE", "https://pai.vito1317.com").rstrip("/")
PAI_SECRET = os.environ.get("PAI_SECRET", "")
SERVER = os.environ.get("IRC_SERVER", "")
PORT = int(os.environ.get("IRC_PORT", "6697"))
USE_TLS = os.environ.get("IRC_TLS", "1") == "1"
NICK = os.environ.get("IRC_NICK", "pai-bot")
CHANNELS = [c.strip() for c in os.environ.get("IRC_CHANNELS", "").split(",") if c.strip()]
PREFIX = os.environ.get("IRC_PREFIX", "")

# 每個 IRC 頻道 → PAI conversation_id（維持上下文）
_conv = {}


def log(*a):
    print("[irc-bridge]", *a, flush=True)


def ask_pai(channel: str, text: str) -> str:
    """打 PAI /api/chat/send，回傳 AI 回覆文字。每頻道沿用同一個 conversation。"""
    body = {"message": text}
    if channel in _conv:
        body["conversation_id"] = _conv[channel]
    data = json.dumps(body).encode("utf-8")
    req = urllib.request.Request(
        PAI_BASE + "/api/chat/send", data=data, method="POST",
        headers={"Content-Type": "application/json", "Accept": "application/json",
                 "X-Register-Secret": PAI_SECRET},
    )
    try:
        with urllib.request.urlopen(req, timeout=180) as r:
            j = json.loads(r.read().decode("utf-8"))
        if j.get("conversation_id"):
            _conv[channel] = j["conversation_id"]
        return (j.get("reply") or j.get("error") or "（無回應）").strip()
    except Exception as e:
        return f"（PAI 連線失敗：{e}）"


def send(sock, line: str):
    sock.sendall((line + "\r\n").encode("utf-8", "replace"))


def privmsg(sock, target: str, text: str):
    # IRC 一行有長度上限 → 拆行送
    for chunk in (text.replace("\r", " ").split("\n")):
        chunk = chunk.strip()
        while chunk:
            send(sock, f"PRIVMSG {target} :{chunk[:400]}")
            chunk = chunk[400:]
            time.sleep(0.4)  # 簡易節流，避免被 IRC server flood-kick


def run_once():
    raw = socket.create_connection((SERVER, PORT), timeout=30)
    sock = ssl.create_default_context().wrap_socket(raw, server_hostname=SERVER) if USE_TLS else raw
    send(sock, f"NICK {NICK}")
    send(sock, f"USER {NICK} 0 * :PAI bridge")
    buf = ""
    joined = False
    while True:
        data = sock.recv(4096)
        if not data:
            raise ConnectionError("socket closed")
        buf += data.decode("utf-8", "replace")
        while "\r\n" in buf:
            line, buf = buf.split("\r\n", 1)
            if line.startswith("PING"):
                send(sock, "PONG" + line[4:])
                continue
            parts = line.split(" ")
            # 註冊完成（001 welcome）→ JOIN 頻道
            if len(parts) > 1 and parts[1] == "001" and not joined:
                joined = True
                for ch in CHANNELS:
                    send(sock, f"JOIN {ch}")
                    log("joined", ch)
                continue
            # PRIVMSG：:nick!user@host PRIVMSG #chan :text
            if len(parts) >= 4 and parts[1] == "PRIVMSG":
                sender = parts[0].lstrip(":").split("!")[0]
                target = parts[2]
                text = line.split(" :", 1)[1] if " :" in line else ""
                if sender == NICK or not text:
                    continue
                # 頻道訊息：可要求前綴；私訊（target==NICK）一律回
                reply_to = target if target.startswith("#") else sender
                if target.startswith("#"):
                    if PREFIX and not text.startswith(PREFIX):
                        continue
                    if PREFIX:
                        text = text[len(PREFIX):].strip()
                if not text:
                    continue
                log(f"<{sender}/{target}> {text[:80]}")
                reply = ask_pai(reply_to, text)
                privmsg(sock, reply_to, reply)


def main():
    if not (SERVER and CHANNELS and PAI_SECRET):
        log("缺少設定：需要 IRC_SERVER / IRC_CHANNELS / PAI_SECRET 環境變數")
        sys.exit(1)
    while True:
        try:
            log(f"connecting {SERVER}:{PORT} tls={USE_TLS} nick={NICK}")
            run_once()
        except Exception as e:
            log("disconnected:", e, "→ 5 秒後重連")
            time.sleep(5)


if __name__ == "__main__":
    main()

# ── 常駐範例（supervisor）──────────────────────────────────────────────
# [program:pai-irc-bridge]
# command=/usr/bin/python3 /home/vito/proactive-ai-platform/bridges/irc_bridge.py
# environment=PAI_SECRET="<gateway register secret>",IRC_SERVER="irc.libera.chat",IRC_CHANNELS="#pai",IRC_PREFIX="!pai "
# autostart=true
# autorestart=true
# user=vito
