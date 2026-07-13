FROM php:8.0-cli
COPY . /app
WORKDIR /app
RUN echo "BUILD TEST OK"
EXPOSE 10000
CMD php -S 0.0.0.0:10000 -t public