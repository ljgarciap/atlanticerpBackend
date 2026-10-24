#!/usr/bin/env bash
# make-env.sh — genera el .env del simulacro con secretos NUEVOS y aleatorios (nada heredado de ningún entorno).
set -euo pipefail
cd "$(dirname "$0")/../.."
cp infra/drill/env.drill.example .env
rnd() { openssl rand -base64 "$1" | tr -d '\n/+=' | cut -c1-"$2"; }
sed -i "s|^APP_KEY=.*|APP_KEY=base64:$(openssl rand -base64 32)|" .env
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$(rnd 24 24)|" .env
sed -i "s|^JWT_SECRET=.*|JWT_SECRET=$(rnd 48 48)|" .env
echo ".env del simulacro generado (secretos nuevos). No contiene credenciales de AWS ni de Anthropic."
