#!/bin/sh
set -eu

certificate_dir=/etc/nginx/ssl
ca_certificate="$certificate_dir/ca.pem"
ca_private_key="$certificate_dir/ca.key"
certificate="$certificate_dir/cert.pem"
private_key="$certificate_dir/cert.key"
request="$certificate_dir/cert.csr"
extensions="$certificate_dir/cert-extensions.cnf"

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

if [ ! -s "$ca_certificate" ] || [ ! -s "$ca_private_key" ] \
    || ! openssl x509 -in "$ca_certificate" -noout -checkend 0 >/dev/null 2>&1; then
    openssl req -x509 -newkey rsa:3072 -sha256 -days 3650 -nodes \
        -keyout "$ca_private_key.tmp" \
        -out "$ca_certificate.tmp" \
        -subj "/CN=Whatsapp Dashboard Local CA" \
        -addext "basicConstraints=critical,CA:TRUE,pathlen:0" \
        -addext "keyUsage=critical,keyCertSign,cRLSign"
    mv "$ca_private_key.tmp" "$ca_private_key"
    mv "$ca_certificate.tmp" "$ca_certificate"
    chmod 600 "$ca_private_key"
    chmod 644 "$ca_certificate"
fi

if [ ! -s "$certificate" ] || [ ! -s "$private_key" ] \
    || ! openssl x509 -in "$certificate" -noout -checkip "$PROXY_CERT_IP" >/dev/null 2>&1 \
    || ! openssl x509 -in "$certificate" -noout -checkhost "$PROXY_CERT_HOSTNAME" >/dev/null 2>&1 \
    || ! openssl verify -CAfile "$ca_certificate" "$certificate" >/dev/null 2>&1; then
    openssl req -new -newkey rsa:2048 -sha256 -nodes \
        -keyout "$private_key.tmp" \
        -out "$request" \
        -subj "/CN=$PROXY_CERT_IP" \
        >/dev/null 2>&1
    printf 'basicConstraints=critical,CA:FALSE\nkeyUsage=critical,digitalSignature,keyEncipherment\nextendedKeyUsage=serverAuth\nsubjectAltName=IP:%s,IP:127.0.0.1,DNS:%s\n' \
        "$PROXY_CERT_IP" "$PROXY_CERT_HOSTNAME" > "$extensions"
    openssl x509 -req -in "$request" \
        -CA "$ca_certificate" \
        -CAkey "$ca_private_key" \
        -CAcreateserial \
        -out "$certificate.tmp" \
        -days 825 \
        -sha256 \
        -extfile "$extensions"
    mv "$private_key.tmp" "$private_key"
    mv "$certificate.tmp" "$certificate"
    chmod 600 "$private_key"
    chmod 644 "$certificate"
    rm -f "$request" "$extensions"
fi

nginx -t
exec nginx -g 'daemon off;'
