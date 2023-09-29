#!/bin/bash

######################### INCLUSION LIB ##########################
BASEDIR=$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )
wget https://raw.githubusercontent.com/NebzHB/dependance.lib/master/dependance.lib -O $BASEDIR/dependance.lib &>/dev/null
PLUGIN=$(basename "$(realpath $BASEDIR/..)")
. ${BASEDIR}/dependance.lib
##################################################################
wget https://raw.githubusercontent.com/NebzHB/nodejs_install/main/install_nodejs.sh -O $BASEDIR/install_nodejs.sh &>/dev/null

installVer='18' 	#NodeJS major version to be installed

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
. ${BASEDIR}/install_nodejs.sh ${installVer}

step 60 "Nettoyage anciens modules"
cd ${BASEDIR};
#remove old local modules
sudo rm -rf node_modules &>/dev/null
sudo rm -f package-lock.json &>/dev/null

step 70 "Installation des librairies, veuillez patienter svp"
silent sudo mkdir node_modules 
silent sudo chown -R www-data:www-data . 
tryOrStop sudo npm install --no-fund --no-package-lock --no-audit
silent sudo chown -R www-data:www-data . 

step 90 "Mise à jour class utilitaires"
silent wget https://raw.githubusercontent.com/NebzHB/hkLibs/main/homekitEnums.class.php -O $BASEDIR/../core/class/homekitEnums.class.php
silent wget https://raw.githubusercontent.com/NebzHB/hkLibs/main/homekitUtils.class.php -O $BASEDIR/../core/class/homekitUtils.class.php
silent sudo chown www-data:www-data homekit*
silent sudo chmod 775 homekit* 

post
