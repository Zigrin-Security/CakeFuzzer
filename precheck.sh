#!/usr/bin/env bash

# REQUIREMENTS
commands=(patch sed)
python_modules=(virtualenv venv)

info()  { echo -e "\\033[1;36m[INFO]\\033[0m  \\033[36m$*\\033[0m" >&2; }
warn()  { echo -e "\\033[1;33m[WARNING]\\033[0m  \\033[33m$*\\033[0m" >&2; }
fatal() { echo -e "\\033[1;31m[FATAL]\\033[0m  \\033[31m$*\\033[0m" >&2; exit 1; }

get_installed_python_package_path () {
    local python_version=$1
    local package_name=$2
    potential_packages=(${package_name}3.$python_minor_version ${package_name}3$python_minor_version ${package_name}3 $package_name)
    paths=""
    for potential_package in "${potential_packages[@]}"; do
        paths+=$'\n'"$(whereis -b $potential_package | grep -Eo "[^ ]+/$potential_package" | sort -ur)"
    done
    path=""
    while IFS= read -r path; do
        if [ -f "$path" ]; then
            path="$path";
            break;
        fi
    done <<< "$paths"
    echo $path
}

get_available_python_package_name () {
    local python_bin_name=$1
    local python_minor_version=$2
    local package_name=$3
    # Get latest version of python 3 available in the repository
    potential_packages=($python_bin_name-$package_name python3$python_minor_version-$package_name python3-$package_name)
    for potential_package in "${potential_packages[@]}"; do
        packages=$($pkg_mgr search $potential_package 2>/dev/null)
        if [ ! -z "$packages" ]; then
            bin_name=$potential_package
            echo $bin_name
            break
        fi
    done
}

if [[ $(id -u) -ne 0 ]]; then fatal "Please run as root"; fi

if [ -n "$(command -v apt)" ]; then
  pkg_mgr=apt
  apt -qq update
else
  pkg_mgr=yum
  yum -q check-update
fi

info "Detecting installed python"
python_paths="$(whereis -b python3 | grep -Eo '[^ ]+/python3\.[0-9]+' | grep -Fv python3.11 | sort -ur)"
python_path=""
while IFS= read -r path; do
    if [ -f "$path" ]; then
        python_path="$path";
        break;
    fi
done <<< "$python_paths"
python_bin_name=$(echo $python_path | grep -Eo 'python3\.[0-9]+')

# Adding to install if not exists
if [ -z "$python_bin_name" ]; then
    # Get latest version of python 3 available in the repository
    python_bin_name=$($pkg_mgr search python3 2>/dev/null| grep -Eo 'python3\.?[0-9]+' | grep -Fv python3.11 | sort -u | tail -n 1)
    commands+=($python_bin_name)
fi

info "Installing all required tools"
for command in "${commands[@]}"; do
    if [ ! -n "$(command -v $command)" ]; then
        $pkg_mgr install -y $command
    fi
done

# Detecting python version
python_version=$(echo $python_bin_name | grep -Eo '[0-9]+\.?[0-9]+')
if [ "${python_version:1:1}" = "." ]; then
    python_minor_version="${python_version:2}"
else
    python_minor_version="${python_version:1}"
fi

# Finding and installing required python modules
for module in "${python_modules[@]}"; do
    module_path=$(get_installed_python_package_path $python_version $module)
    if [ -z "$module_path" ]; then
        module_name=$(get_available_python_package_name $python_bin_name $python_minor_version $module)
        if [ ! -z "$module_name" ]; then
            $pkg_mgr install -y $module_name
        else
            warn "Python package '$module' does not exist in the repo"
        fi
    fi
done

# Note: If a wrong version of pip is installed
# Use below commands to install correct pip version
if [ ! -n "$(command -v pip3)" ]; then
    wget -q https://bootstrap.pypa.io/get-pip.py -O /tmp/get-pip.py
    $python_bin_name /tmp/get-pip.py
    rm /tmp/get-pip.py
fi

pip_name=pip$python_version

cd /cake_fuzzer
info "Preparing virtual environment"
$pip_name install -q --upgrade virtualenv
virtualenv -q -p $python_bin_name venv
if [ ! -e venv ]; then
    info "Run venv"
    $python_bin_name -m venv venv
fi
source venv/bin/activate

$pip_name install -qr requirements.txt

info "Setup finished!"
