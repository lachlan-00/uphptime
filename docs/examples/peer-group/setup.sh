#!/bin/sh

set -e

INSTALL_DIR=$(dirname "$0")
mkdir -p "$INSTALL_DIR/docker"

if [ -d "$INSTALL_DIR/docker/uphptime" ]; then
    echo "git pull uphptime"
    cd $INSTALL_DIR/docker/uphptime && git pull
    cd $INSTALL_DIR
else
    echo "git clone uphptime to $INSTALL_DIR/docker/uphptime"
    cd $INSTALL_DIR
    git clone https://github.com/lachlan-00/uphptime.git "$INSTALL_DIR/docker/uphptime"
fi

if [ ! -f "$INSTALL_DIR/docker/uphptime/docker/Dockerfile" ]; then
    echo "Invalid build checkout. docker/uphptime; missing $INSTALL_DIR/docker/uphptime/docker/Dockerfile" >&2
    exit 1
fi
