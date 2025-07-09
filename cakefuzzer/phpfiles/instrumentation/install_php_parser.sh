#!/bin/bash

# Download the PHP-Parser library
if [ ! -f php-parser.zip ]; then
    curl -Lso php-parser.zip https://github.com/nikic/PHP-Parser/archive/refs/heads/master.zip
fi

# Extract the downloaded zip archive
unzip php-parser.zip

# Rename the extracted folder
mv PHP-Parser-master php-parser

# # Remove the downloaded zip archive
# Do not remove it for now...
# rm php-parser.zip

echo "PHP-Parser library downloaded and extracted to 'php-parser' folder."
