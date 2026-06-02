#!/usr/bin/env bash
#
# PAI 主動式 AI 平台 — 一鍵安裝腳本
#
# 用法：
#   ./install.sh                          # 本機開發安裝（SQLite，互動式）
#   ./install.sh --prod                   # production（最佳化 autoload + config cache）
#   ./install.sh --with-nginx --domain pai.example.com --port 8083
#   ./install.sh --with-systemd           # 安裝 queue worker + scheduler 為系統服務
#   環境變數：ADMIN_EMAIL, ADMIN_PASSWORD（免互動建立管理員）
#
set -euo pipefail

# ---------- 參數 ----------
APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROD=0
WITH_NGINX=0
WITH_SYSTEMD=0
DOMAIN=""
PORT="8083"
RUN_USER="${SUDO_USER:-$(id -un)}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --prod) PROD=1; shift ;;
    --with-nginx) WITH_NGINX=1; shift ;;
    --with-systemd) WITH_SYSTEMD=1; shift ;;
    --domain) DOMAIN="$2"; shift 2 ;;
    --port) PORT="$2"; shift 2 ;;
    -h|--help) awk 'NR>1 && /^set /{exit} NR>1{sub(/^# ?/,""); print}' "$0"; exit 0 ;;
    *) echo "未知參數：$1"; exit 1 ;;
  esac
done

cd "$APP_DIR"

# ---------- 輔助 ----------
c_green=$'\033[0;32m'; c_yellow=$'\033[0;33m'; c_red=$'\033[0;31m'; c_reset=$'\033[0m'
step() { echo -e "\n${c_green}▶ $*${c_reset}"; }
warn() { echo -e "${c_yellow}⚠ $*${c_reset}"; }
die()  { echo -e "${c_red}✘ $*${c_reset}"; exit 1; }
have() { command -v "$1" >/dev/null 2>&1; }

# ---------- 1. 前置檢查 ----------
step "檢查環境需求"
have php || die "找不到 php（需 8.3+）"
PHP_VER="$(php -r 'echo PHP_VERSION;')"
php -r 'exit(version_compare(PHP_VERSION, "8.3.0", ">=") ? 0 : 1);' || die "PHP $PHP_VER 過舊，需 8.3+"
have composer || die "找不到 composer"
have npm || die "找不到 npm（需 Node 18+）"
echo "  PHP $PHP_VER · $(composer --version 2>/dev/null | head -1) · node $(node -v)"
# PHP 擴充
for ext in pdo_sqlite mbstring openssl tokenizer; do
  php -m | grep -qi "^$ext$" || die "缺少 PHP 擴充：$ext"
done
have bwrap || warn "未安裝 bubblewrap (bwrap) — Sandbox 將降級為弱隔離（建議 apt install bubblewrap）"

# ---------- 2. PHP 相依 ----------
step "安裝 PHP 相依 (composer)"
if [[ $PROD -eq 1 ]]; then
  composer install --no-dev --optimize-autoloader --no-interaction
else
  composer install --no-interaction
fi

# ---------- 3. .env / APP_KEY ----------
step "設定 .env"
if [[ ! -f .env ]]; then
  cp .env.example .env
  echo "  已從 .env.example 建立 .env"
fi
if ! grep -qE '^APP_KEY=base64:' .env; then
  php artisan key:generate --force
fi
# 預設 SQLite
grep -qE '^DB_CONNECTION=' .env || echo "DB_CONNECTION=sqlite" >> .env
sed -i 's/^DB_CONNECTION=.*/DB_CONNECTION=sqlite/' .env
[[ -f database/database.sqlite ]] || touch database/database.sqlite

# ---------- 4. 資料庫遷移 ----------
step "建立資料庫結構 (migrate)"
php artisan migrate --force

# ---------- 5. 前端建置 ----------
step "安裝前端相依並建置 (npm)"
if [[ -f package-lock.json ]]; then npm ci; else npm install; fi
npm run build

# ---------- 6. storage link + 權限 ----------
step "建立 storage 連結與權限"
php artisan storage:link 2>/dev/null || true
if have sudo && id www-data >/dev/null 2>&1; then
  sudo chown -R www-data:www-data storage bootstrap/cache database
  echo "  storage/bootstrap/cache/database 已給 www-data"
