#!/bin/sh
set -eu

certificate_dir=/etc/nginx/ssl
certificate="$certificate_dir/cert.pem"
private_key="$certificate_dir/cert.key"

case "${PROXY_CERT_IP:-}" in
    ''|*[!0-9.]*)
        echo "PROXY_CERT_IP debe ser una dirección IPv4." >&2
        exit 1
        ;;
esac

case "${PROXY_CERT_HOSTNAME:-}" in
    ''|*[!A-Za-z0-9.-]*)
        echo "PROXY_CERT_HOSTNAME solo puede contener letras, números, puntos y guiones." >&2
        exit 1
        ;;
esac

mkdir -p "$certificate_dir"

if [ ! -s "$certificate" ] || [ ! -s "$private_key" ] \
    || ! openssl x509 -in "$certificate" -noout -checkip "$PROXY_CERT_IP" >/dev/null 2>&1 \
    || ! openssl x509 -in "$certificate" -noout -checkhost "$PROXY_CERT_HOSTNAME" >/dev/null 2>&1; then
    openssl req -x509 -newkey rsa:3072 -sha256 -days 3650 -nodes \
        -keyout "$private_key.tmp" \
        -out "$certificate.tmp" \
        -subj "/CN=$PROXY_CERT_IP" \
        -addext "subjectAltName=IP:$PROXY_CERT_IP,IP:127.0.0.1,DNS:$PROXY_CERT_HOSTNAME" \
        -addext "basicConstraints=critical,CA:TRUE" \
        -addext "keyUsage=critical,digitalSignature,keyCertSign,cRLSign" \
        -addext "extendedKeyUsage=serverAuth"
    mv "$private_key.tmp" "$private_key"
    mv "$certificate.tmp" "$certificate"
    chmod 600 "$private_key"
    chmod 644 "$certificate"
fi

nginx -t
exec nginx -g 'daemon off;'
