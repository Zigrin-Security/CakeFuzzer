#!/usr/bin/env bash

sudo apt -qq update

commands=(patch sed python3-pip)
python_ver=$(whereis python3 | grep -Eo 'python3\.[0-9]+ ' | sort -u | tail -n 1|xargs)
if [ -z "$python_ver" ]; then
    # Get latest version of python 3 available in the repository
    python_ver=$(apt search -qq '^python3\.[0-9]+$' 2>/dev/null| grep -Eo 'python3\.[0-9]+' | grep -Fv python3.11 | tail -n 1)
    commands+=($python_ver)
fi
commands+=($python_ver-venv)

for command in "${commands[@]}"; do
    if [ ! -n "$(command -v $command)" ]; then
        if [ -n "$(command -v apt)" ]; then
            sudo apt install -y -qq $command
        else
            sudo yum install -y $command
        fi
    fi
done

# Note: If a wrong version of pip is installed
# Use below commands to install correct pip version
# if [ ! -n "$(command -v pip3.8)" ]
# then
#     wget -q https://bootstrap.pypa.io/get-pip.py -O /tmp/get-pip.py
#     sudo $python_ver /tmp/get-pip.py
#     rm /tmp/get-pip.py
# fi

cd /cake_fuzzer
sudo pip3 install -q --upgrade virtualenv
sudo virtualenv -q -p $python_ver venv
if [ ! -e venv ]; then
    $python_ver -m venv venv
fi
source venv/bin/activate

pip install -qr requirements.txt

echo "setup finished!"