else
  chmod -R ug+rwX storage bootstrap/cache database || true
  warn "未設定 www-data 擁有權（無 sudo 或無 www-data）；請確保 web server 可寫 storage/"
fi

# ---------- 7. 建立管理員 ----------
step "建立中控台管理員"
ADMIN_EMAIL="${ADMIN_EMAIL:-}"
ADMIN_PASSWORD="${ADMIN_PASSWORD:-}"
if [[ -z "$ADMIN_EMAIL" ]]; then read -rp "  管理員 Email: " ADMIN_EMAIL; fi
if [[ -z "$ADMIN_PASSWORD" ]]; then read -rsp "  管理員密碼: " ADMIN_PASSWORD; echo; fi
php artisan pai:user "$ADMIN_EMAIL" "$ADMIN_PASSWORD"

# ---------- 8. 領域包健檢 ----------
step "驗證領域包"
php artisan pai:domains || warn "部分領域包載入失敗，請檢查 packs/"

# ---------- 9. production 快取 ----------
if [[ $PROD -eq 1 ]]; then
  step "建立 production 快取"
  php artisan config:cache && php artisan route:cache && php artisan view:cache
fi

# ---------- 10. nginx（選配）----------
if [[ $WITH_NGINX -eq 1 ]]; then
  step "設定 nginx"
  [[ -n "$DOMAIN" ]] || die "--with-nginx 需同時指定 --domain"
  have nginx || die "找不到 nginx"
  FPM_SOCK="$(ls /run/php/php*-fpm.sock 2>/dev/null | head -1 || echo /run/php/php-fpm.sock)"
  TMP="$(mktemp)"
  sed -e "s#__DOMAIN__#$DOMAIN#g" -e "s#__ROOT__#$APP_DIR#g" \
      -e "s#__PORT__#$PORT#g" -e "s#__FPM_SOCK__#$FPM_SOCK#g" \
      deploy/nginx.conf.template > "$TMP"
  sudo cp "$TMP" "/etc/nginx/sites-available/$DOMAIN"
  sudo ln -sfn "/etc/nginx/sites-available/$DOMAIN" "/etc/nginx/sites-enabled/$DOMAIN"
  rm -f "$TMP"
  sudo nginx -t && sudo nginx -s reload
  echo "  nginx 已服務 $DOMAIN（:$PORT → php-fpm $FPM_SOCK）"
fi

# ---------- 11. 背景服務 ----------
PHP_BIN="$(command -v php)"
if [[ $WITH_SYSTEMD -eq 1 ]]; then
  step "安裝 systemd 服務（queue worker + scheduler）"
  for unit in pai-worker pai-scheduler; do
    TMP="$(mktemp)"
    sed -e "s#__APP_DIR__#$APP_DIR#g" -e "s#__PHP__#$PHP_BIN#g" -e "s#__USER__#$RUN_USER#g" \
        "deploy/$unit.service" > "$TMP"
    sudo cp "$TMP" "/etc/systemd/system/$unit.service"
    rm -f "$TMP"
  done
  sudo systemctl daemon-reload
  sudo systemctl enable --now pai-worker pai-scheduler
  echo "  已啟用 pai-worker、pai-scheduler"
else
  warn "未啟用系統服務。請另開終端機執行下列兩者（或加 --with-systemd）："
  echo "    php artisan queue:work --timeout=900   # 處理認知運行/事件"
  echo "    php artisan schedule:work              # cron 主動觸發 + 日誌掃描"
fi

# ---------- 完成 ----------
URL="${DOMAIN:+https://$DOMAIN}"
cat <<DONE

${c_green}✔ 安裝完成！${c_reset}
  • 登入帳號：$ADMIN_EMAIL
  • 中控台：${URL:-（用 php artisan serve 或設 --with-nginx）}
  • AI 後端：確認 PAI_LLM_BASE_URL（預設本機 llama-server :10003）可連線：
        php artisan pai:llm-ping
  • 新增領域：登入後到 /packs 用自然語言生成，或編輯 packs/*.yaml
  • 本機預覽：php artisan serve --port=8123  → http://127.0.0.1:8123
DONE
