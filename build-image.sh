#!/bin/bash

set -e

TAG="harbor.cs.dm.unipi.it/rooms/rooms"

if [ -x ./composer.phar ]; then
  php composer.phar install
else
  composer install
fi

sudo docker build -t $TAG .

echo -n "Should I push the image to Docker Hub? [yn]: "
read ans
if [ "$ans" = "y" ]; then
  sudo docker push $TAG
fi


