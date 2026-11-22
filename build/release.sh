#!/bin/bash
#
# Nextcloud Music app
#
# This file is licensed under the Affero General Public License version 3 or
# later. See the COPYING file.
#
# @author Pauli Järvinen <pauli.jarvinen@gmail.com>
# @copyright Pauli Järvinen 2021 - 2026
#

# Create the base package from the files stored in git
cd ..
git archive HEAD --format=zip --prefix=music/ > music.zip

# Add the generated webpack files to the previously created package
cd ..
zip -g music/music.zip music/dist/*.js
zip -g music/music.zip music/dist/*.css
zip -g music/music.zip music/dist/*.json
zip -g music/music.zip music/dist/img/**

# Remove the front-end source files from the package as those are not needed to run the app
zip -d music/music.zip "music/css/*.css"
zip -d music/music.zip "music/css/*/"
zip -d music/music.zip "music/img/*.svg"
zip -d music/music.zip "music/img/*/*"
zip -d music/music.zip "music/js/*.js*"
zip -d music/music.zip "music/js/*/*"
zip -d music/music.zip "music/l10n/*/*"

# Add the application icon back to the zip as that is still needed by the cloud core
zip -g music/music.zip music/img/music.svg

# Remove also files related to building, testing, code analysis, and developer documentation
zip -d music/music.zip "music/build/*"
zip -d music/music.zip "music/docs/*"
zip -d music/music.zip "music/stubs/*"
zip -d music/music.zip "music/tests/*"
zip -d music/music.zip "music/composer.*"
zip -d music/music.zip "music/vendor-bin/*/*"
zip -d music/music.zip "music/vendor-bin"
zip -d music/music.zip "music/package*.json"
zip -d music/music.zip "music/phpstan*.*"
zip -d music/music.zip "music/webpack.config.js"
