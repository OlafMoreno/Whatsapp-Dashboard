FROM nginx:alpine

# Instalar openssl y dos2unix para limpiar saltos de línea de Windows
RUN apk add --no-cache openssl dos2unix

COPY docker/nginx-proxy.conf /etc/nginx/nginx.conf
COPY docker/proxy-entrypoint.sh /usr/local/bin/proxy-entrypoint.sh

# Normalizar saltos de línea (Windows -> Linux) y dar permisos de ejecución
RUN dos2unix /usr/local/bin/proxy-entrypoint.sh && \
    chmod +x /usr/local/bin/proxy-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/proxy-entrypoint.sh"]