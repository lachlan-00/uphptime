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

if [ -d "$INSTALL_DIR/docker/uphptime-dashboard" ]; then
    echo "git pull uphptime-dashboard"
    cd $INSTALL_DIR/docker/uphptime-dashboard && git pull
    cd $INSTALL_DIR
else
    echo "git clone uphptime-dashboard to $INSTALL_DIR/docker/uphptime-dashboard"
    cd $INSTALL_DIR
    git clone https://github.com/lachlan-00/uphptime-dashboard.git "$INSTALL_DIR/docker/uphptime-dashboard"
fi

if [ ! -f "$INSTALL_DIR/docker/uphptime/docker/Dockerfile" ]; then
    echo "Invalid build checkout. docker/uphptime; missing $INSTALL_DIR/docker/uphptime/docker/Dockerfile" >&2
    exit 1
fi
if [ ! -f "$INSTALL_DIR/docker/uphptime-dashboard/Dockerfile" ]; then
    echo "Invalid build checkout. docker/uphptime-dashboard; missing $INSTALL_DIR/docker/uphptime-dashboard/Dockerfile" >&2
    exit 1
fi
