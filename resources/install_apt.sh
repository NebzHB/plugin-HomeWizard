#!/bin/bash

######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
TIMED=1
. ${BASEDIR}/dependance.lib
##################################################################
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/install_nodejs.sh -O $BASEDIR/install_nodejs.sh &>/dev/null

pre
step 0 "Vérification des droits"
DIRECTORY="/var/www"
if [ ! -d "$DIRECTORY" ]; then
	silent sudo mkdir $DIRECTORY
fi
silent sudo chown -R www-data $(realpath $BASEDIR/..)

step 5 "Mise à jour APT et installation des packages nécessaires"
tryOrStop sudo apt-get update
try sudo DEBIAN_FRONTEND=noninteractive apt-get install -y libudev-dev

#install nodejs, steps 10->50
. ${BASEDIR}/install_nodejs.sh --firstSubStep 10 --lastSubStep 50

step 60 "Nettoyage anciens modules"
cd ${BASEDIR};
#remove old local modules
sudo rm -rf node_modules &>/dev/null
sudo rm -f package-lock.json &>/dev/null

step 80 "Installation des librairies, veuillez patienter svp"
silent sudo mkdir node_modules 
silent sudo chown -R www-data:www-data . 
tryOrStop sudo npm install --no-fund --no-package-lock --no-audit
silent wget https://raw.githubusercontent.com/NebzHB/nodejsToJeedom/main/jeedom.js -O $BASEDIR/utils/jeedom.js
silent sudo chown -R www-data:www-data . 

post
