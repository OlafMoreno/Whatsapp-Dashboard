FROM nginx:alpine

RUN apk add --no-cache openssl

COPY docker/nginx-proxy.conf /etc/nginx/nginx.conf
COPY docker/proxy-entrypoint.sh /usr/local/bin/proxy-entrypoint.sh

RUN chmod 755 /usr/local/bin/proxy-entrypoint.sh

CMD ["/usr/local/bin/proxy-entrypoint.sh"]
