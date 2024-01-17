FROM nginx:latest

COPY examples/docker/nginx/nginx.conf  /etc/nginx/
COPY examples/docker/nginx/default.conf /etc/nginx/conf.d/
RUN echo "upstream php-upstream { server 127.0.0.1:9000; }" > /etc/nginx/conf.d/upstream.conf

COPY examples/docker/nginx/nginxlogrotate /etc/logrotate.d/nginx

EXPOSE 8000
EXPOSE 443
