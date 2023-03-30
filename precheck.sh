#!/usr/bin/env bash


if [ ! -n "$(command -v patch)" ] || [ ! -n "$(command -v sed)" ] || [ ! -n "$(command -v python3.8)" ]
then
    if [ -n "$(command -v apt-get)" ]
    then
        sudo apt-get update
        sudo apt-get install -y patch sed python3.8
    else
        sudo yum install -y patch sed python3.8
    fi
fi
echo "patch installed!"

cd /cake_fuzzer

if [ ! -n "$(command -v pip3.8)" ]
then
    wget -q https://bootstrap.pypa.io/get-pip.py -O /tmp/get-pip.py
    sudo python3.8 /tmp/get-pip.py
    rm /tmp/get-pip.py
fi

cd /cake_fuzzer
sudo pip3.8 install -q --upgrade virtualenv
sudo virtualenv -q -p python3.8 venv
if [ ! -e venv ]; then
    python3.8 -m venv venv
fi
source venv/bin/activate
echo "venv activated!"

pip3.8 install -qr requirements.txt
echo "python dependencies installed!"

echo "setup finished!"
